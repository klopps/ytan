<?php

declare(strict_types=1);

namespace Ytan\Service;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Ytan\Domain\Weather\WeatherForecastRepositoryInterface;
use Ytan\Exception\ApiException;
use Ytan\Service\Weather\OpenMeteoWeatherProvider;
use Ytan\Service\Weather\SmhiWeatherProvider;
use Ytan\Service\Weather\WeatherProviderResult;
use Ytan\Service\Weather\WeatherRegion;
use Ytan\Service\Weather\WeatherRegionResolver;

/**
 * Orchestrates an hourly, 7-day weather + marine (wave/swell/tide) forecast
 * for a coordinate, caching the combined result in the weather_forecast DB
 * table (see database/migrations/022_create_weather_forecast.sql) with a
 * real TTL. Backs GET /api/v1/weather (shown on-demand via the map's
 * right-click/long-press "Weather data for this location" context menu
 * item - see public/js/weather.js), which renders it as a horizontally
 * scrollable hourly timeline.
 *
 * Was a single-provider (Open-Meteo only) class with its own flat-file
 * cache until 2026-09-18, when todo.md's "Wetterdaten" item asked for
 * region-specific data sources (only SMHI/Sweden+international Baltic
 * waters is wired up so far - see WeatherRegionResolver's own doc comment
 * for the rest) plus durably storing which source/model/generation-time
 * served each forecast - neither fit the old per-coordinate JSON file
 * cache well, hence the move to a real table. This class's own job
 * narrowed to orchestration: WeatherRegionResolver picks a
 * WeatherProviderInterface for the general (temperature/wind/precipitation/
 * weather-code) forecast, OpenMeteoWeatherProvider is still used directly
 * (regardless of which general provider was chosen) for marine/tide data
 * and sunrise/sunset - see fetchAndCombine() below for why both stay
 * provider-independent.
 *
 * A marine fetch failure (network error, non-200) is treated exactly the
 * same as Open-Meteo's own "every hour null" response for inland points -
 * both just leave every hour's marine fields null, never an exception.
 * Confirmed live: an inland coordinate (Munich) gets HTTP 200 from the
 * marine endpoint with every hourly wave_height value null, never an
 * error status - so "no marine data here" has to be detected from the
 * response content, not from a failed request. Only the general/forecast
 * fetch throws, since every coordinate on Earth has ordinary weather.
 */
final class WeatherService
{
    // ~1.1km grid at these latitudes - enough cache-hit rate across small
    // pans, fine enough that wave/wind conditions near a coastline aren't
    // blurred across a whole bay.
    private const COORD_PRECISION = 2;

    // Open-Meteo's/SMHI's underlying models don't update sub-hourly; this
    // stays well under Open-Meteo's 10k-calls/day free tier even with many
    // distinct lookups, while staying fresh enough for a touring-planning
    // session.
    private const CACHE_TTL_SECONDS = 1800;

    public function __construct(
        private readonly WeatherForecastRepositoryInterface $forecasts,
        private readonly WeatherRegionResolver $regionResolver,
        private readonly OpenMeteoWeatherProvider $openMeteoProvider,
        private readonly SmhiWeatherProvider $smhiProvider,
    ) {
    }

    /**
     * @return array{
     *     coordinates: array{lat: float, lng: float},
     *     fetched_at: string,
     *     source: string,
     *     model: ?string,
     *     generated_at: ?string,
     *     has_marine_data: bool,
     *     utc_offset_seconds: int,
     *     hourly: list<array<string, mixed>>,
     *     daily: list<array{date: string, sunrise: ?string, sunset: ?string}>
     * }
     */
    public function getForecast(float $lat, float $lng): array
    {
        [$roundedLat, $roundedLng] = $this->roundCoordinates($lat, $lng);

        $cached = $this->forecasts->findFresh($roundedLat, $roundedLng, self::CACHE_TTL_SECONDS);
        if ($cached !== null) {
            return $cached['payload'];
        }

        $region = $this->regionResolver->resolve($roundedLat, $roundedLng);
        $data = $this->fetchAndCombine($roundedLat, $roundedLng, $region);

        $this->forecasts->upsert(
            $roundedLat,
            $roundedLng,
            $region->regionCode,
            $data['source'],
            $data['model'],
            $this->toMysqlDatetime($data['generated_at']),
            gmdate('Y-m-d H:i:s'),
            $data,
        );

        return $data;
    }

