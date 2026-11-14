<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

use Ytan\Service\JsonHttpClient;

/**
 * Tide predictions of the German Federal Maritime and Hydrographic Agency
 * (BSH, gezeiten.bsh.de): high and low water times for 172 gauges on the
 * North Sea and Baltic coasts and up the estuaries (Elbe to the Geesthacht
 * weir, Weser to the Bremen weir, Ems to Papenburg) - exactly where
 * Open-Meteo has no sea level at all.
 *
 * The data are the files behind BSH's own tide page (found 2026-11-14):
 * the gauge list tides_overview.json ({gauges: [{station_name, bshnr,
 * latitude, longitude}]}) and per gauge DE_{bshnr padded to 5 with "_"}
 * _tides.json ({years: [{"2026": {MTH (mean tidal range, cm),
 * hwnw_prediction: {data: [{timestamp, height (cm), type HW|NW}]}}}]}, the
 * current and the next year, ~700 KB, changed about once a year). Both are
 * kept compact in $cacheDir for CACHE_SECONDS; a failed download is
 * remembered for FAIL_SECONDS so a BSH outage does not slow every
 * Routencheck. Use: "©BSH" next to the data (decided 2026-11-14).
 *
 * The gauge is the nearest one within $maxStationMeters; when it has no
 * predictions (some Baltic gauges), the next nearest ones are tried.
 */
final class BshTideSource implements TideSource
{
    public const BASE_URL = 'https://gezeiten.bsh.de/data';
    public const ATTRIBUTION = '©BSH';
    private const CACHE_SECONDS = 30 * 86400;
    /** bin/cleanup-file-caches.php deletes cache files older than this (unused for longer than they stay valid) */
    public const CACHE_MAX_AGE_SECONDS = 31 * 86400;
    private const FAIL_SECONDS = 3600;
    private const MAX_GAUGES_TRIED = 3;

    public function __construct(
        private readonly string $cacheDir,
        private readonly JsonHttpClient $http,
    ) {
    }

    public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
    {
        $gauges = $this->gauges();
        if ($gauges === null) {
            return null;
        }
        $near = [];
        foreach ($gauges as $gauge) {
            $distance = self::haversine($lat, $lng, $gauge['lat'], $gauge['lng']);
            if ($distance <= $maxStationMeters) {
                $near[] = $gauge + ['distance' => $distance];
            }
        }
        usort($near, static fn ($a, $b) => $a['distance'] <=> $b['distance']);

        foreach (array_slice($near, 0, self::MAX_GAUGES_TRIED) as $gauge) {
            $data = $this->gauge($gauge['id'], $to);
            if ($data === null || ($data['events'] === [] && $data['mean_range_m'] === null)) {
                continue;
            }

            return [
                'station' => $gauge['name'],
                'source' => 'bsh',
                'attribution' => self::ATTRIBUTION,
                'lat' => $gauge['lat'],
                'lng' => $gauge['lng'],
                'distance_m' => (int) round($gauge['distance']),
                'mean_range_m' => $data['mean_range_m'],
                'events' => self::window($data['events'], $from, $to),
            ];
        }

        return null;
    }

    /**
     * The events from the last one before $from to the first one after $to.
     *
     * @param list<array{time: int, type: 'HW'|'NW', height_m: ?float}> $events sorted
     * @return list<array{time: int, type: 'HW'|'NW', height_m: ?float}>
     */
    public static function window(array $events, int $from, int $to): array
    {
        $result = [];
        $count = count($events);
        for ($i = 0; $i < $count; $i++) {
            $next = $events[$i + 1]['time'] ?? PHP_INT_MAX;
            $previous = $events[$i - 1]['time'] ?? PHP_INT_MIN;
            if ($next >= $from && $previous <= $to) {
                $result[] = $events[$i];
            }
        }

        return $result;
    }

    /** @return list<array{id: string, name: string, lat: float, lng: float}>|null */
    private function gauges(): ?array
    {
        $cached = $this->read('bsh-gauges.json');
        if ($cached !== null && isset($cached['gauges'])) {
            return $cached['gauges'];
        }
        $answer = $this->http->getJson(self::BASE_URL . '/tides_overview.json', []);
        if (!is_array($answer['gauges'] ?? null)) {
            $this->failed('bsh-gauges.json');

            return null;
        }

        return $this->write('bsh-gauges.json', ['gauges' => self::parseGauges($answer)])['gauges'];
    }

