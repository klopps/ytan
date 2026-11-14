<?php

declare(strict_types=1);

namespace Ytan\Service\Ai;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Gathers everything the Routencheck needs for one route and day: the route
 * cut into sections, the weather forecast along it (a few sample points,
 * WeatherService - cached 30 min), the open water around each section (water
 * index) and the POIs next to it (landing sites, shelters, campsites, danger
 * spots, portages), then rates it (RouteRating) and builds the compact context
 * the AI gets (aiContext()).
 *
 * The data sources are closures so tests can run without network and index.
 */
final class RouteBriefingBuilder
{
    public const POI_TYPES = [1 => 'landing', 2 => 'landing', 6 => 'danger', 9 => 'portage', 10 => 'landing', 11 => 'landing'];
    private const POI_MAX_DISTANCE_M = 2000;
    private const POI_MAX_COUNT = 30;
    private const WEATHER_SAMPLE_SPACING_M = 8000;
    private const WEATHER_MAX_SAMPLES = 5;
    private const FLOW_SAMPLE_SPACING_M = 200;
    private const FLOW_MAX_SAMPLES = 40;

    /**
     * @param \Closure(float, float): array $forecast WeatherService::getForecast()
     * @param \Closure(array{0: float, 1: float}, float): ?int $openWater WaterDistanceService::openWaterAlong()
     * @param \Closure(float, float, float, float, list<int>, ?int): list<array<string, mixed>> $pois POIs in a box the user may see (south, north, west, east, types, user id)
     * @param (\Closure(float, float, int): ?array{kind: string, bearing: float})|null $flow river/canal at a position within a distance (WaterDistanceService::flowAt()); null = no current
     */
    public function __construct(
        private readonly \Closure $forecast,
        private readonly \Closure $openWater,
        private readonly \Closure $pois,
        private readonly ?\Closure $flow = null,
    ) {
    }

    /**
     * Where each section runs on a river or canal: samples every ~200 m (at
     * most FLOW_MAX_SAMPLES per section), each compared with the direction of
     * the river's centre line there (drawn downstream in OpenStreetMap).
     * river_along = mean cos(course vs. flow) over all samples (off-river = 0),
     * river_share / canal_share = share of the samples on a river / canal.
     *
     * @return array{river_along: float, river_share: float, canal_share: float}|null null = no flow lookup
     */
    private function sectionFlow(RouteGeometry $geometry, array $section, int $maxMeters): ?array
    {
        if ($this->flow === null || $section['length'] <= 0) {
            return null;
        }
        $count = max(1, min(self::FLOW_MAX_SAMPLES, (int) round($section['length'] / self::FLOW_SAMPLE_SPACING_M)));
        $along = 0.0;
        $river = 0;
        $canal = 0;
        for ($i = 0; $i < $count; $i++) {
            $at = $section['from'] + $section['length'] * ($i + 0.5) / $count;
            [$lat, $lng] = $geometry->at($at);
            $found = ($this->flow)($lat, $lng, $maxMeters);
            if ($found === null) {
                continue;
            }
            if ($found['kind'] === 'canal') {
                $canal++;
                continue;
            }
            $before = $geometry->at(max(0.0, $at - 50));
            $after = $geometry->at(min($geometry->length, $at + 50));
            if (RouteGeometry::distance($before, $after) < 1.0) {
                continue;
            }
            $course = RouteGeometry::bearing($before, $after);
            $river++;
            $along += cos(deg2rad(RouteGeometry::angleBetween($course, (float) $found['bearing'])));
        }

        return ['river_along' => $along / $count, 'river_share' => $river / $count, 'canal_share' => $canal / $count];
    }

