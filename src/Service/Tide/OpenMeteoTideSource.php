<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * The fallback everywhere (decision 2026-11-14): Open-Meteo's hourly sea level
 * (`tide_height` in WeatherService's payload, Open-Meteo marine
 * `sea_level_height_msl`) at the nearest sea coastline point, high and low
 * water = the local maxima and minima of that curve, refined with a parabola
 * through the three hours around each. Only the open sea has values - in the
 * estuaries Open-Meteo answers null (Elbe at Wedel and Glückstadt), then this
 * source has nothing (null).
 *
 * The forecast is a closure (WeatherService::getForecast(), cached 30 min)
 * so tests need no network.
 */
final class OpenMeteoTideSource implements TideSource
{
    public const ATTRIBUTION = 'Open-Meteo';
    /** a max/min must stand out by this much from its neighbours' level, otherwise it is noise */
    private const MIN_SWING_M = 0.05;

    /** @param \Closure(float, float): ?array $forecast WeatherService::getForecast() */
    public function __construct(private readonly \Closure $forecast)
    {
    }

    public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
    {
        if ($coast === null) {
            return null;
        }
        try {
            $payload = ($this->forecast)((float) $coast['lat'], (float) $coast['lng']);
        } catch (\Throwable $e) {
            error_log('YTAN Routencheck: sea level for ' . $coast['lat'] . ',' . $coast['lng'] . ' failed: ' . $e->getMessage());

            return null;
        }
        $series = self::series($payload);
        if (count($series) < 6) {
            return null;
        }
        $events = self::extremes($series);
        if ($events === []) {
            return null;
        }

        return [
            'station' => 'Open-Meteo',
            'source' => 'open-meteo',
            'attribution' => self::ATTRIBUTION,
            'lat' => (float) $coast['lat'],
            'lng' => (float) $coast['lng'],
            'distance_m' => (int) round(self::haversine($lat, $lng, (float) $coast['lat'], (float) $coast['lng'])),
            'mean_range_m' => self::meanRange($events),
            'events' => BshTideSource::window($events, $from, $to),
        ];
    }

    /**
     * Hourly sea level as [unix time, metres], null values left out.
     *
     * @return list<array{0: int, 1: float}>
     */
    public static function series(?array $payload): array
    {
        if ($payload === null) {
            return [];
        }
        $offset = (int) ($payload['utc_offset_seconds'] ?? 0);
        $series = [];
        foreach ((array) ($payload['hourly'] ?? []) as $row) {
            if (!is_array($row) || !is_numeric($row['tide_height'] ?? null) || !isset($row['time'])) {
                continue;
            }
            $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', substr((string) $row['time'], 0, 16), new \DateTimeZone('UTC'));
            if ($time !== false) {
                $series[] = [$time->getTimestamp() - $offset, (float) $row['tide_height']];
            }
        }
        usort($series, static fn ($a, $b) => $a[0] <=> $b[0]);

        return $series;
    }

    /**
     * Local maxima (HW) and minima (NW), alternating; a plateau counts once.
     *
     * @param list<array{0: int, 1: float}> $series
     * @return list<array{time: int, type: 'HW'|'NW', height_m: ?float}>
     */
    public static function extremes(array $series): array
    {
        $events = [];
        $count = count($series);
        for ($i = 1; $i < $count - 1; $i++) {
            [$t0, $y0] = $series[$i - 1];
            [$t1, $y1] = $series[$i];
            [, $y2] = $series[$i + 1];
            $type = null;
            if ($y1 > $y0 && $y1 >= $y2) {
                $type = 'HW';
            } elseif ($y1 < $y0 && $y1 <= $y2) {
                $type = 'NW';
            }
            if ($type === null) {
                continue;
            }
            // parabola through the three points: vertex offset in steps
            $denominator = $y0 - 2 * $y1 + $y2;
            $shift = abs($denominator) > 1e-9 ? max(-0.5, min(0.5, 0.5 * ($y0 - $y2) / $denominator)) : 0.0;
            $step = $t1 - $t0;
            $height = $y1 - 0.25 * ($y0 - $y2) * $shift;
            $event = ['time' => (int) round($t1 + $shift * $step), 'type' => $type, 'height_m' => round($height, 2)];
            $last = end($events);
            if ($last !== false && $last['type'] === $type) {
                // two maxima without a minimum between: keep the more extreme one
                if (($type === 'HW') === ($height > $last['height_m'])) {
                    $events[count($events) - 1] = $event;
                }
                continue;
            }
            if ($last !== false && abs($height - $last['height_m']) < self::MIN_SWING_M) {
                continue;
            }
            $events[] = $event;
        }

        return $events;
    }

    /** @param list<array{time: int, type: 'HW'|'NW', height_m: ?float}> $events */
    private static function meanRange(array $events): ?float
    {
        $ranges = [];
        for ($i = 1, $n = count($events); $i < $n; $i++) {
            $ranges[] = abs((float) $events[$i]['height_m'] - (float) $events[$i - 1]['height_m']);
        }

        return $ranges === [] ? null : round(array_sum($ranges) / count($ranges), 2);
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * 6371008.8 * asin(min(1.0, sqrt($a)));
    }
}
