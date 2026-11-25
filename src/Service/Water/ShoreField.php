<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

/**
 * The shore of a small area in a flat metric frame (metres east/north of an
 * origin), for the autorouting: which side of the shore a position is on
 * (water on the right of every shore segment) and how far the shore is.
 *
 * Segments sit in square buckets of the size of the largest radius probed, so
 * a probe looks at 3 x 3 buckets only. Rivers and canals (centre lines only)
 * count as water within a fixed buffer around them.
 */
final class ShoreField
{
    public const RIVER_BUFFER_M = 40.0;
    public const CANAL_BUFFER_M = 15.0;
    private const METERS_PER_DEGREE = 111320.0;
    private const KEY = 1048576;

    private float $mx;
    /** @var list<array{0: float, 1: float, 2: float, 3: float}> */
    private array $shore = [];
    /** @var list<array{0: float, 1: float, 2: float, 3: float, 4: float}> ax, ay, bx, by, buffer */
    private array $rivers = [];
    /** @var array<int, list<int>> */
    private array $shoreBuckets = [];
    /** @var array<int, list<int>> */
    private array $riverBuckets = [];

    /**
     * @param list<array{0: int, 1: float, 2: float, 3: float, 4: float}> $segments see ShoreSource::segmentsInBox()
     * @param float $bucket metres, at least the radius given to probe()
     */
    public function __construct(array $segments, private readonly float $lat0, private readonly float $lng0, private readonly float $bucket)
    {
        $this->mx = self::METERS_PER_DEGREE * cos(deg2rad($lat0));
        foreach ($segments as [$kind, $lat1, $lng1, $lat2, $lng2]) {
            $ax = $this->x($lng1);
            $ay = $this->y($lat1);
            $bx = $this->x($lng2);
            $by = $this->y($lat2);
            if ($kind === 0) {
                $this->shore[] = [$ax, $ay, $bx, $by];
                $this->addToBuckets($this->shoreBuckets, count($this->shore) - 1, $ax, $ay, $bx, $by);
            } else {
                $this->rivers[] = [$ax, $ay, $bx, $by, $kind === 2 ? self::CANAL_BUFFER_M : self::RIVER_BUFFER_M];
                $this->addToBuckets($this->riverBuckets, count($this->rivers) - 1, $ax, $ay, $bx, $by);
            }
        }
    }

    public function x(float $lng): float
    {
        return ($lng - $this->lng0) * $this->mx;
    }

    public function y(float $lat): float
    {
        return ($lat - $this->lat0) * self::METERS_PER_DEGREE;
    }

    public function lat(float $y): float
    {
        return $this->lat0 + $y / self::METERS_PER_DEGREE;
    }

    public function lng(float $x): float
    {
        return $this->lng0 + $x / $this->mx;
    }

    public function hasShore(): bool
    {
        return $this->shore !== [];
    }

    public function hasRivers(): bool
    {
        return $this->rivers !== [];
    }

    /**
     * The nearest shore within $radius metres of the position: its distance
     * and whether the position is on the water side. null = no shore that
     * near (the position can be on either side - it is not told here).
     *
     * @return array{distance: float, water: bool}|null
     */
    public function probe(float $lat, float $lng, float $radius): ?array
    {
        return $this->probeXY($this->x($lng), $this->y($lat), $radius);
    }

    /** @return array{distance: float, water: bool}|null */
    public function probeXY(float $x, float $y, float $radius): ?array
    {
        $best = $radius * $radius;
        $bestIndex = null;
        $bestT = 0.0;
        $bx = (int) floor($x / $this->bucket);
        $by = (int) floor($y / $this->bucket);
        for ($i = $bx - 1; $i <= $bx + 1; $i++) {
            for ($j = $by - 1; $j <= $by + 1; $j++) {
                foreach ($this->shoreBuckets[$i * self::KEY + $j] ?? [] as $index) {
                    [$ax, $ay, $cx, $cy] = $this->shore[$index];
                    $dx = $cx - $ax;
                    $dy = $cy - $ay;
                    $length = $dx * $dx + $dy * $dy;
                    $t = $length > 0.0 ? max(0.0, min(1.0, (($x - $ax) * $dx + ($y - $ay) * $dy) / $length)) : 0.0;
                    $d2 = ($ax + $t * $dx - $x) ** 2 + ($ay + $t * $dy - $y) ** 2;
                    if ($d2 < $best) {
                        $best = $d2;
                        $bestIndex = $index;
                        $bestT = $t;
                    }
                }
            }
        }
        if ($bestIndex === null) {
            return null;
        }
        [$ax, $ay, $cx, $cy] = $this->shore[$bestIndex];
        $qx = $ax + $bestT * ($cx - $ax);
        $qy = $ay + $bestT * ($cy - $ay);
        $distance = sqrt($best);
        if ($distance < 0.01) {
            return ['distance' => 0.0, 'water' => true];
        }

        // Pseudo-normal: the mean of the right-hand normals of every segment
        // meeting at the nearest point - at a corner the plain normal of one
        // segment would put positions beside a convex corner on the wrong side.
        $nx = 0.0;
        $ny = 0.0;
        $seen = [];
        $qbx = (int) floor($qx / $this->bucket);
        $qby = (int) floor($qy / $this->bucket);
        for ($i = $qbx - 1; $i <= $qbx + 1; $i++) {
            for ($j = $qby - 1; $j <= $qby + 1; $j++) {
                foreach ($this->shoreBuckets[$i * self::KEY + $j] ?? [] as $index) {
                    if (isset($seen[$index])) {
                        continue;
                    }
                    $seen[$index] = true;
                    [$sx, $sy, $ex, $ey] = $this->shore[$index];
                    if (self::pointSegmentDistance($qx, $qy, $sx, $sy, $ex, $ey) > 2.0) {
                        continue;
                    }
                    $length = hypot($ex - $sx, $ey - $sy);
                    if ($length > 0.0) {
                        $nx += ($ey - $sy) / $length;
                        $ny += -($ex - $sx) / $length;
                    }
                }
            }
        }
        if (hypot($nx, $ny) < 1e-6) {
            $length = hypot($cx - $ax, $cy - $ay);
            $nx = $length > 0.0 ? ($cy - $ay) / $length : 0.0;
            $ny = $length > 0.0 ? -($cx - $ax) / $length : 0.0;
        }

        return ['distance' => $distance, 'water' => (($x - $qx) * $nx + ($y - $qy) * $ny) >= 0.0];
    }