    /**
     * @param list<array{0: float, 1: float}> $points the route's waypoints
     * @param array{date: string, start_hour: int, reverse: bool, speed_kmh: float, level: string, current_kmh?: ?float, user_id?: ?int} $options current_kmh null = the rules' river_current_kmh
     * @param array<string, mixed> $rules BriefingRules::normalize()d
     * @return array<string, mixed>
     */
    public function build(array $points, array $options, array $rules): array
    {
        if ($options['reverse']) {
            $points = array_reverse($points);
        }
        $geometry = new RouteGeometry($points);
        $sections = $geometry->sections();

        $samples = max(1, min(self::WEATHER_MAX_SAMPLES, (int) ceil($geometry->length / self::WEATHER_SAMPLE_SPACING_M)));
        $forecasts = [];
        $weatherInfo = null;
        $timezone = null;
        for ($i = 0; $i < $samples; $i++) {
            [$lat, $lng] = $geometry->at($geometry->length * ($i + 0.5) / $samples);
            $payload = null;
            try {
                $payload = ($this->forecast)($lat, $lng);
            } catch (Throwable $e) {
                error_log('YTAN Routencheck: weather for ' . $lat . ',' . $lng . ' failed: ' . $e->getMessage());
            }
            $zone = self::timezone($payload);
            $timezone ??= $zone;
            $weatherInfo ??= $payload !== null ? [
                'source' => $payload['source'] ?? null,
                'model' => $payload['model'] ?? null,
                'generated_at' => $payload['generated_at'] ?? null,
            ] : null;
            $forecasts[] = self::forecastTables($payload, $zone ?? new DateTimeZone('UTC'));
        }
        $timezone ??= new DateTimeZone('UTC');
        $segmentLength = $geometry->length / $samples;
        foreach ($sections as $i => $section) {
            $mid = ($section['from'] + $section['to']) / 2;
            $sections[$i]['weather'] = $segmentLength > 0 ? min($samples - 1, (int) floor($mid / $segmentLength)) : 0;
        }

        $onRiver = false;
        $onCanal = false;
        foreach ($sections as $i => $section) {
            $sections[$i]['flow'] = $this->sectionFlow($geometry, $section, (int) $rules['river_max_m']);
            $onRiver = $onRiver || ($sections[$i]['flow']['river_share'] ?? 0) > 0;
            $onCanal = $onCanal || ($sections[$i]['flow']['canal_share'] ?? 0) > 0;
        }
        $currentKmh = (float) ($options['current_kmh'] ?? $rules['river_current_kmh']);

        $levelFactor = (float) ($rules['levels'][$options['level']] ?? 1.0);
        $rating = new RouteRating($sections, $forecasts, $this->openWater, $rules, $levelFactor, $timezone, $currentKmh);
        $start = new DateTimeImmutable(sprintf('%s %02d:00:00', $options['date'], $options['start_hour']), $timezone);
        $assessment = $rating->assess($start, $options['speed_kmh']);
        foreach ($assessment['sections'] as $i => $section) {
            $assessment['sections'][$i]['points'] = array_map(
                static fn (array $p) => [round($p[0], 6), round($p[1], 6)],
                $sections[$i]['points']
            );
            $assessment['sections'][$i]['bearing'] = (int) round($sections[$i]['bearing']);
        }
        $sun = $forecasts[0]['sun'][$options['date']] ?? null;

        return [
            'date' => $options['date'],
            'start_hour' => $options['start_hour'],
            'reverse' => $options['reverse'],
            'speed_kmh' => $options['speed_kmh'],
            'level' => $options['level'],
            'length_m' => (int) round($geometry->length),
            'start' => $start->format('H:i'),
            'end' => $assessment['end']->format('H:i'),
            'end_date' => $assessment['end']->format('Y-m-d'),
            'duration_min' => (int) round(($assessment['end']->getTimestamp() - $start->getTimestamp()) / 60),
            'rating' => $assessment['rating'],
            'partial' => $assessment['partial'],
            'reasons' => $assessment['reasons'],
            'sections' => $assessment['sections'],
            'windows' => $rating->windows($options['date'], $options['speed_kmh']),
            'pois' => $this->nearbyPois($geometry, $options['user_id'] ?? null),
            'sun' => [
                'sunrise' => $sun !== null && $sun['sunrise'] !== null ? $sun['sunrise']->format('H:i') : null,
                'sunset' => $sun !== null && $sun['sunset'] !== null ? $sun['sunset']->format('H:i') : null,
            ],
            'weather' => $weatherInfo,
            'thresholds' => [
                'wind' => self::scaled($rules['wind'], $levelFactor),
                'gust' => self::scaled($rules['gust'], $levelFactor),
                'wave' => self::scaled($rules['wave'], $levelFactor),
                'current' => self::scaled($rules['current'], $levelFactor),
            ],
            // on_river: the sheet offers the current speed chips only then
            'current' => [
                'on_river' => $onRiver,
                'on_canal' => $onCanal,
                'river_kmh' => round($currentKmh, 1),
                'default_kmh' => (float) $rules['river_current_kmh'],
            ],
        ];
    }

