<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

use Ytan\Service\GeometryService;

/**
 * Tide predictions of TidesAtlas (tidesatlas.com, commercial third party,
 * "Free Web" plan: 25 requests per UTC day, key in .env TIDESATLAS_API_KEY,
 * header X-API-Key + Referer of the registered site, server-side only, the
 * credits page carries their required line) - for France, where the national
 * service (SHOM) is paid. Decision of the user 2026-11-16, after a comparison
 * against the national sources: Ø 1-23 min off (Vigo 1, Vlissingen 5,
 * Cuxhaven 7, Lisboa 7, Dover 14, London 17, Hamburg 23), own gauges up the
 * Loire to Nantes and the Gironde to Bordeaux; Open-Meteo is 40-87 min early
 * there. Their terms neither allow nor forbid storing answers (done.md) - the
 * user decided to cache, without it 25 requests a day are nowhere near enough.
 *
 * GET /api/v1/tides?lat&lon&date&days (the docs say 1-14, the Free Web plan
 * refuses more than 7 with HTTP 403 - found live 2026-11-16) picks a calibrated gauge within
 * 50 km itself ("data_source": "ticon"), else the FES2022 model ("fes2022",
 * not used here - a model like Open-Meteo). Two cache levels to save
 * requests: a 0.05 degree cell (~5 km) remembers its station slug, the
 * station's events (7 days) are shared by every cell that maps to it and
 * refreshed by slug (?port=). A request counter per UTC day
 * (tidesatlas-quota.json) stops at DAILY_LIMIT, leaving a margin below 25.
 */
final class TidesAtlasTideSource implements TideSource
{
    public const BASE_URL = 'https://tidesatlas.com/api/v1/tides';
    /** the address registered for the Free Web key */
    public const REFERER = 'https://ytan.pesr.org/';
    public const DAILY_LIMIT = 22;
    private const DAYS = 7;
    private const CELL = 0.05;
    /** mainland France incl. Corsica: south, west, north, east */
    private const BOX = [41.3, -5.3, 51.2, 9.7];
    private const QUOTA_FILE = 'tidesatlas-quota.json';

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
        $cellLat = round($lat / self::CELL) * self::CELL;
        $cellLng = round($lng / self::CELL) * self::CELL;
        $cellFile = sprintf('ta-cell-%.2f_%.2f.json', $cellLat, $cellLng);
        $cell = $this->cache->read($cellFile);
        if ($cell !== null && (isset($cell['failed']) || isset($cell['nodata']))) {
            return null;
        }

        $station = $cell === null ? null : $this->cache->read(self::stationFile((string) $cell['slug']));
        if ($station !== null && isset($station['failed'])) {
            return null;
        }
        if ($station === null || $station['from'] > $from || $station['to'] < $to) {
            $query = $cell === null ? ['lat' => round($cellLat, 4), 'lon' => round($cellLng, 4)] : ['port' => $cell['slug']];
            $station = $this->fetch($query, $from, $cell === null ? $cellFile : self::stationFile((string) $cell['slug']));
            if ($station === null) {
                return null;
            }
            if ($cell === null) {
                $this->cache->write($cellFile, isset($station['nodata']) ? ['nodata' => true] : ['slug' => $station['slug']]);
                if (isset($station['nodata'])) {
                    return null;
                }
            }
        }
        if ($station['events'] === []) {
            return null;
        }
        $distance = (int) round(GeometryService::distanceMeters($lat, $lng, (float) $station['lat'], (float) $station['lng']));
        if ($distance > $maxStationMeters) {
            return null;
        }