    private function fetchAndCombine(float $lat, float $lng, WeatherRegion $region): array
    {
        if ($region->provider === WeatherRegion::PROVIDER_SMHI) {
            $general = $this->smhiProvider->fetchGeneralForecast($lat, $lng);
        } elseif ($region->openMeteoModel !== null) {
            // One of the five countries WeatherRegionResolver maps onto a
            // specific Open-Meteo model (DWD/DMI/MET Norway/Météo-France/
            // KNMI) - still Open-Meteo's own API, just not its default
            // best-match model.
            $general = $this->openMeteoProvider->fetchGeneralForecastForModel(
                $lat,
                $lng,
                $region->openMeteoModel,
                $region->sourceLabel ?? 'Open-Meteo',
            );
        } else {
            $general = $this->openMeteoProvider->fetchGeneralForecast($lat, $lng);
        }
        $general = $this->fillHourlyGaps($general, $region, $lat, $lng);

        // Marine (wave/swell/tide) has no SMHI equivalent in this app, and
        // sunrise/sunset is astronomical, not weather-model-specific - both
        // always come from Open-Meteo regardless of which general provider
        // served the actual weather. Whenever OpenMeteoWeatherProvider
        // itself served the general forecast - its own best-match default
        // OR one of the five countries routed through its `models=`
        // parameter (see fetchAndCombine() above) - $general->daily already
        // carries it (that same request asks for hourly+daily together);
        // fetchDailySunTimes() only runs as a separate call for the one
        // region that isn't OpenMeteoWeatherProvider at all (SMHI), which
        // has no daily block of its own to reuse.
        $marine = $this->openMeteoProvider->fetchMarineHourly($lat, $lng);
        $daily = $region->provider === WeatherRegion::PROVIDER_SMHI
            ? $this->openMeteoProvider->fetchDailySunTimes($lat, $lng)['daily']
            : $general->daily;

        $hourly = [];
        $hasMarineData = false;

        foreach ($general->hourly as $time => $entry) {
            $marineEntry = $marine[$time] ?? null;
            if ($marineEntry !== null && $marineEntry['wave_height'] !== null) {
                $hasMarineData = true;
            }

            $hourly[] = array_merge(['time' => $time], $entry, $marineEntry ?? $this->emptyMarineEntry());
        }

        return [
            'coordinates' => ['lat' => $lat, 'lng' => $lng],
            'fetched_at' => gmdate('c'),
            'source' => $general->source,
            'model' => $general->model,
            'generated_at' => $general->generatedAt,
            'has_marine_data' => $hasMarineData,
            // Open-Meteo's `timezone=auto` (and SmhiWeatherProvider's own
            // matching conversion) resolves the queried coordinate's own
            // IANA zone and returns every hourly/daily timestamp as a
            // naive local-time string for THAT location (no UTC offset in
            // the string itself) - this is what we want for display (a
            // Danish beach's forecast should show Danish local hours
            // regardless of where the user's own device happens to be), but
            // it means the frontend can't just `new Date(hour.time)` and
            // compare against `new Date()` for a "now" indicator: the
            // browser parses a naive string using the DEVICE's own
            // timezone, which only coincidentally matches the forecast
            // location's timezone. Passing this through lets weather.js
            // shift its own `Date.now()` reading by this many seconds
            // before comparing, landing in the same "pretend it's UTC"
            // reference frame the frontend parses hour.time strings into
            // (see weather.js's own comment on that trick) - independent of
            // the device's own timezone setting entirely.
            'utc_offset_seconds' => $general->utcOffsetSeconds,
            'hourly' => $hourly,
            'daily' => $daily,
        ];
    }