    /**
     * What the AI gets: the result in a compact, rounded form (rounded so a
     * forecast refresh without a real change gives the same text - the cache
     * key is this context). No user data, no route name.
     *
     * @param array<string, mixed> $result build()'s
     * @return array<string, mixed>
     */
    public static function aiContext(array $result): array
    {
        $labels = [0 => 'green', 1 => 'yellow', 2 => 'red'];
        $rating = static fn (?int $r) => $r === null ? 'grey (no data)' : $labels[$r];
        $reasons = static fn (array $list) => array_map(static fn (array $r) => array_filter([
            'criterion' => $r['criterion'],
            'value' => is_float($r['value']) ? self::roundHalf($r['value'], $r['criterion']) : $r['value'],
            'threshold' => $r['threshold'],
            'level' => $labels[$r['level']] ?? null,
        ], static fn ($v) => $v !== null), $list);

        $sections = [];
        foreach ($result['sections'] as $s) {
            $v = $s['values'] ?? [];
            $sections[] = array_filter([
                'km' => sprintf('%.1f-%.1f', $s['from_m'] / 1000, $s['to_m'] / 1000),
                'time' => $s['entry'] . '-' . $s['exit'],
                'course' => self::compass((float) $s['bearing']),
                'rating' => $rating($s['rating']),
                'wind_ms' => isset($v['wind']) ? self::roundHalf($v['wind'], 'wind') : null,
                'gust_ms' => isset($v['gust']) ? self::roundHalf($v['gust'], 'gust') : null,
                'wind_from' => isset($v['direction']) ? self::compass((float) $v['direction']) : null,
                'wind_relative' => $v['relative'] ?? null,
                'wave_m' => $v['wave'] ?? null,
                'open_water_upwind_km' => isset($v['fetch_m']) ? round($v['fetch_m'] / 1000, 1) : null,
                'lee' => !empty($v['lee']) ? true : null,
                'exposed' => !empty($v['exposed']) ? true : null,
                'offshore_wind' => !empty($v['offshore']) ? true : null,
                'water' => $v['water'] ?? null,
                'current_kmh' => isset($v['current']) ? round($v['current'] * 2) / 2 : null,
                'reasons' => $reasons($s['reasons']) ?: null,
            ], static fn ($value) => $value !== null);
        }

        $windows = array_map(static fn (array $w) => sprintf('%02d:00 %s%s', $w['hour'], $rating($w['rating']), $w['reason'] !== null ? ' (' . $w['reason'] . ')' : ''), $result['windows']);

        $places = array_map(static fn (array $p) => [
            'name' => $p['name'],
            'type' => $p['type_label'],
            'role' => $p['role'],
            'km' => round($p['at_m'] / 1000, 1),
            'off_route_m' => (int) (round($p['distance_m'] / 50) * 50),
        ], $result['pois']);

        return [
            'route' => [
                'date' => $result['date'],
                'start' => $result['start'],
                'end' => $result['end'],
                'length_km' => round($result['length_m'] / 1000, 1),
                'speed_kmh' => $result['speed_kmh'],
                'paddler_level' => $result['level'],
                'reversed' => $result['reverse'],
            ] + (!empty($result['current']['on_river']) ? ['assumed_river_current_kmh' => $result['current']['river_kmh']] : [])
              + (!empty($result['current']['on_canal']) ? ['partly_on_canal' => true] : []),
            'overall_rating' => $rating($result['rating']),
            'partly_without_data' => $result['partial'],
            'reasons' => $reasons($result['reasons']),
            'sections' => $sections,
            'start_hours' => $windows,
            'daylight' => $result['sun'],
            'places_near_route' => $places,
            'thresholds_for_this_paddler' => $result['thresholds'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function nearbyPois(RouteGeometry $geometry, ?int $userId): array
    {
        $lats = array_column($geometry->points, 0);
        $lngs = array_column($geometry->points, 1);
        $marginLat = self::POI_MAX_DISTANCE_M / 111320.0;
        $marginLng = $marginLat / max(0.1, cos(deg2rad((min($lats) + max($lats)) / 2)));
        $candidates = ($this->pois)(min($lats) - $marginLat, max($lats) + $marginLat, min($lngs) - $marginLng, max($lngs) + $marginLng, array_keys(self::POI_TYPES), $userId);

        $nearby = [];
        foreach ($candidates as $poi) {
            $near = $geometry->nearest((float) $poi['latitude'], (float) $poi['longitude']);
            if ($near['distance'] > self::POI_MAX_DISTANCE_M) {
                continue;
            }
            $type = (int) $poi['poitype_id'];
            $nearby[] = [
                'id' => (int) $poi['id'],
                'name' => (string) $poi['name'],
                'type' => $type,
                'type_label' => (string) ($poi['type_label'] ?? $type),
                'role' => self::POI_TYPES[$type] ?? 'landing',
                'lat' => (float) $poi['latitude'],
                'lng' => (float) $poi['longitude'],
                'distance_m' => (int) round($near['distance']),
                'at_m' => (int) round($near['along']),
            ];
        }
        usort($nearby, static fn ($a, $b) => $a['distance_m'] <=> $b['distance_m']);
        $nearby = array_slice($nearby, 0, self::POI_MAX_COUNT);
        usort($nearby, static fn ($a, $b) => $a['at_m'] <=> $b['at_m']);

        return $nearby;
    }

    /**
     * Hourly rows keyed by their local time ("2026-11-14T09:00") and sunrise/
     * sunset per date, from a WeatherService payload (null = no data).
     *
     * @return array{hourly: array<string, array<string, mixed>>, sun: array<string, array{sunrise: ?DateTimeImmutable, sunset: ?DateTimeImmutable}>}
     */
    private static function forecastTables(?array $payload, DateTimeZone $zone): array
    {
        $hourly = [];
        foreach ((array) ($payload['hourly'] ?? []) as $row) {
            if (is_array($row) && isset($row['time'])) {
                $hourly[substr((string) $row['time'], 0, 13) . ':00'] = $row;
            }
        }
        $sun = [];
        foreach ((array) ($payload['daily'] ?? []) as $day) {
            if (!is_array($day) || !isset($day['date'])) {
                continue;
            }
            $parse = static function (mixed $value) use ($zone): ?DateTimeImmutable {
                if (!is_string($value) || $value === '') {
                    return null;
                }
                $time = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($value, 0, 16), $zone);

                return $time === false ? null : $time;
            };
            $sun[(string) $day['date']] = ['sunrise' => $parse($day['sunrise'] ?? null), 'sunset' => $parse($day['sunset'] ?? null)];
        }

        return ['hourly' => $hourly, 'sun' => $sun];
    }

    private static function timezone(?array $payload): ?DateTimeZone
    {
        if ($payload === null || !is_numeric($payload['utc_offset_seconds'] ?? null)) {
            return null;
        }
        $seconds = (int) $payload['utc_offset_seconds'];
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return new DateTimeZone(sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60)));
    }

    private static function scaled(array $pair, float $factor): array
    {
        return ['yellow' => round($pair['yellow'] * $factor, 1), 'red' => round($pair['red'] * $factor, 1)];
    }

    /** Wind and gusts to 0.5 m/s, everything else as it is. */
    private static function roundHalf(float $value, string $criterion): float
    {
        return in_array($criterion, ['wind', 'gust'], true) ? round($value * 2) / 2 : round($value, 1);
    }

    private static function compass(float $degrees): string
    {
        $names = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

        return $names[((int) round($degrees / 45)) % 8];
    }
}
