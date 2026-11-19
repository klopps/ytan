<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

use Ytan\Service\GeometryService;

/**
 * High and low water from Ifremer's MARS2D model run MANGA700 (Channel and Bay
 * of Biscay, ~700 m grid, hourly sea surface height `XE`, open data under the
 * Licence Ouverte / Etalab 2.0), read over OPeNDAP from tds1.ifremer.fr.
 * Unlike the gauge sources it gives the tide AT the position (the nearest wet
 * cell), including the parts of the estuaries the grid reaches.
 *
 * Compared with the own TICON-4 prediction on 2026-11-19 (done.md): Brest and
 * Saint-Malo within ±15 min, Cherbourg ~+10, but Le Havre, the Gironde,
 * Arcachon and the Seine 15-70 min late and the Loire -48..+21 min; the
 * ranges are ~35 % too small everywhere (Brest 2.83 m against 4.37 m), Nantes
 * has no wet cell at all. So it is only the last resort before Open-Meteo
 * (App.php: after TidesAtlas, in the Routencheck's list only - not in the
 * weather timeline, which would wait for it) and marked as a model with
 * approximate times like Open-Meteo (RouteBriefingBuilder::tideSummary()).
 *
 * Facts found while building it (2026-11-19, done.md): the grid is regular -
 * latitude 43.282791 + j x 0.0062984, longitude -5.725559 + i x 0.0091202,
 * 1386 x 1087 cells; XE is Int16 x 0.0015259255 m, -32768 = land/dry; the
 * time axis (seconds since 1900, hourly since 2006) has gaps, so indices are
 * never computed from a date - the last ~150 hours are read and the times come
 * back with the data. The forecast reaches ~3 days ahead (it stopped for some
 * days in November 2026, then resumed). One request takes ~2-6 s, so the
 * series of a cell is cached CACHE_SECONDS and a failure FAIL_SECONDS.
 */
final class IfremerMarsTideSource implements TideSource
{
    public const DATASET_URL = 'https://tds1.ifremer.fr/thredds/dodsC/MARC-MANGA700-L1_MARS2D-FOR_FULL_TIME_SERIE';
    public const ATTRIBUTION = 'Ifremer MARC (MARS2D)';
    public const CACHE_SECONDS = 6 * 3600;
    public const FAIL_SECONDS = 1800;
    private const LAT0 = 43.282790902924326;
    private const LNG0 = -5.725559424980528;
    private const DLAT = 0.0062984;
    private const DLNG = 0.0091202;
    private const NJ = 1386;
    private const NI = 1087;
    private const SCALE = 0.0015259255;
    private const FILL = -32768;
    /** seconds between 1900-01-01 and 1970-01-01 */
    private const EPOCH_1900 = 2208988800;
    /** hours read back from the end of the time axis (forecast ~75 h + yesterday and today) */
    private const HOURS = 150;
    /** cells around the position searched for a wet one: (2 x RADIUS + 1)^2 */
    private const RADIUS = 2;

    /** @var \Closure(): int */
    private \Closure $now;