    /**
     * todo.md's "Fehlende Daten": a general-forecast provider that doesn't
     * cover every hour of the 7-day window (SMHI's real point forecast is
     * hourly for ~2.5 days, then a mix of 3h/6h/12h steps out past day 10 -
     * confirmed live, not hypothetical). A no-op when $general is already
     * Open-Meteo's own result - it's this method's own filler/fallback, so
     * by construction it has nothing to fill against itself.
     *
     * Walks an independently synthesized 168-key hourly grid (see
     * buildExpectedHourlyKeys() - deliberately not just $filler's own key
     * set, so a real "no data" hour is still possible, correctly time-
     * slotted, even if the Open-Meteo filler request itself fails). Per
     * missing hour: interpolate against $general's OWN nearest neighbors if
     * they're <=4h apart (a genuine short gap in that provider's own data);
     * otherwise fall back to Open-Meteo's value for that exact hour (covers
     * both "gap too wide to interpolate" and "provider's coverage ends
     * before day 7" with one mechanism, since Open-Meteo essentially always
     * has dense hourly data for any coordinate). Only when even that
     * Open-Meteo fetch fails does an hour end up with every field null -
     * the frontend's actual "keine Daten" case (weather.js's
     * buildWeatherHourColumn(), gated on `hour.temperature === null`, the
     * same idiom already used for a missing marine reading).
     */
    private function fillHourlyGaps(WeatherProviderResult $general, WeatherRegion $region, float $lat, float $lng): WeatherProviderResult
    {
        if ($region->provider !== WeatherRegion::PROVIDER_SMHI) {
            return $general;
        }

        try {
            $filler = $this->openMeteoProvider->fetchGeneralForecast($lat, $lng);
        } catch (ApiException) {
            $filler = null;
        }

        $generalKeys = array_keys($general->hourly);
        sort($generalKeys);
        // Synthesized independently (168 hourly steps from the primary
        // provider's own first hour's local calendar day) rather than
        // reusing $filler's own key set - so a "no data" hour is still
        // possible (and correctly time-slotted) even when the Open-Meteo
        // filler request itself fails, instead of silently shrinking back
        // to whatever sparse hours $general happened to have.
        $referenceKeys = $this->buildExpectedHourlyKeys($generalKeys[0]);

        $merged = [];
        foreach ($referenceKeys as $key) {
            if (isset($general->hourly[$key])) {
                $merged[$key] = $general->hourly[$key];
                continue;
            }

            $before = $this->findNearestKey($generalKeys, $key, before: true);
            $after = $this->findNearestKey($generalKeys, $key, before: false);

            if ($before !== null && $after !== null && $this->hoursBetween($before, $after) <= 4.0) {
                $fraction = $this->hoursBetween($before, $key) / $this->hoursBetween($before, $after);
                $merged[$key] = $this->interpolateHourlyEntry($general->hourly[$before], $general->hourly[$after], $fraction);
                continue;
            }

            $merged[$key] = $filler?->hourly[$key] ?? $this->emptyGeneralEntry();
        }

        return new WeatherProviderResult(
            hourly: $merged,
            utcOffsetSeconds: $general->utcOffsetSeconds,
            source: $general->source,
            model: $general->model,
            generatedAt: $general->generatedAt,
            daily: $general->daily,
        );
    }

    /**
     * @return list<string> 7*24 hourly local-time keys ("Y-m-d\TH:i"),
     *         starting at $firstKnownKey's own calendar day's midnight -
     *         matches Open-Meteo's own forecast_days=7 window without
     *         depending on an Open-Meteo response actually being available.
     */
    private function buildExpectedHourlyKeys(string $firstKnownKey): array
    {
        $day = (new DateTimeImmutable($firstKnownKey, new DateTimeZone('UTC')))->setTime(0, 0);

        $keys = [];
        for ($hour = 0; $hour < 7 * 24; $hour++) {
            $keys[] = $day->modify("+{$hour} hours")->format('Y-m-d\TH:i');
        }

        return $keys;
    }

