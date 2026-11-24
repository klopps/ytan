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
final class WaterDistanceService implements ShoreSource
{
    public const MAX_METERS = 100000;
    private const METERS_PER_DEGREE = 111320.0;

    private ?WaterIndex $index = null;
    private bool $opened = false;
    /** @var array<string,mixed>|null water-index.json without "coverage", [] without one */
    private ?array $meta = null;
    /** @var list<array{ring: list<array{0:float, 1:float}>, box: array{south:float, west:float, north:float, east:float}}>|null null = everything covered */
    private ?array $coverage = null;
    private ?WaterCorrections $corrections = null;
    private bool $correctionsLoaded = false;

    /**
     * $correctionsPath: the admin's corrections (WaterCorrections), default
     * water-corrections.json next to the index; a missing file = none.
     */
    public function __construct(
        private readonly string $indexPath,
        private readonly ?string $metaPath = null,
        private readonly ?string $correctionsPath = null
    ) {
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

    /** @inheritDoc */
    public function segmentsInBox(float $south, float $west, float $north, float $east): array
    {
        $index = $this->index();
        if ($index === null) {
            return [];
        }
        $oriented = ($this->meta()['oriented'] ?? false) === true;
        $cell = $index->cellDegrees();
        $rowFrom = (int) floor($south / $cell + 1e-9);
        $rowTo = (int) floor($north / $cell + 1e-9);
        $columnFrom = (int) floor($west / $cell + 1e-9);
        $columnTo = (int) floor($east / $cell + 1e-9);
        // [index kind name => shore kind code]
        $kinds = [['sea', 0], ['river', 1]];
        if ($index->hasKind('canal')) {
            $kinds[] = ['canal', 2];
        }
        if ($oriented) {
            $kinds[] = ['lake', 0];
        }
        $result = [];
        $seen = [];
        foreach ($kinds as [$name, $code]) {
            $kind = (int) array_search($name, WaterIndex::KINDS, true);
            for ($row = $rowFrom; $row <= $rowTo; $row++) {
                foreach ($this->segments($index, $kind, $row, $columnFrom, $columnTo) as [$lat1, $lng1, $lat2, $lng2]) {
                    // a segment is stored in every cell its box touches
                    $key = $code . ':' . $lat1 . ':' . $lng1 . ':' . $lat2 . ':' . $lng2;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $result[] = [$code, $lat1, $lng1, $lat2, $lng2];
                }
            }
        }

        return $result;
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
        foreach (['sea', 'river', 'lake'] as $name) {
            $result[$name] = $this->nearest($index, (int) array_search($name, WaterIndex::KINDS, true), $lat, $lng)['meters'];
        }
        // "river" has always meant river or canal (POI distances, shelter filter)
        if ($index->hasKind('canal')) {
            $canal = $this->nearest($index, (int) array_search('canal', WaterIndex::KINDS, true), $lat, $lng)['meters'];
            if ($canal !== null && ($result['river'] === null || $canal < $result['river'])) {
                $result['river'] = $canal;
            }
        }

        return $result;
    }

    /**
     * The river or canal a position lies on (Routencheck: current): the
     * nearest centre line within $maxMeters, unless a sea coastline or lake
     * shore is nearer (then the position is on the sea/lake, e.g. at a river
     * mouth). 'bearing' is the direction of that centre line segment in
     * degrees (0 = north) - downstream for a river, since OpenStreetMap draws
     * rivers in flow direction and the index keeps the order of the points;
     * meaningless for a canal (no current).
     *
     * null = on neither, no index, outside its countries - or an index built
     * before canals had a kind of their own (canals there are stored as river
     * and would get a current), so nothing changes until the new index is in.
     *
     * @return array{kind: 'river'|'canal', bearing: float, distance: int}|null
     */
    public function flowAt(float $lat, float $lng, int $maxMeters = 150): ?array
    {
        $index = $this->index();
        if ($index === null || !$index->hasKind('canal') || !$this->covers($lat, $lng)) {
            return null;
        }
        $best = null;
        foreach (['river', 'canal'] as $name) {
            $found = $this->nearest($index, (int) array_search($name, WaterIndex::KINDS, true), $lat, $lng, $maxMeters);
            if ($found['meters'] !== null && $found['meters'] <= $maxMeters && ($best === null || $found['meters'] < $best['meters'])) {
                $best = $found + ['kind' => $name];
            }
        }
        if ($best === null) {
            return null;
        }
        foreach (['sea', 'lake'] as $name) {
            $other = $this->nearest($index, (int) array_search($name, WaterIndex::KINDS, true), $lat, $lng, $best['meters'])['meters'];
            if ($other !== null && $other < $best['meters']) {
                return null;
            }
        }
        [$lat1, $lng1, $lat2, $lng2] = $best['segment'];

        return [
            'kind' => $best['kind'],
            'bearing' => self::bearing($lat1, $lng1, $lat2, $lng2),
            'distance' => $best['meters'],
        ];
    }

    /**
     * The nearest point of the sea coastline within $maxMeters (Routencheck:
     * whether a river section is near a river mouth, where the tide may turn
     * the current; OpenStreetMap's coastline runs up the estuaries, e.g. the
     * Weser into Bremen, the Elbe to below Hamburg).
     *
     * null = none within reach, no index, outside its countries.
     *
     * @return array{distance: int, lat: float, lng: float}|null
     */
    public function nearestSea(float $lat, float $lng, int $maxMeters = self::MAX_METERS): ?array
    {
        $index = $this->index();
        if ($index === null || !$this->covers($lat, $lng)) {
            return null;
        }
        $found = $this->nearest($index, (int) array_search('sea', WaterIndex::KINDS, true), $lat, $lng, $maxMeters);
        if ($found['meters'] === null || $found['meters'] > $maxMeters) {
            return null;
        }

        return ['distance' => $found['meters'], 'lat' => $found['point'][0], 'lng' => $found['point'][1]];
    }

    /** Initial course from point 1 to point 2 in degrees (0 = north, 90 = east). */
    private static function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dl = deg2rad($lng2 - $lng1);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);

