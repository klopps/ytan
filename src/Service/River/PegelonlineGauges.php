<?php

declare(strict_types=1);

namespace Ytan\Service\River;

use Ytan\Service\GeometryService;
use Ytan\Service\Tide\TideCache;
use Ytan\Service\Tide\TideHttp;

/**
 * The nearest water level gauge of the German federal waterways (WSV,
 * PEGELONLINE REST API v2, Datenlizenz Deutschland - Namensnennung 2.0) with
 * its latest measurement and the WSV's own classification of it:
 * stateMnwMhw (low / normal / high = above mean high water) and stateNswHsw
 * (high = above the highest navigable water level, HSW - shipping stops).
 * PEGELONLINE has no flow speed (no cross-section) and no forecast, so the
 * Routencheck can only say "high water now" - see RouteBriefingBuilder.
 *
 * One request per 0.05 degree cell returns all stations within the radius
 * with their current W measurement; it is cached CACHE_SECONDS in the tide
 * cache directory (cleaned by bin/cleanup-file-caches.php), a failure
 * FAIL_SECONDS. Measurements older than MAX_MEASUREMENT_AGE_SECONDS are
 * ignored (a gauge that stopped reporting).
 */
final class PegelonlineGauges
{
    public const BASE_URL = 'https://www.pegelonline.wsv.de/webservices/rest-api/v2/stations.json';
    public const ATTRIBUTION = 'WSV, PEGELONLINE';
    public const CACHE_SECONDS = 900;
    public const FAIL_SECONDS = 600;
    public const MAX_MEASUREMENT_AGE_SECONDS = 6 * 3600;
    /** Germany with its border rivers; nothing is asked outside. */
    private const COVERAGE = ['south' => 47.2, 'north' => 55.1, 'west' => 5.8, 'east' => 15.1];
    private const CELL_DEGREES = 0.05;

    /** @var \Closure(): int */
    private \Closure $now;

    public function __construct(
        private readonly TideHttp $http,
        private readonly TideCache $cache,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * @return array{station: string, water: string, distance_m: int, value_cm: float, time: int, mhw: ?string, hsw: ?string}|null
     *         mhw = 'low'|'normal'|'high', hsw = 'normal'|'high', null = the WSV gives no classification
     */
    public function nearest(float $lat, float $lng, int $maxMeters): ?array
    {
        if ($lat < self::COVERAGE['south'] || $lat > self::COVERAGE['north'] || $lng < self::COVERAGE['west'] || $lng > self::COVERAGE['east']) {
            return null;
        }
        $best = null;
        foreach ($this->stations($lat, $lng, $maxMeters) as $station) {
            $distance = (int) round(GeometryService::distanceMeters($lat, $lng, $station['lat'], $station['lng']));
            if ($distance <= $maxMeters && ($best === null || $distance < $best['distance_m'])) {
                $best = ['station' => $station['station'], 'water' => $station['water'], 'distance_m' => $distance,
                    'value_cm' => $station['value_cm'], 'time' => $station['time'], 'mhw' => $station['mhw'], 'hsw' => $station['hsw']];
            }
        }

        return $best;
    }

    /** @return list<array{station: string, water: string, lat: float, lng: float, value_cm: float, time: int, mhw: ?string, hsw: ?string}> */
    private function stations(float $lat, float $lng, int $maxMeters): array
    {
        $cellLat = round($lat / self::CELL_DEGREES) * self::CELL_DEGREES;
        $cellLng = round($lng / self::CELL_DEGREES) * self::CELL_DEGREES;
        // the radius around the cell centre covers every point of the cell
        $radiusKm = (int) ceil($maxMeters / 1000 + 5);
        $file = sprintf('pegelonline-%.2F_%.2F_%d.json', $cellLat, $cellLng, $radiusKm);
        $now = ($this->now)();

        $cached = $this->cache->read($file);
        if (isset($cached['failed']) && $now - (int) $cached['failed'] < self::FAIL_SECONDS) {
            return [];
        }
        if (isset($cached['fetched'], $cached['stations']) && $now - (int) $cached['fetched'] < self::CACHE_SECONDS) {
            return $this->fresh($cached['stations'], $now);
        }

        $body = $this->http->get(self::BASE_URL . '?' . http_build_query([
            'latitude' => sprintf('%.4F', $cellLat),
            'longitude' => sprintf('%.4F', $cellLng),
            'radius' => $radiusKm,
            'includeTimeseries' => 'true',
            'includeCurrentMeasurement' => 'true',
            'timeseries' => 'W',
        ]));
        $decoded = $body !== null ? json_decode($body, true) : null;
        if (!is_array($decoded)) {
            error_log('YTAN: PEGELONLINE stations near ' . $cellLat . ',' . $cellLng . ' could not be loaded');
            $this->cache->write($file, ['failed' => $now]);

            return [];
        }

        $stations = [];
        foreach ($decoded as $row) {
            $parsed = is_array($row) ? self::parse($row) : null;
            if ($parsed !== null) {
                $stations[] = $parsed;
            }
        }
        $this->cache->write($file, ['fetched' => $now, 'stations' => $stations]);

        return $this->fresh($stations, $now);
    }

    /** @return array{station: string, water: string, lat: float, lng: float, value_cm: float, time: int, mhw: ?string, hsw: ?string}|null */
    private static function parse(array $row): ?array
    {
        if (!is_numeric($row['latitude'] ?? null) || !is_numeric($row['longitude'] ?? null)) {
            return null;
        }
        foreach ((array) ($row['timeseries'] ?? []) as $series) {
            $current = is_array($series) && ($series['shortname'] ?? null) === 'W' ? ($series['currentMeasurement'] ?? null) : null;
            if (!is_array($current) || !is_numeric($current['value'] ?? null)) {
                continue;
            }
            $time = strtotime((string) ($current['timestamp'] ?? ''));
            if ($time === false) {
                continue;
            }
            $mhw = $current['stateMnwMhw'] ?? null;
            $hsw = $current['stateNswHsw'] ?? null;

            return [
                'station' => self::name((string) ($row['longname'] ?? $row['shortname'] ?? '')),
                'water' => self::name((string) ($row['water']['longname'] ?? $row['water']['shortname'] ?? '')),
                'lat' => (float) $row['latitude'],
                'lng' => (float) $row['longitude'],
                'value_cm' => (float) $current['value'],
                'time' => $time,
                'mhw' => in_array($mhw, ['low', 'normal', 'high'], true) ? $mhw : null,
                'hsw' => in_array($hsw, ['normal', 'high'], true) ? $hsw : null,
            ];
        }

        return null;
    }

    /** @param list<array<string, mixed>> $stations */
    private function fresh(array $stations, int $now): array
    {
        return array_values(array_filter($stations, static fn (array $s) => $now - (int) $s['time'] <= self::MAX_MEASUREMENT_AGE_SECONDS));
    }

    /** PEGELONLINE writes names in capitals ("NEU DARCHAU", "ELBE"). */
    private static function name(string $raw): string
    {
        return mb_convert_case(mb_strtolower(trim($raw)), MB_CASE_TITLE, 'UTF-8');
    }
}