    public function __construct(
        private readonly TideCache $cache,
        private readonly TideHttp $http,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
    {
        // the position itself first (a river mouth inside the grid), else its coast point
        foreach ([[$lat, $lng], $coast !== null ? [(float) $coast['lat'], (float) $coast['lng']] : null] as $at) {
            if ($at === null) {
                continue;
            }
            $series = $this->series($at[0], $at[1]);
            if ($series === null) {
                continue;
            }
            $distance = (int) round(GeometryService::distanceMeters($lat, $lng, $series['lat'], $series['lng']));
            if ($distance > $maxStationMeters) {
                continue;
            }
            $events = OpenMeteoTideSource::extremes($series['values']);
            if ($events === []) {
                continue;
            }

            return [
                'station' => sprintf('Ifremer %.3f, %.3f', $series['lat'], $series['lng']),
                'source' => 'ifremer',
                'attribution' => self::ATTRIBUTION,
                'lat' => $series['lat'],
                'lng' => $series['lng'],
                'distance_m' => $distance,
                'mean_range_m' => GaugeTideSource::meanRange($events),
                'events' => BshTideSource::window($events, $from, $to),
            ];
        }

        return null;
    }

    /** @return array{j: int, i: int}|null the grid cell of a position, null = outside the grid */
    public static function cell(float $lat, float $lng): ?array
    {
        $j = (int) round(($lat - self::LAT0) / self::DLAT);
        $i = (int) round(($lng - self::LNG0) / self::DLNG);
        if ($j < self::RADIUS || $i < self::RADIUS || $j >= self::NJ - self::RADIUS || $i >= self::NI - self::RADIUS) {
            return null;
        }

        return ['j' => $j, 'i' => $i];
    }

    /**
     * Hourly sea level of the wet cell nearest to the position.
     *
     * @return array{lat: float, lng: float, values: list<array{0: int, 1: float}>}|null
     */
    private function series(float $lat, float $lng): ?array
    {
        $cell = self::cell($lat, $lng);
        if ($cell === null) {
            return null;
        }
        $file = sprintf('ifremer-%d_%d.json', $cell['j'], $cell['i']);
        $now = ($this->now)();
        $cached = $this->cache->read($file);
        if (isset($cached['failed']) && $now - (int) $cached['failed'] < self::FAIL_SECONDS) {
            return null;
        }
        if (isset($cached['fetched']) && $now - (int) $cached['fetched'] < self::CACHE_SECONDS) {
            return $cached['series'] ?? null;
        }

        $series = $this->fetch($cell, $lat, $lng);
        if ($series === false) {
            error_log('YTAN: tide data Ifremer MARS2D cell ' . $cell['j'] . '/' . $cell['i'] . ' could not be loaded');
            $this->cache->write($file, ['failed' => $now]);

            return null;
        }
        $this->cache->write($file, ['fetched' => $now, 'series' => $series]);

        return $series;
    }

    /**
     * @param array{j: int, i: int} $cell
     * @return array{lat: float, lng: float, values: list<array{0: int, 1: float}>}|false|null false = request failed, null = only land around
     */
    private function fetch(array $cell, float $lat, float $lng): array|false|null
    {
        $count = $this->timeCount();
        if ($count === null) {
            return false;
        }
        $last = $count - 1;
        $first = max(0, $last - self::HOURS);
        $j0 = $cell['j'] - self::RADIUS;
        $i0 = $cell['i'] - self::RADIUS;
        $size = 2 * self::RADIUS + 1;
        $query = sprintf('XE[%d:1:%d][%d:1:%d][%d:1:%d]', $first, $last, $j0, $j0 + $size - 1, $i0, $i0 + $size - 1);
        $body = $this->http->get(self::DATASET_URL . '.ascii?' . rawurlencode($query));
        if ($body === null) {
            return false;
        }
        $parsed = self::parseAscii($body);
        if ($parsed === null) {
            return false;
        }

        // the wet cell (no fill value at any hour) nearest to the position
        $best = null;
        foreach ($parsed['values'] as $dj => $row) {
            foreach ($row as $di => $values) {
                if (in_array(self::FILL, $values, true)) {
                    continue;
                }
                $cellLat = self::LAT0 + ($j0 + $dj) * self::DLAT;
                $cellLng = self::LNG0 + ($i0 + $di) * self::DLNG;
                $distance = GeometryService::distanceMeters($lat, $lng, $cellLat, $cellLng);
                if ($best === null || $distance < $best['distance']) {
                    $best = ['distance' => $distance, 'lat' => $cellLat, 'lng' => $cellLng, 'values' => $values];
                }
            }
        }
        if ($best === null) {
            return null;
        }
        $series = [];
        foreach ($best['values'] as $t => $raw) {
            $series[] = [(int) round($parsed['times'][$t]) - self::EPOCH_1900, round($raw * self::SCALE, 3)];
        }

        return ['lat' => round($best['lat'], 5), 'lng' => round($best['lng'], 5), 'values' => $series];
    }

    /** Length of the time axis from the dataset's DDS, cached an hour. */
    private function timeCount(): ?int
    {
        $now = ($this->now)();
        $cached = $this->cache->read('ifremer-time.json');
        if (isset($cached['fetched'], $cached['count']) && $now - (int) $cached['fetched'] < 3600) {
            return (int) $cached['count'];
        }
        $dds = $this->http->get(self::DATASET_URL . '.dds');
        if ($dds === null || preg_match('/Float64 time\[time = (\d+)\]/', $dds, $m) !== 1) {
            return null;
        }
        $this->cache->write('ifremer-time.json', ['fetched' => $now, 'count' => (int) $m[1]]);

        return (int) $m[1];
    }

    /**
     * Parses the OPeNDAP ASCII answer of an XE[t][j][i] request: rows
     * "[t][j], v_i0, v_i1, ..." and the "XE.time[n]" list.
     *
     * @return array{values: array<int, array<int, list<int>>>, times: list<float>}|null values[j][i] = per hour
     */
    public static function parseAscii(string $body): ?array
    {
        $values = [];
        $times = null;
        $lines = preg_split('/\r?\n/', $body) ?: [];
        foreach ($lines as $n => $line) {
            if (preg_match('/^\[(\d+)\]\[(\d+)\],\s*(.+)$/', $line, $m) === 1) {
                $t = (int) $m[1];
                $j = (int) $m[2];
                foreach (array_map('trim', explode(',', $m[3])) as $i => $value) {
                    $values[$j][$i][$t] = (int) $value;
                }
            } elseif (str_starts_with($line, 'XE.time[') && isset($lines[$n + 1])) {
                $times = array_map(static fn ($v) => (float) trim($v), explode(',', $lines[$n + 1]));
            }
        }
        if ($values === [] || $times === null) {
            return null;
        }
        foreach ($values as $j => $row) {
            foreach ($row as $i => $series) {
                ksort($series);
                if (count($series) !== count($times)) {
                    return null;
                }
                $values[$j][$i] = array_values($series);
            }
        }

        return ['values' => $values, 'times' => $times];
    }
}