        return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
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
                        foreach ($this->segments($index, (int) $kind, $r, $c, $c) as [$lat1, $lng1, $lat2, $lng2]) {
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

    /**
     * Segments of a run of cells of one kind with the admin's corrections
     * applied - every read of the index goes through here.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private function segments(WaterIndex $index, int $kind, int $row, int $column0, int $column1): array
    {
        $segments = $index->segments($kind, $row, $column0, $column1);
        $corrections = $this->corrections();

        return $corrections === null
            ? $segments
            : $corrections->apply(WaterIndex::KINDS[$kind], $row, $column0, $column1, $index->cellDegrees(), $segments);
    }

    /** Reads the corrections file again on the next query (after the admin changed it). */
    public function reloadCorrections(): void
    {
        $this->correctionsLoaded = false;
        $this->corrections = null;
    }

    private function corrections(): ?WaterCorrections
    {
        if (!$this->correctionsLoaded) {
            $this->correctionsLoaded = true;
            $this->corrections = WaterCorrections::fromFile($this->correctionsPath ?? dirname($this->indexPath) . '/water-corrections.json');
        }

        return $this->corrections;
    }

    /**
     * Admin overlay (/admin/water): every segment of every kind in the box,
     * deduplicated, as [kind name, lat1, lng1, lat2, lng2, own] (own: 0 = in
     * the index as built, > 0 = the id of the correction whose line it is,
     * -1 = changed by a correction but not drawn by one, i.e. flipped) - corrected, or the
     * index as built ($raw). 'removed' = original segments a delete correction
     * takes away (drawn dashed), 'truncated' = more than $limit segments
     * (the lists then stop there - zoom in).
     *
     * @return array{segments: list<array{0: string, 1: float, 2: float, 3: float, 4: float, 5: int}>, removed: list<array{0: string, 1: float, 2: float, 3: float, 4: float}>, truncated: bool}
     */
    public function overlaySegments(float $south, float $west, float $north, float $east, bool $raw, int $limit): array
    {
        $result = ['segments' => [], 'removed' => [], 'truncated' => false];
        $index = $this->index();
        if ($index === null) {
            return $result;
        }
        $corrections = $raw ? null : $this->corrections();
        $cell = $index->cellDegrees();
        $rowFrom = (int) floor($south / $cell + 1e-9);
        $rowTo = (int) floor($north / $cell + 1e-9);
        $columnFrom = (int) floor($west / $cell + 1e-9);
        $columnTo = (int) floor($east / $cell + 1e-9);
        $seen = [];
        $seenRemoved = [];
        foreach (WaterIndex::KINDS as $kind => $name) {
            if (!$index->hasKind($name)) {
                continue;
            }
            for ($row = $rowFrom; $row <= $rowTo; $row++) {
                $original = $index->segments($kind, $row, $columnFrom, $columnTo);
                $segments = $corrections === null
                    ? $original
                    : $corrections->apply($name, $row, $columnFrom, $columnTo, $cell, $original);
                // a segment that is not in the index as built comes from a correction (added, or flipped)
                $inIndex = [];
                foreach ($original as $segment) {
                    $inIndex[implode(':', $segment)] = true;
                }
                foreach ($segments as $segment) {
                    $key = $kind . ':' . implode(':', $segment);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    if (count($result['segments']) >= $limit) {
                        $result['truncated'] = true;

                        return $result;
                    }
                    $seen[$key] = true;
                    $own = 0;
                    if (!isset($inIndex[implode(':', $segment)])) {
                        // the correction the line belongs to, -1 = changed (flipped) but not drawn by a correction
                        $own = $corrections?->sourceOf($name, $segment) ?? -1;
                    }
                    $result['segments'][] = [$name, ...$segment, $own];
                }
                if ($corrections === null) {
                    continue;
                }
                foreach ($original as $segment) {
                    $key = $kind . ':' . implode(':', $segment);
                    if (!isset($seenRemoved[$key]) && $corrections->removes($name, $segment)) {
                        $seenRemoved[$key] = true;
                        $result['removed'][] = [$name, ...$segment];
                    }
                }
            }
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

    /**
     * Nearest segment of one kind: its distance in metres (null = none within
     * MAX_METERS or $limit) and the segment itself [lat1, lng1, lat2, lng2].
     * A $limit stops the ring search early once nothing nearer can come.
     * 'point' is the nearest point on that segment [lat, lng].
     *
     * @return array{meters: ?int, segment: ?array{0: float, 1: float, 2: float, 3: float}, point: ?array{0: float, 1: float}}
     */
    private function nearest(WaterIndex $index, int $kind, float $lat, float $lng, int $limit = self::MAX_METERS): array
    {
        $cell = $index->cellDegrees();
        $row = (int) floor($lat / $cell + 1e-9);
        $column = (int) floor($lng / $cell + 1e-9);
        $scaleX = self::METERS_PER_DEGREE * cos(deg2rad($lat));

        $best = INF;
        $bestPoint = null;
        $bestSegment = null;
        for ($ring = 0; ; $ring++) {
            // Nothing in ring N is nearer than N-1 whole cells; a cell is
            // narrowest at the ring's latitude farthest from the equator.
            if ($ring > 0) {
                $poleward = min(89.0, abs($lat) + ($ring + 1) * $cell);
                $cellMeters = $cell * self::METERS_PER_DEGREE * min(1.0, cos(deg2rad($poleward)));
                $bound = ($ring - 1) * $cellMeters;
                if ($bound >= $best || $bound > $limit) {
                    break;
                }
            }
            $segments = [];
            for ($r = $row - $ring; $r <= $row + $ring; $r++) {
                if ($r === $row - $ring || $r === $row + $ring) {
                    array_push($segments, ...$this->segments($index, $kind, $r, $column - $ring, $column + $ring));
                } else {
                    array_push($segments, ...$this->segments($index, $kind, $r, $column - $ring, $column - $ring));
                    array_push($segments, ...$this->segments($index, $kind, $r, $column + $ring, $column + $ring));
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
                    $bestSegment = [$lat1, $lng1, $lat2, $lng2];
                }
            }
        }

        if ($bestPoint === null) {
            return ['meters' => null, 'segment' => null, 'point' => null];
        }
        $meters = self::haversine($lat, $lng, $bestPoint[0], $bestPoint[1]);

        return $meters > self::MAX_METERS
            ? ['meters' => null, 'segment' => null, 'point' => null]
            : ['meters' => (int) round($meters), 'segment' => $bestSegment, 'point' => $bestPoint];
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return \Ytan\Service\GeometryService::distanceMeters($lat1, $lng1, $lat2, $lng2);
    }
}