        return [
            'station' => (string) $station['name'],
            'source' => 'tidesatlas',
            'attribution' => (string) $station['attribution'],
            'lat' => (float) $station['lat'],
            'lng' => (float) $station['lng'],
            'distance_m' => $distance,
            'mean_range_m' => GaugeTideSource::meanRange($station['events']),
            'events' => BshTideSource::window($station['events'], $from, $to),
        ];
    }

    /**
     * One request (within the daily limit), the station stored under its slug.
     *
     * @param array<string, scalar> $query lat/lon or port
     * @return array<string, mixed>|null the station file's content, ['nodata' => true] for a place without a gauge, null = nothing now
     */
    private function fetch(array $query, int $from, string $failFile): ?array
    {
        if (!$this->takeRequest()) {
            return null;
        }
        $start = gmdate('Y-m-d', $from);
        $body = $this->http->get(self::BASE_URL . '?' . http_build_query($query + ['date' => $start, 'days' => self::DAYS]), [
            'X-API-Key: ' . $this->apiKey,
            'Referer: ' . self::REFERER,
            'Accept: application/json',
        ]);
        $parsed = $body === null ? null : self::parse($body);
        if ($parsed === null) {
            // label without the key; a 429 (quota) lands here too and is retried after an hour
            $this->cache->failed($failFile, 'TidesAtlas ' . http_build_query($query));

            return null;
        }
        if (isset($parsed['nodata'])) {
            return $parsed;
        }
        $events = $parsed['events'];
        $startTime = (int) strtotime($start . ' 00:00:00 UTC');

        return $this->cache->write(self::stationFile($parsed['slug']), $parsed + [
            'from' => $startTime,
            // covered up to the last event really received
            'to' => $events === [] ? $startTime : $events[count($events) - 1]['time'],
        ]);
    }

    /** Counts a request against today's (UTC) limit; false = limit reached. */
    private function takeRequest(): bool
    {
        $today = gmdate('Y-m-d', ($this->clock)());
        $quota = $this->cache->read(self::QUOTA_FILE);
        $count = $quota !== null && ($quota['day'] ?? '') === $today ? (int) ($quota['count'] ?? 0) : 0;
        if ($count >= self::DAILY_LIMIT) {
            return false;
        }
        $this->cache->write(self::QUOTA_FILE, ['day' => $today, 'count' => $count + 1]);

        return true;
    }

    private static function stationFile(string $slug): string
    {
        return 'ta-' . TideCache::safe($slug) . '.json';
    }

    /**
     * @return array{slug: string, name: string, lat: float, lng: float, attribution: string, events: list<array{time: int, type: 'HW'|'NW', height_m: ?float}>}|array{nodata: true}|null null = not the expected answer
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !empty($data['error']) || !is_array($data['extremes'] ?? null)) {
            return null;
        }
        $port = $data['port'] ?? null;
        $source = (array) ($data['tide_source'] ?? []);
        // only calibrated gauges: the FES2022 model or an "inland" guess is no better than Open-Meteo
        if (($data['data_source'] ?? '') !== 'ticon' || !is_array($port) || !isset($port['slug'], $port['lat'], $port['lon'])
            || ($source['confidence'] ?? '') === 'none' || ($source['place_type'] ?? '') === 'inland') {
            return ['nodata' => true];
        }
        $events = [];
        foreach ($data['extremes'] as $row) {
            $type = $row['type'] ?? null;
            $time = isset($row['timestamp']) && is_numeric($row['timestamp']) ? (int) $row['timestamp'] : strtotime((string) ($row['datetime'] ?? ''));
            if ($time === false || !in_array($type, ['high', 'low'], true)) {
                continue;
            }
            $height = $row['height_m'] ?? null;
            $events[] = ['time' => $time, 'type' => $type === 'high' ? 'HW' : 'NW', 'height_m' => is_numeric($height) ? round((float) $height, 2) : null];
        }
        usort($events, static fn ($a, $b) => $a['time'] <=> $b['time']);
        $attribution = 'TidesAtlas';
        if (is_string($source['attribution'] ?? null) && $source['attribution'] !== '') {
            $attribution .= ' / ' . $source['attribution'];
        }

        return [
            'slug' => (string) $port['slug'],
            'name' => (string) ($port['name'] ?? $port['slug']),
            'lat' => (float) $port['lat'],
            'lng' => (float) $port['lon'],
            'attribution' => $attribution,
            'events' => UkhoTideSource::merged($events),
        ];
    }
}