    /**
     * @param array<string, mixed> $overview tides_overview.json
     * @return list<array{id: string, name: string, lat: float, lng: float}>
     */
    public static function parseGauges(array $overview): array
    {
        $gauges = [];
        foreach ((array) ($overview['gauges'] ?? []) as $gauge) {
            if (!is_array($gauge) || !isset($gauge['bshnr'], $gauge['latitude'], $gauge['longitude'])
                || !preg_match('/^[A-Za-z0-9]{1,5}$/', (string) $gauge['bshnr'])) {
                continue;
            }
            $gauges[] = [
                'id' => (string) $gauge['bshnr'],
                'name' => (string) ($gauge['station_name'] ?? $gauge['bshnr']),
                'lat' => (float) $gauge['latitude'],
                'lng' => (float) $gauge['longitude'],
            ];
        }

        return $gauges;
    }

    /**
     * One gauge's predictions; downloaded again when the cached ones end
     * before $to (new year) or are older than CACHE_SECONDS.
     *
     * @return array{mean_range_m: ?float, events: list<array{time: int, type: 'HW'|'NW', height_m: ?float}>}|null
     */
    private function gauge(string $id, int $to): ?array
    {
        $file = 'bsh-' . $id . '.json';
        $cached = $this->read($file);
        if ($cached !== null && isset($cached['events'])) {
            $last = end($cached['events']);
            if ($last === false || $last['time'] >= $to) {
                return $cached;
            }
        } elseif ($cached !== null) {
            return null; // failed recently
        }
        $answer = $this->http->getJson(self::BASE_URL . '/DE_' . str_pad($id, 5, '_', STR_PAD_LEFT) . '_tides.json', []);
        if ($answer === null || !is_array($answer['years'] ?? null)) {
            $this->failed($file);

            return null;
        }

        return $this->write($file, self::parseGauge($answer));
    }

    /**
     * @param array<string, mixed> $file DE_xxxxx_tides.json
     * @return array{mean_range_m: ?float, events: list<array{time: int, type: 'HW'|'NW', height_m: ?float}>}
     */
    public static function parseGauge(array $file): array
    {
        $events = [];
        $range = null;
        foreach ((array) ($file['years'] ?? []) as $yearEntry) {
            foreach ((array) $yearEntry as $year) {
                if (!is_array($year)) {
                    continue;
                }
                if ($range === null && is_numeric($year['MTH'] ?? null) && (float) $year['MTH'] > 0) {
                    $range = round((float) $year['MTH'] / 100, 2);
                }
                foreach ((array) ($year['hwnw_prediction']['data'] ?? []) as $event) {
                    $time = is_array($event) ? strtotime((string) ($event['timestamp'] ?? '')) : false;
                    $type = $event['type'] ?? null;
                    if ($time === false || ($type !== 'HW' && $type !== 'NW')) {
                        continue;
                    }
                    $events[] = [
                        'time' => $time,
                        'type' => $type,
                        'height_m' => is_numeric($event['height'] ?? null) ? round((float) $event['height'] / 100, 2) : null,
                    ];
                }
            }
        }
        usort($events, static fn ($a, $b) => $a['time'] <=> $b['time']);
        // some gauges (Baltic, Geesthacht weir) have no MTH: the mean of their own high-low differences
        if ($range === null) {
            $differences = [];
            for ($i = 1, $n = count($events); $i < $n; $i++) {
                if ($events[$i]['height_m'] !== null && $events[$i - 1]['height_m'] !== null && $events[$i]['type'] !== $events[$i - 1]['type']) {
                    $differences[] = abs($events[$i]['height_m'] - $events[$i - 1]['height_m']);
                }
            }
            $range = $differences === [] ? null : round(array_sum($differences) / count($differences), 2);
        }

        return ['mean_range_m' => $range, 'events' => $events];
    }

    /** @return array<string, mixed>|null fresh cache content; ['failed' => t] for a recent failure */
    private function read(string $file): ?array
    {
        $path = $this->cacheDir . '/' . $file;
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }
        $maxAge = isset($data['failed']) ? self::FAIL_SECONDS : self::CACHE_SECONDS;

        return time() - $mtime <= $maxAge ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function write(string $file, array $data): array
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        $temp = $this->cacheDir . '/' . $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, json_encode($data)) !== false) {
            @rename($temp, $this->cacheDir . '/' . $file);
        }

        return $data;
    }

    private function failed(string $file): void
    {
        error_log('YTAN: BSH tide data ' . $file . ' could not be loaded');
        $this->write($file, ['failed' => time()]);
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * 6371008.8 * asin(min(1.0, sqrt($a)));
    }
}
