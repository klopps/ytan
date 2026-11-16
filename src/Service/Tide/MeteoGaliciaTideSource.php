<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

use Ytan\Service\GeometryService;

/**
 * Tide predictions of MeteoGalicia (Xunta de Galicia), MeteoSIX API v5
 * getTidesInfo - Galicia only (checked 2026-11-16: Bilbao, Gijón, Porto,
 * Cádiz, Brest answer exception 217 "no information about tides"). Free key,
 * personal (.env METEOSIX_API_KEY, never logged or shown), data CC BY-SA 4.0
 * ("Fonte dos datos: Xunta de Galicia", credits page).
 *
 * The API picks the nearest port itself (Tui -> Vigo, open sea 50 km west ->
 * Corcubión), so like KartverketTideSource the position (rounded to 0.02
 * degrees) is the cache key. Answer: GeoJSON, per feature `port` (name,
 * coordinates) and `days[].variables[name=tides].summary[]` with
 * {state: "High tides"|"Low tides", timeInstant: "2026-11-16T08:19:00+01",
 * height}. Limits found live: startTime not before today (Madrid time,
 * exception 213), at most ~21 days per request (30 -> exception 215), every
 * error is HTTP 200 with an `exception` object.
 */
final class MeteoGaliciaTideSource implements TideSource
{
    public const BASE_URL = 'https://servizos.meteogalicia.gal/apiv5/getTidesInfo';
    private const WINDOW_DAYS = 20;
    /** Galicia: south, west, north, east */
    private const BOX = [41.8, -9.6, 43.9, -6.7];
    private const NO_TIDES = '217';

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock current unix time (tests) */
    public function __construct(
        private readonly string $apiKey,
        private readonly TideCache $cache,
        private readonly TideHttp $http,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
    {
        if ($this->apiKey === '' || $lat < self::BOX[0] || $lat > self::BOX[2] || $lng < self::BOX[1] || $lng > self::BOX[3]) {
            return null;
        }
        $qLat = round($lat / 0.02) * 0.02;
        $qLng = round($lng / 0.02) * 0.02;
        $file = sprintf('mg-%.2f_%.2f.json', $qLat, $qLng);
        $cached = $this->cache->read($file);
        if ($cached !== null && (isset($cached['failed']) || isset($cached['nodata']))) {
            return null;
        }
        if ($cached === null || $cached['from'] > max($from, $this->today()) || $cached['to'] < $to) {
            $start = $this->today();
            $end = $start + self::WINDOW_DAYS * 86400;
            $madrid = new \DateTimeZone('Europe/Madrid');
            $body = $this->http->get(self::BASE_URL . '?' . http_build_query([
                'coords' => sprintf('%.4f,%.4f', $qLng, $qLat),
                'startTime' => (new \DateTimeImmutable('@' . $start))->setTimezone($madrid)->format('Y-m-d\TH:i:s'),
                'endTime' => (new \DateTimeImmutable('@' . $end))->setTimezone($madrid)->format('Y-m-d\TH:i:s'),
                'lang' => 'en',
                'API_KEY' => $this->apiKey,
            ]));
            $parsed = $body === null ? null : self::parse($body);
            if ($parsed === null) {
                // label without the URL: it carries the personal key
                $this->cache->failed($file, 'MeteoGalicia ' . sprintf('%.2f,%.2f', $qLat, $qLng));

                return null;
            }
            // covered up to the last event really received (the horizon is the API's, not ours)
            $events = $parsed['events'] ?? [];
            $last = $events === [] ? $start : $events[count($events) - 1]['time'];
            $cached = $this->cache->write($file, $parsed + ['from' => $start, 'to' => isset($parsed['nodata']) ? $end : $last]);
            if (isset($cached['nodata'])) {
                return null;
            }
        }
        if ($cached['events'] === []) {
            return null;
        }
        $distance = (int) round(GeometryService::distanceMeters($lat, $lng, (float) $cached['lat'], (float) $cached['lng']));
        if ($distance > $maxStationMeters) {
            return null;
        }

        return [
            'station' => (string) $cached['name'],
            'source' => 'meteogalicia',
            'attribution' => '©MeteoGalicia (Xunta de Galicia)',
            'lat' => (float) $cached['lat'],
            'lng' => (float) $cached['lng'],
            'distance_m' => $distance,
            'mean_range_m' => GaugeTideSource::meanRange($cached['events']),
            'events' => BshTideSource::window($cached['events'], $from, $to),
        ];
    }

    /** Today 00:00 in Madrid - the API refuses an earlier start. */
    private function today(): int
    {
        $madrid = new \DateTimeZone('Europe/Madrid');

        return (new \DateTimeImmutable('@' . ($this->clock)()))->setTimezone($madrid)->setTime(0, 0)->getTimestamp();
    }

    /**
     * @return array{name: string, lat: float, lng: float, events: list<array{time: int, type: 'HW'|'NW', height_m: ?float}>}|array{nodata: true}|null null = not the expected answer
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['exception'])) {
            return (string) ($data['exception']['code'] ?? '') === self::NO_TIDES ? ['nodata' => true] : null;
        }
        $properties = $data['features'][0]['properties'] ?? null;
        $coordinates = $properties['port']['geometry']['coordinates'] ?? null;
        if (!is_array($properties) || !is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }
        $events = [];
        foreach ($properties['days'] ?? [] as $day) {
            foreach ($day['variables'] ?? [] as $variable) {
                if (($variable['name'] ?? '') !== 'tides') {
                    continue;
                }
                foreach ($variable['summary'] ?? [] as $row) {
                    $state = (string) ($row['state'] ?? '');
                    $time = strtotime((string) ($row['timeInstant'] ?? ''));
                    if ($time === false || !in_array($state, ['High tides', 'Low tides'], true)) {
                        continue;
                    }
                    $height = $row['height'] ?? null;
                    $events[] = ['time' => $time, 'type' => $state === 'High tides' ? 'HW' : 'NW', 'height_m' => is_numeric($height) ? round((float) $height, 2) : null];
                }
            }
        }
        usort($events, static fn ($a, $b) => $a['time'] <=> $b['time']);

        return [
            'name' => (string) ($properties['port']['name'] ?? 'MeteoGalicia'),
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
            'events' => $events,
        ];
    }
}
