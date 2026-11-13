<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

use Ytan\Service\LighthousePolygon;

/**
 * Distance in metres from a position to the nearest sea (coastline), river or
 * canal (centre line) and large lake (shore, at least 10 km²) - looked up in
 * the local water index (WaterIndex, built by tools/water/build_index.py), so
 * it takes milliseconds and works on the servers without any external request.
 *
 * Per kind the cells around the position are searched ring by ring until no
 * farther ring can hold anything nearer than the best segment found; the
 * nearest point of that segment is then measured on the sphere (haversine).
 * Farther than MAX_METERS = no value (null).
 *
 * The index only holds the water of its countries (water-index.json's
 * "countries"), so a position outside their borders ("coverage", Geofabrik's
 * extract polygons) gets no distances at all: the ring search would otherwise
 * measure to the nearest water that happens to be in the index (a harbour in
 * Germany got 21.5 km to the nearest Danish coast). A meta file without
 * "coverage" (older index, test fixture) counts as covering everything.
 */
final class WaterDistanceService
{
    public const MAX_METERS = 100000;

    private const EARTH_RADIUS = 6371008.8;
    private const METERS_PER_DEGREE = 111320.0;

    private ?WaterIndex $index = null;
    private bool $opened = false;
    /** @var array<string,mixed>|null water-index.json without "coverage", [] without one */
    private ?array $meta = null;
    /** @var list<array{ring: list<array{0:float, 1:float}>, box: array{south:float, west:float, north:float, east:float}}>|null null = everything covered */
    private ?array $coverage = null;

    public function __construct(private readonly string $indexPath, private readonly ?string $metaPath = null)
    {
    }

    /** Whether an index file is there (no index = every distance null). */
    public function available(): bool
    {
        return $this->index() !== null;
    }

    /** The index version (water-index.json's "version"), null without one. */
    public function version(): ?string
    {
        $meta = $this->meta();

        return isset($meta['version']) ? (string) $meta['version'] : null;
    }

