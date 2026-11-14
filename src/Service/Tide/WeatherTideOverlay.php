<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * The weather timeline's tide curve from a national tide source instead of
 * Open-Meteo (decided 2026-11-14): official predictions at real gauges are
 * more accurate than the coarse sea-level model, and in estuaries and
 * harbours Open-Meteo has no value at all (Hamburg, Bremen).
 *
 * Applied per request AFTER WeatherService's cache (weather_forecast table),
 * never stored there - the UKHO Discovery tier forbids caching, and the
 * other sources keep their own TideCache. A gauge within $maxStationMeters
 * (the Routencheck rule tidal_station_km) replaces every hourly tide_height;
 * without one the payload stays as it is (Open-Meteo). So does a gauge whose
 * mean range is below $minRangeMeters (tidal_min_range_m) where Open-Meteo
 * has a curve: in the Baltic that curve carries the wind set-up, the gauge's
 * astronomical tide is nearly flat.
 *
 * The sources give high/low water only, so the hours between are filled
 * with a half cosine from one event to the next (the usual tide-table
 * interpolation). Heights are relative to the mean of the events' heights:
 * the sources use different datums (gauge zero, NAP, chart datum), the curve
 * shows range and times, not an absolute water level. An event without a
 * height (some BSH gauges) gets +/- half the mean range.
 */
final class WeatherTideOverlay
{
    public function __construct(private readonly TideService $national)
    {
    }

    /**
     * @param array<string, mixed> $payload WeatherService::getForecast()
     * @return array<string, mixed>
     */
    public function apply(array $payload, float $lat, float $lng, int $maxStationMeters, float $minRangeMeters = 0.0): array
    {
        $offset = (int) ($payload['utc_offset_seconds'] ?? 0);
        $times = [];
        foreach ((array) ($payload['hourly'] ?? []) as $i => $row) {
            $time = is_array($row) && isset($row['time'])
                ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', substr((string) $row['time'], 0, 16), new \DateTimeZone('UTC'))
                : false;
            if ($time !== false) {
                $times[$i] = $time->getTimestamp() - $offset;
            }
        }
        if (count($times) < 2) {
            return $payload;
        }
        $tide = $this->national->tide($lat, $lng, null, min($times), max($times), $maxStationMeters);
        $levels = $tide === null ? null : self::levels($tide['events'], $tide['mean_range_m']);
        if ($levels === null) {
            return $payload;
        }
        // In a (nearly) tideless sea the gauge's astronomical tide is almost flat
        // (Warnemünde +/- 0.07 m), while Open-Meteo's sea level also shows the wind
        // set-up (+/- 0.3 m) - keep Open-Meteo there, unless it has no curve at all.
        $openMeteoHasCurve = array_filter(array_column((array) $payload['hourly'], 'tide_height'), static fn ($h) => $h !== null) !== [];
        $range = $tide['mean_range_m'] ?? GaugeTideSource::meanRange($tide['events']);
        if ($openMeteoHasCurve && $range !== null && $range < $minRangeMeters) {
            return $payload;
        }
        foreach ($times as $i => $time) {
            $payload['hourly'][$i]['tide_height'] = self::heightAt($levels, $time);
        }
        $payload['has_marine_data'] = true;
        $payload['tide_source'] = [
            'station' => $tide['station'],
            'source' => $tide['source'],
            'attribution' => $tide['attribution'],
            'distance_m' => $tide['distance_m'],
        ];

        return $payload;
    }

    /**
     * Events with heights relative to their mean, null = not usable (fewer
     * than two events, or no heights and no range).
     *
     * @param list<array{time: int, type: string, height_m: ?float}> $events
     * @return list<array{0: int, 1: float}>|null [time, metres]
     */
    public static function levels(array $events, ?float $meanRange): ?array
    {
        if (count($events) < 2) {
            return null;
        }
        $heights = array_values(array_filter(array_column($events, 'height_m'), static fn ($h) => $h !== null));
        $mean = $heights === [] ? null : array_sum($heights) / count($heights);
        $levels = [];
        foreach ($events as $event) {
            if ($event['height_m'] !== null && $mean !== null) {
                $levels[] = [$event['time'], $event['height_m'] - $mean];
            } elseif ($meanRange !== null) {
                $levels[] = [$event['time'], ($event['type'] === 'HW' ? 0.5 : -0.5) * $meanRange];
            } else {
                return null;
            }
        }

        return $levels;
    }

    /**
     * Half-cosine interpolation between the neighbouring events, null
     * outside them.
     *
     * @param list<array{0: int, 1: float}> $levels sorted
     */
    public static function heightAt(array $levels, int $time): ?float
    {
        for ($i = 0, $n = count($levels) - 1; $i < $n; $i++) {
            [$t0, $h0] = $levels[$i];
            [$t1, $h1] = $levels[$i + 1];
            if ($time >= $t0 && $time <= $t1 && $t1 > $t0) {
                $fraction = ($time - $t0) / ($t1 - $t0);

                return round($h0 + ($h1 - $h0) * (1 - cos(M_PI * $fraction)) / 2, 2);
            }
        }

        return null;
    }
}