    /**
     * The river and canal centre lines in x/y metres (without their buffer).
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public function riverLinesXY(): array
    {
        return array_map(static fn (array $r): array => [$r[0], $r[1], $r[2], $r[3]], $this->rivers);
    }

    /**
     * The nearest point on a river or canal centre line within the bucket size
     * of the position, null = none that near.
     *
     * @return array{0: float, 1: float}|null x, y
     */
    public function nearestRiverPointXY(float $x, float $y): ?array
    {
        $best = INF;
        $point = null;
        $bx = (int) floor($x / $this->bucket);
        $by = (int) floor($y / $this->bucket);
        for ($i = $bx - 1; $i <= $bx + 1; $i++) {
            for ($j = $by - 1; $j <= $by + 1; $j++) {
                foreach ($this->riverBuckets[$i * self::KEY + $j] ?? [] as $index) {
                    [$ax, $ay, $cx, $cy] = $this->rivers[$index];
                    $dx = $cx - $ax;
                    $dy = $cy - $ay;
                    $length = $dx * $dx + $dy * $dy;
                    $t = $length > 0.0 ? max(0.0, min(1.0, (($x - $ax) * $dx + ($y - $ay) * $dy) / $length)) : 0.0;
                    $px = $ax + $t * $dx;
                    $py = $ay + $t * $dy;
                    $d2 = ($px - $x) ** 2 + ($py - $y) ** 2;
                    if ($d2 < $best) {
                        $best = $d2;
                        $point = [$px, $py];
                    }
                }
            }
        }

        return $point;
    }

    /** Whether a river or canal centre line is near enough to count the position as water. */
    public function onRiver(float $lat, float $lng): bool
    {
        return $this->onRiverXY($this->x($lng), $this->y($lat));
    }

    public function onRiverXY(float $x, float $y): bool
    {
        $bx = (int) floor($x / $this->bucket);
        $by = (int) floor($y / $this->bucket);
        for ($i = $bx - 1; $i <= $bx + 1; $i++) {
            for ($j = $by - 1; $j <= $by + 1; $j++) {
                foreach ($this->riverBuckets[$i * self::KEY + $j] ?? [] as $index) {
                    [$ax, $ay, $cx, $cy, $buffer] = $this->rivers[$index];
                    if (self::pointSegmentDistance($x, $y, $ax, $ay, $cx, $cy) <= $buffer) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function pointSegmentDistance(float $x, float $y, float $ax, float $ay, float $bx, float $by): float
    {
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $length = $dx * $dx + $dy * $dy;
        $t = $length > 0.0 ? max(0.0, min(1.0, (($x - $ax) * $dx + ($y - $ay) * $dy) / $length)) : 0.0;

        return hypot($ax + $t * $dx - $x, $ay + $t * $dy - $y);
    }

    /** @param array<int, list<int>> $buckets */
    private function addToBuckets(array &$buckets, int $index, float $ax, float $ay, float $bx, float $by): void
    {
        $i0 = (int) floor(min($ax, $bx) / $this->bucket);
        $i1 = (int) floor(max($ax, $bx) / $this->bucket);
        $j0 = (int) floor(min($ay, $by) / $this->bucket);
        $j1 = (int) floor(max($ay, $by) / $this->bucket);
        for ($i = $i0; $i <= $i1; $i++) {
            for ($j = $j0; $j <= $j1; $j++) {
                $buckets[$i * self::KEY + $j][] = $index;
            }
        }
    }
}