    /** Whether the index has the water around this position (inside one of its countries). */
    public function covers(float $lat, float $lng): bool
    {
        $this->meta();
        if ($this->coverage === null) {
            return true;
        }
        foreach ($this->coverage as $area) {
            $box = $area['box'];
            if ($lat >= $box['south'] && $lat <= $box['north'] && $lng >= $box['west'] && $lng <= $box['east']
                && LighthousePolygon::contains($area['ring'], $lat, $lng)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function meta(): array
    {
        if ($this->meta === null) {
            $meta = $this->metaPath !== null && is_file($this->metaPath)
                ? json_decode((string) file_get_contents($this->metaPath), true)
                : null;
            $this->meta = is_array($meta) ? $meta : [];
            if (isset($this->meta['coverage']) && is_array($this->meta['coverage'])) {
                $this->coverage = [];
                foreach ($this->meta['coverage'] as $ring) {
                    $ring = array_map(static fn (array $point): array => [(float) $point[0], (float) $point[1]], $ring);
                    $this->coverage[] = ['ring' => $ring, 'box' => LighthousePolygon::boundingBox($ring)];
                }
            }
            unset($this->meta['coverage']);
        }

        return $this->meta;
    }

    /** @return array{sea:?int, river:?int, lake:?int} */
    public function distances(float $lat, float $lng): array
    {
        $result = ['sea' => null, 'river' => null, 'lake' => null];
        $index = $this->index();
        if ($index === null || !$this->covers($lat, $lng)) {
            return $result;
        }
        foreach (WaterIndex::KINDS as $kind => $name) {
            $result[$name] = $this->nearest($index, $kind, $lat, $lng);
        }

        return $result;
    }

    /**
     * Open water along a straight line: metres from the position in the
     * direction $bearing (degrees, 0 = north, 90 = east) to the first sea
     * coastline or lake shore (islands included - their rings are coastline
     * too), at most $maxMeters. Looking towards where the wind comes from this
     * is the fetch (Routencheck: shelter in the lee of land, waves); looking
     * downwind it tells whether the wind blows out to open water.
     *
     * Rivers are left out (the index has only their centre line). null = no
     * index or the position is outside its countries; $maxMeters = nothing
     * within reach. Flat geometry around the position - good enough within a
     * few tens of kilometres.
     */
    public function openWaterAlong(float $lat, float $lng, float $bearing, int $maxMeters = 30000): ?int
    {
        $index = $this->index();
        if ($index === null || !$this->covers($lat, $lng)) {
            return null;
        }
        $cell = $index->cellDegrees();
        $scaleX = self::METERS_PER_DEGREE * cos(deg2rad($lat));
        $dirX = sin(deg2rad($bearing));
        $dirY = cos(deg2rad($bearing));
        $endX = $dirX * $maxMeters;
        $endY = $dirY * $maxMeters;

        // Walk the line in quarter-cell steps and test the segments of the
        // cells around each step (3 x 3, so a cell the line only clips at a
        // corner is not skipped); stop once the best hit lies behind the
        // cells still to come.
        $cellMeters = $cell * self::METERS_PER_DEGREE * min(1.0, cos(deg2rad(min(89.0, abs($lat) + $cell))));
        $step = max(200.0, $cellMeters / 4);
        $seen = [];
        $best = INF;
        $kinds = [array_search('sea', WaterIndex::KINDS, true), array_search('lake', WaterIndex::KINDS, true)];
        for ($s = 0.0; $s <= $maxMeters + $step; $s += $step) {
            if ($best < $s - 2 * $cellMeters) {
                break;
            }
            $pLat = $lat + ($dirY * min($s, $maxMeters)) / self::METERS_PER_DEGREE;
            $pLng = $lng + ($dirX * min($s, $maxMeters)) / $scaleX;
            $row = (int) floor($pLat / $cell + 1e-9);
            $column = (int) floor($pLng / $cell + 1e-9);
            for ($r = $row - 1; $r <= $row + 1; $r++) {
                for ($c = $column - 1; $c <= $column + 1; $c++) {
                    if (isset($seen["$r:$c"])) {
                        continue;
                    }
                    $seen["$r:$c"] = true;
                    foreach ($kinds as $kind) {
                        foreach ($index->segments((int) $kind, $r, $c, $c) as [$lat1, $lng1, $lat2, $lng2]) {
                            $hit = self::rayHit(
                                $endX, $endY,
                                ($lng1 - $lng) * $scaleX, ($lat1 - $lat) * self::METERS_PER_DEGREE,
                                ($lng2 - $lng) * $scaleX, ($lat2 - $lat) * self::METERS_PER_DEGREE
                            );
                            if ($hit !== null && $hit < $best) {
                                $best = $hit;
                            }
                        }
                    }
                }
            }
        }

        return is_finite($best) ? (int) round($best) : $maxMeters;
    }

    /**
     * Where the line from (0,0) to (endX,endY) crosses the segment A-B: the
     * distance from (0,0) in metres, null when they don't cross. Hits closer
     * than 1 m count as the starting point lying on the shore and are ignored.
     */
    private static function rayHit(float $endX, float $endY, float $ax, float $ay, float $bx, float $by): ?float
    {
        $sx = $bx - $ax;
        $sy = $by - $ay;
        $denominator = $endX * $sy - $endY * $sx;
        if (abs($denominator) < 1e-9) {
            return null;
        }
        $t = ($ax * $sy - $ay * $sx) / $denominator;
        $u = ($ax * $endY - $ay * $endX) / $denominator;
        if ($t < 0.0 || $t > 1.0 || $u < 0.0 || $u > 1.0) {
            return null;
        }
        $distance = $t * hypot($endX, $endY);

        return $distance < 1.0 ? null : $distance;
    }

    private function index(): ?WaterIndex
    {
        if (!$this->opened) {
            $this->opened = true;
            $this->index = WaterIndex::open($this->indexPath);
            if ($this->index === null) {
                error_log('YTAN: water index not available at ' . $this->indexPath);
            }
        }

        return $this->index;
    }

    private function nearest(WaterIndex $index, int $kind, float $lat, float $lng): ?int
    {
        $cell = $index->cellDegrees();
        $row = (int) floor($lat / $cell + 1e-9);
        $column = (int) floor($lng / $cell + 1e-9);
        $scaleX = self::METERS_PER_DEGREE * cos(deg2rad($lat));

        $best = INF;
        $bestPoint = null;
        for ($ring = 0; ; $ring++) {
            // Nothing in ring N is nearer than N-1 whole cells; a cell is
            // narrowest at the ring's latitude farthest from the equator.
            if ($ring > 0) {
                $poleward = min(89.0, abs($lat) + ($ring + 1) * $cell);
                $cellMeters = $cell * self::METERS_PER_DEGREE * min(1.0, cos(deg2rad($poleward)));
                $bound = ($ring - 1) * $cellMeters;
                if ($bound >= $best || $bound > self::MAX_METERS) {
                    break;
                }
            }
            $segments = [];
            for ($r = $row - $ring; $r <= $row + $ring; $r++) {
                if ($r === $row - $ring || $r === $row + $ring) {
                    array_push($segments, ...$index->segments($kind, $r, $column - $ring, $column + $ring));
                } else {
                    array_push($segments, ...$index->segments($kind, $r, $column - $ring, $column - $ring));
                    array_push($segments, ...$index->segments($kind, $r, $column + $ring, $column + $ring));
                }
            }
            foreach ($segments as [$lat1, $lng1, $lat2, $lng2]) {
                $ax = ($lng1 - $lng) * $scaleX;
                $ay = ($lat1 - $lat) * self::METERS_PER_DEGREE;
                $dx = ($lng2 - $lng1) * $scaleX;
                $dy = ($lat2 - $lat1) * self::METERS_PER_DEGREE;
                $length = $dx * $dx + $dy * $dy;
                $t = $length > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $length)) : 0.0;
                $d = hypot($ax + $t * $dx, $ay + $t * $dy);
                if ($d < $best) {
                    $best = $d;
                    $bestPoint = [$lat1 + $t * ($lat2 - $lat1), $lng1 + $t * ($lng2 - $lng1)];
                }
            }
        }

        if ($bestPoint === null) {
            return null;
        }
        $meters = self::haversine($lat, $lng, $bestPoint[0], $bestPoint[1]);

        return $meters > self::MAX_METERS ? null : (int) round($meters);
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * self::EARTH_RADIUS * asin(min(1.0, sqrt($a)));
    }
}
