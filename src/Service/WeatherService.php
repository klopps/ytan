<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ApiException;

/**
 * Fetches an hourly, 7-day weather + marine (wave/swell) forecast for a
 * coordinate from Open-Meteo (free, no API key - see open-meteo.com),
 * zipping both series together by timestamp and caching the combined
 * result on disk with a real TTL. Also fetches one sunrise/sunset pair
 * per calendar day (Open-Meteo's `daily` block, same request as the
 * hourly general forecast) for the week-chart's sunrise/sunset markers.
 * Backs GET /api/v1/weather (shown on-demand via the map's right-click/
 * long-press "Weather data for this location" context menu item - see
 * public/js/weather.js), which renders it as a horizontally scrollable
 * hourly timeline.
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
    private const FORECAST_URL = 'https://api.open-meteo.com/v1/forecast';
    private const MARINE_URL = 'https://marine-api.open-meteo.com/v1/marine';

    private const GENERAL_HOURLY_VARS = 'temperature_2m,apparent_temperature,wind_speed_10m,wind_gusts_10m,wind_direction_10m,precipitation,weather_code';
    private const GENERAL_DAILY_VARS = 'sunrise,sunset';
    private const MARINE_HOURLY_VARS = 'wave_height,wave_direction,wave_period,swell_wave_height,swell_wave_direction,swell_wave_period,wind_wave_height,wind_wave_direction,wind_wave_period,sea_surface_temperature,sea_level_height_msl';

    // Open-Meteo's marine model caps hourly forecasts at 7 days - matching
    // that for the general forecast too keeps both series the same length
    // (they're zipped together by timestamp) and is plenty for planning a
    // multi-day kayak tour.
    private const FORECAST_DAYS = 7;

    // ~1.1km grid at these latitudes - enough cache-hit rate across small
    // pans, fine enough that wave/wind conditions near a coastline aren't
    // blurred across a whole bay.
    private const COORD_PRECISION = 2;

    // Open-Meteo's underlying models don't update sub-hourly; this stays
    // well under the 10k-calls/day free tier even with many distinct
    // lookups, while staying fresh enough for a touring-planning session.
    private const CACHE_TTL_SECONDS = 1800;

    public function __construct(
        private readonly string $cacheDir,
        private readonly JsonHttpClient $httpClient,
    ) {
    }

    /**
     * @return array{
     *     coordinates: array{lat: float, lng: float},
     *     fetched_at: string,
     *     has_marine_data: bool,
     *     utc_offset_seconds: int,
     *     hourly: list<array<string, mixed>>,
     *     daily: list<array{date: string, sunrise: ?string, sunset: ?string}>
     * }
     */
    public function getForecast(float $lat, float $lng): array
    {
        [$roundedLat, $roundedLng] = $this->roundCoordinates($lat, $lng);
        $file = $this->cacheFilePath($roundedLat, $roundedLng);

        $cached = $this->readCache($file);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->fetchAndCombine($roundedLat, $roundedLng);
        $this->writeCache($file, $data);

        return $data;
    }

    /**
     * @return array{coordinates: array{lat: float, lng: float}, fetched_at: string, has_marine_data: bool, utc_offset_seconds: int, hourly: list<array<string, mixed>>, daily: list<array{date: string, sunrise: ?string, sunset: ?string}>}
     */
    private function fetchAndCombine(float $lat, float $lng): array
    {
        $general = $this->fetchGeneralHourly($lat, $lng);
        $marine = $this->fetchMarineHourly($lat, $lng);

        $hourly = [];
        $hasMarineData = false;

        foreach ($general['hourly'] as $time => $entry) {
            $marineEntry = $marine[$time] ?? null;
            if ($marineEntry !== null && $marineEntry['wave_height'] !== null) {
                $hasMarineData = true;
            }

            $hourly[] = array_merge(['time' => $time], $entry, $marineEntry ?? $this->emptyMarineEntry());
        }

        return [
            'coordinates' => ['lat' => $lat, 'lng' => $lng],
            'fetched_at' => gmdate('c'),
            'has_marine_data' => $hasMarineData,
            // Open-Meteo's `timezone=auto` resolves the queried coordinate's
            // own IANA zone and returns every hourly/daily timestamp as a
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
            'utc_offset_seconds' => $general['utc_offset_seconds'],
            'hourly' => $hourly,
            'daily' => $general['daily'],
        ];
    }

    /**
     * @return array{hourly: array<string, array<string, mixed>>, daily: list<array{date: string, sunrise: ?string, sunset: ?string}>, utc_offset_seconds: int}
     *         hourly keyed by ISO time string
     */
    private function fetchGeneralHourly(float $lat, float $lng): array
    {
        $response = $this->httpClient->getJson(self::FORECAST_URL, [
            'latitude' => $lat,
            'longitude' => $lng,
            'hourly' => self::GENERAL_HOURLY_VARS,
            'daily' => self::GENERAL_DAILY_VARS,
            'forecast_days' => self::FORECAST_DAYS,
            'timezone' => 'auto',
        ]);

        $hourly = $response['hourly'] ?? null;
        if (!is_array($hourly) || !isset($hourly['time']) || !is_array($hourly['time'])) {
            throw new ApiException('Weather data is currently unavailable.', 503, 'weather.unavailable');
        }

        $result = [];
        foreach ($hourly['time'] as $index => $time) {
            $result[$time] = [
                'temperature' => $hourly['temperature_2m'][$index] ?? null,
                'feels_like' => $hourly['apparent_temperature'][$index] ?? null,
                'wind_speed' => $hourly['wind_speed_10m'][$index] ?? null,
                'wind_gusts' => $hourly['wind_gusts_10m'][$index] ?? null,
                'wind_direction' => $hourly['wind_direction_10m'][$index] ?? null,
                'precipitation' => $hourly['precipitation'][$index] ?? null,
                'weather_code' => $hourly['weather_code'][$index] ?? null,
            ];
        }

        $daily = $response['daily'] ?? null;
        $dailyResult = [];
        if (is_array($daily) && isset($daily['time']) && is_array($daily['time'])) {
            foreach ($daily['time'] as $index => $date) {
                $dailyResult[] = [
                    'date' => $date,
                    'sunrise' => $daily['sunrise'][$index] ?? null,
                    'sunset' => $daily['sunset'][$index] ?? null,
                ];
            }
        }

        return [
            'hourly' => $result,
            'daily' => $dailyResult,
            'utc_offset_seconds' => (int) ($response['utc_offset_seconds'] ?? 0),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>|null keyed by ISO time
     *         string, or null when the request itself failed (network
     *         error, non-200) - distinct from a successful response whose
     *         values are simply all null for an inland point, which
     *         returns a normal keyed array here (each entry's
     *         wave_height just happens to be null).
     */
    private function fetchMarineHourly(float $lat, float $lng): ?array
    {
        $response = $this->httpClient->getJson(self::MARINE_URL, [
            'latitude' => $lat,
            'longitude' => $lng,
            'hourly' => self::MARINE_HOURLY_VARS,
            'forecast_days' => self::FORECAST_DAYS,
            'timezone' => 'auto',
        ]);

        $hourly = $response['hourly'] ?? null;
        if (!is_array($hourly) || !isset($hourly['time']) || !is_array($hourly['time'])) {
            return null;
        }

        $result = [];
        foreach ($hourly['time'] as $index => $time) {
            $result[$time] = [
                'wave_height' => $hourly['wave_height'][$index] ?? null,
                'wave_direction' => $hourly['wave_direction'][$index] ?? null,
                'wave_period' => $hourly['wave_period'][$index] ?? null,
                'swell_wave_height' => $hourly['swell_wave_height'][$index] ?? null,
                'swell_wave_direction' => $hourly['swell_wave_direction'][$index] ?? null,
                'swell_wave_period' => $hourly['swell_wave_period'][$index] ?? null,
                'wind_wave_height' => $hourly['wind_wave_height'][$index] ?? null,
                'wind_wave_direction' => $hourly['wind_wave_direction'][$index] ?? null,
                'wind_wave_period' => $hourly['wind_wave_period'][$index] ?? null,
                'sea_surface_temperature' => $hourly['sea_surface_temperature'][$index] ?? null,
                // Astronomical/oceanographic sea level relative to mean sea
                // level - i.e. the actual tide, not wave chop. Confirmed
                // live: a real tidal North Sea point (Cuxhaven) returns a
                // clean ~12.4h semi-diurnal curve swinging +-2m; the Baltic
                // reference point this feature was built against shows only
                // a small, non-tidal-shaped sea-level wobble (the Baltic is
                // nearly tideless) - both are real, non-null data, just very
                // different amplitudes depending on the coast.
                'tide_height' => $hourly['sea_level_height_msl'][$index] ?? null,
            ];
        }

        return $result;
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
     * @return array{0: float, 1: float}
     */
    private function roundCoordinates(float $lat, float $lng): array
    {
        return [round($lat, self::COORD_PRECISION), round($lng, self::COORD_PRECISION)];
    }

    private function cacheFilePath(float $lat, float $lng): string
    {
        return $this->cacheDir . '/weather_' . $lat . '_' . $lng . '.json';
    }

    /**
     * @return array{coordinates: array{lat: float, lng: float}, fetched_at: string, has_marine_data: bool, hourly: list<array<string, mixed>>}|null
     *         null = cache miss (file missing, corrupt, or expired)
     */
    private function readCache(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || !isset($decoded['fetched_at'], $decoded['data'])) {
            return null;
        }

        if (time() - (int) $decoded['fetched_at'] > self::CACHE_TTL_SECONDS) {
            return null;
        }

        return $decoded['data'];
    }

    /**
     * @param array{coordinates: array{lat: float, lng: float}, fetched_at: string, has_marine_data: bool, hourly: list<array<string, mixed>>} $data
     */
    private function writeCache(string $file, array $data): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }

        $payload = json_encode(['fetched_at' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE);
        file_put_contents($file, $payload);
    }
}
