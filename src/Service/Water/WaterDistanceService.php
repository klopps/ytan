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