    /**
     * @param list<string> $sortedKeys
     */
    private function findNearestKey(array $sortedKeys, string $key, bool $before): ?string
    {
        if ($before) {
            $result = null;
            foreach ($sortedKeys as $candidate) {
                if ($candidate >= $key) {
                    break;
                }
                $result = $candidate;
            }

            return $result;
        }

        foreach ($sortedKeys as $candidate) {
            if ($candidate > $key) {
                return $candidate;
            }
        }

        return null;
    }

    private function hoursBetween(string $keyA, string $keyB): float
    {
        $utc = new DateTimeZone('UTC');

        return abs((new DateTimeImmutable($keyB, $utc))->getTimestamp() - (new DateTimeImmutable($keyA, $utc))->getTimestamp()) / 3600;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    private function interpolateHourlyEntry(array $before, array $after, float $fraction): array
    {
        return [
            'temperature' => $this->lerp($before['temperature'], $after['temperature'], $fraction),
            'feels_like' => $this->lerp($before['feels_like'], $after['feels_like'], $fraction),
            'wind_speed' => $this->lerp($before['wind_speed'], $after['wind_speed'], $fraction),
            'wind_gusts' => $this->lerp($before['wind_gusts'], $after['wind_gusts'], $fraction),
            'wind_direction' => $this->lerpAngleDegrees($before['wind_direction'], $after['wind_direction'], $fraction),
            'precipitation' => $this->lerp($before['precipitation'], $after['precipitation'], $fraction),
            // Categorical, not numeric - averaging two WMO codes would
            // produce a meaningless third code. Nearest neighbor instead.
            'weather_code' => $fraction < 0.5 ? $before['weather_code'] : $after['weather_code'],
        ];
    }

    private function lerp(int|float|null $a, int|float|null $b, float $fraction): ?float
    {
        if ($a === null || $b === null) {
            return null;
        }

        return $a + ($b - $a) * $fraction;
    }

    /**
     * Shortest-arc interpolation for a compass bearing (0-359°) - a naive
     * lerp() between e.g. 350° and 10° would sweep the WRONG way around
     * through 180° instead of the 20° short way through 0°/360°.
     */
    private function lerpAngleDegrees(int|float|null $a, int|float|null $b, float $fraction): ?float
    {
        if ($a === null || $b === null) {
            return null;
        }

        $diff = fmod($b - $a, 360);
        if ($diff < -180) {
            $diff += 360;
        } elseif ($diff > 180) {
            $diff -= 360;
        }

        return round(fmod($a + $diff * $fraction + 360, 360));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyGeneralEntry(): array
    {
        return [
            'temperature' => null, 'feels_like' => null, 'wind_speed' => null,
            'wind_gusts' => null, 'wind_direction' => null, 'precipitation' => null,
            'weather_code' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMarineEntry(): array
    {
        return [
            'wave_height' => null, 'wave_direction' => null, 'wave_period' => null,
            'swell_wave_height' => null, 'swell_wave_direction' => null, 'swell_wave_period' => null,
            'wind_wave_height' => null, 'wind_wave_direction' => null, 'wind_wave_period' => null,
            'sea_surface_temperature' => null, 'tide_height' => null,
        ];
    }

    /**
     * The weather_forecast.generated_at column is a plain SQL DATETIME
     * (no timezone-aware type) - a provider's own generatedAt is always a
     * real UTC instant (SmhiWeatherProvider's `referenceTime`, ISO 8601
     * with a trailing "Z"; Open-Meteo doesn't populate this field at all
     * yet, see OpenMeteoWeatherProvider's own doc comment), which MySQL's
     * DATETIME parser rejects outright rather than just ignoring the "Z" -
     * confirmed via a real PDOException, not assumed. Reformatted to plain
     * UTC "Y-m-d H:i:s" here rather than changing the column type, so
     * every provider this app adds only ever needs to hand back an ISO
     * string, not a MySQL-specific one.
     */
    private function toMysqlDatetime(?string $isoUtc): ?string
    {
        if ($isoUtc === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($isoUtc))->format('Y-m-d H:i:s');
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function roundCoordinates(float $lat, float $lng): array
    {
        return [round($lat, self::COORD_PRECISION), round($lng, self::COORD_PRECISION)];
    }
}
