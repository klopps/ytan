<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

use Ytan\Service\Ai\RouteRating;
use Ytan\Service\Tide\TideService;
use Ytan\Service\Wind\WindField;

/**
 * Etmal reach lines: how far a paddler gets over water from a start point.
 *
 * A land/water grid (WaterGrid) around the start, then Dijkstra over the water
 * cells with 16 neighbours (the 8 around plus the knight's moves - the path
 * length on the grid is then within ~3 % of the straight distance, with 8
 * neighbours up to ~8 %; a move needs every cell its straight line passes to
 * be water, so no corner is cut past land). The lines are iso-distance lines of
 * that field (marching squares) at the etmal and at intermediate steps; squares
 * touching land are left out, so a line ends at the shore instead of running
 * along it. reach() is the pure distance over water - no shore distance, wind
 * or current; reachTimed() gives paddling-time lines with wind and river/tidal
 * current for a start time.
 */
final class WaterReach
{
    /** Grid cells at most (memory and time); at 60 km the cells are ~250 m. */
    public const MAX_CELLS = 250000;
    private const MIN_CELL_M = 50.0;
    private const MAX_CELL_M = 400.0;

    /** Neighbour moves: [dr, dc, cells the straight line passes besides the target]. */
    private const MOVES = [
        [0, 1, []], [0, -1, []], [1, 0, []], [-1, 0, []],
        [1, 1, [[0, 1], [1, 0]]], [1, -1, [[0, -1], [1, 0]]], [-1, 1, [[0, 1], [-1, 0]]], [-1, -1, [[0, -1], [-1, 0]]],
        [1, 2, [[0, 1], [1, 1]]], [-1, 2, [[0, 1], [-1, 1]]], [1, -2, [[0, -1], [1, -1]]], [-1, -2, [[0, -1], [-1, -1]]],
        [2, 1, [[1, 0], [1, 1]]], [2, -1, [[1, 0], [1, -1]]], [-2, 1, [[-1, 0], [-1, 1]]], [-2, -1, [[-1, 0], [-1, -1]]],
    ];

    /**
     * The timed reach may cover more: a tailwind carries up to 1.5 x as far,
     * so a 60 km etmal needs a grid of ~200 km (measured 2026-11-27: ~330 m cells).
     */
    private const TIMED_MAX_CELLS = 400000;
    private const TIMED_MAX_CELL_M = 500.0;
    /** Computing time of one timed reach at most (seconds), then 'too_slow'. */
    public const TIMED_BUDGET_S = 25.0;
    /** The tidal current is looked up in steps of this many seconds. */
    private const TIDAL_SLOT_S = 600;
    /** One tide lookup per this many metres of river (per bucket), at most TIDAL_CHECKS of them, nearest to the start first. */
    private const TIDAL_BUCKET_M = 10000.0;
    private const TIDAL_CHECKS = 6;
    /** What a river current may add to the area of a timed reach at most (metres). */
    private const CURRENT_EXTRA_M = 10000.0;
    /** Slower over ground than this counts as standing still (a current against you as strong as your paddling). */
    private const MIN_GROUND_MS = 0.14;

    /** @var list<array{0: int, 1: int, 2: list<array{0: int, 1: int}>, 3: float, 4: float, 5: float}>|null the 32 moves of reachTimed(), see timedMoves() */
    private static ?array $timedMoves = null;

    public function __construct(private readonly ShoreSource $source, private readonly Autorouter $router)
    {
    }

    /**
     * Isochrones over water with wind and current (user decision 2026-11-27):
     * how far the paddler gets in $duration seconds from $start, paddling at
     * $speedKmh through the water.
     *
     * The wind changes the paddling speed - each m/s against the course costs
     * $conditions->slowdown km/h, a tailwind helps the same way (between
     * RouteRating::MIN_SPEED_FACTOR and MAX_SPEED_FACTOR of the speed) - and a
     * river or tidal current adds its component along the course. Both
     * change with the hour, so the search is a time-dependent Dijkstra
     * (earliest arrival): a move costs its length over the speed over ground
     * at the moment it starts, in the cell it starts from.
     *
     * Unlike reach(), visible cells cannot simply take the straight distance
     * (the speed is not the same everywhere), so the moves go up to three
     * cells far in 32 directions: the error of a grid path against the
     * straight line is ~1 % instead of ~3 % with the 16 directions.
     *
     * Lines: every full hour plus the duration itself (main).
     *
     * @return array{status: string, cell_m?: float, lines?: list<array{hours: float, main: bool, paths: list<list<array{lat: float, lng: float}>>}>, wind?: ?array<string, mixed>, current?: array<string, mixed>}
     *         status 'ok', 'on_land', 'not_covered', 'too_large' or 'too_slow'
     */
    public function reachTimed(float $lat, float $lng, int $start, int $duration, float $speedKmh, ReachConditions $conditions): array
    {
        $status = $this->router->launchStatus($lat, $lng);
        if ($status !== 'ok') {
            return ['status' => $status];
        }
        $deadline = microtime(true) + self::TIMED_BUDGET_S;
        $end = $start + $duration;
        $v0 = $speedKmh / 3.6;
        $k = $conditions->slowdown / 3.6; // m/s of speed per m/s of wind
        $currentMax = $this->riverNear($lat, $lng) ? max($conditions->riverKmh, $conditions->tidalKmh) / 3.6 : 0.0;

        // the wind for the largest area a tailwind could carry the paddler into,
        // then the area shrunk to what the strongest wind there really allows
        // A current only carries along a river, so it adds at most CURRENT_EXTRA_M to the
        // area (a full current on top of the radius made a 60 km etmal's grid too coarse);
        // a line on a long, fast river may then end at the edge of the grid.
        $currentExtra = min(self::CURRENT_EXTRA_M, $duration * $currentMax);
        $radius = $duration * $v0 * RouteRating::MAX_SPEED_FACTOR + $currentExtra;
        [$south, $west, $north, $east] = self::box($lat, $lng, $radius * 1.1 + 2000.0);
        $wind = ($conditions->wind)($south, $west, $north, $east);
        $paddleMax = $wind === null ? $v0 : min($v0 * RouteRating::MAX_SPEED_FACTOR, $v0 + $k * $wind->maxSpeed($start, $end));
        $radius = $duration * $paddleMax + $currentExtra;

        $grid = WaterGrid::build(
            $this->source, $lat, $lng, 2 * $radius, 2 * $radius, max(1000.0, 0.05 * $radius),
            self::MIN_CELL_M, self::TIMED_MAX_CELLS, self::TIMED_MAX_CELL_M, 0.0,
            fn (): ?bool => $this->router->waterAt($lat, $lng) !== false
        );
        if ($grid === null) {
            return ['status' => 'too_large'];
        }
        $startCell = $grid->cellOf($lat, $lng);
        $grid->state[$startCell] = 1;

        [$flowX, $flowY, $flowKind, $tides, $tidalWithoutTimes] = $this->flowCells($grid, $conditions);
        // the signed strength of each tide's current per slot: + ebb (downstream), - flood (upstream)
        $slots = intdiv($duration, self::TIDAL_SLOT_S) + 3;
        $tidalFactor = [];
        foreach ($tides as $t => $tide) {
            for ($s = 0; $s < $slots; $s++) {
                $phase = TideService::phase($tide['events'], $start + $s * self::TIDAL_SLOT_S, $conditions->tidalLagSeconds);
                $tidalFactor[$t][$s] = $phase === null ? 0.0 : ($phase['phase'] === 'ebb' ? 1.0 : -1.0) * $phase['strength'];
            }
        }

        $time = $this->times($grid, $startCell, (float) $duration, $start, $v0, $k, $wind, $flowX, $flowY, $flowKind, $tidalFactor,
            $conditions->riverKmh / 3.6, $conditions->tidalKmh / 3.6, $deadline);
        if ($time === null) {
            return ['status' => 'too_slow'];
        }

        $lines = [];
        $levels = [];
        // an hour line closer than half an hour to the main line would lie on it (labels overlapping)
        for ($h = 1; $h * 3600 < $duration - 1800; $h++) {
            $levels[] = (float) $h;
        }
        $levels[] = round($duration / 3600, 2);
        foreach ($levels as $i => $hours) {
            $level = $i === count($levels) - 1 ? (float) $duration : $hours * 3600.0;
            $lines[] = ['hours' => $hours, 'main' => $i === count($levels) - 1, 'paths' => $this->isolines($grid, $time, $level)];
        }

        return [
            'status' => 'ok',
            'cell_m' => round($grid->cell, 1),
            'lines' => $lines,
            'wind' => $wind === null ? null : $wind->summary($lat, $lng, $start, $end) + ['source' => $wind->source, 'model' => $wind->model],
            'current' => [
                'river' => in_array(0, $flowKind, true),
                'tidal' => $tides !== [],
                'tidal_without_times' => $tidalWithoutTimes,
                'tides' => array_map(static fn (array $tide): array => [
                    'station' => (string) ($tide['station'] ?? ''),
                    'source' => (string) ($tide['source'] ?? ''),
                    'attribution' => (string) ($tide['attribution'] ?? ''),
                ], $tides),
            ],
        ];
    }

    /** Whether a (non-canal) river runs within 3 km of a position. */
    private function riverNear(float $lat, float $lng): bool
    {
        [$south, $west, $north, $east] = self::box($lat, $lng, 3000.0);
        foreach ($this->source->segmentsInBox($south, $west, $north, $east) as $segment) {
            if ($segment[0] !== 0 && $segment[0] !== 2) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} south, west, north, east of a square of $half metres around a position */
    private static function box(float $lat, float $lng, float $half): array
    {
        $dLat = $half / 111320.0;
        $dLng = $half / (111320.0 * max(0.1, cos(deg2rad($lat))));

        return [$lat - $dLat, $lng - $dLng, $lat + $dLat, $lng + $dLng];
    }

    /**
     * The water cells that flow with a river: within the rules' river_max_m
     * of a river centre line (canals have no current), direction = the line's
     * drawing direction = downstream. Near the sea a river may be tidal
     * (TidalRiver, one lookup per TIDAL_BUCKET_M of river, the nearest
     * TIDAL_CHECKS to the start; every flowing cell takes the answer of the
     * nearest bucket that was looked up).
     *
     * @return array{0: array<int, float>, 1: array<int, float>, 2: array<int, int>, 3: list<array<string, mixed>>, 4: bool}
     *         flow x, flow y (unit vector) and kind per cell (0 river, n >= 1 tide n-1, -1 tidal without times = no current), the tides, whether a tidal river had no times
     */
    private function flowCells(WaterGrid $grid, ReachConditions $conditions): array
    {
        $flowX = [];
        $flowY = [];
        $flowKind = [];
        $lines = $grid->field->flowingRiverLinesXY();
        if ($lines === [] || ($conditions->riverKmh <= 0.0 && $conditions->tidalKmh <= 0.0)) {
            return [$flowX, $flowY, $flowKind, [], false];
        }
        $cell = $grid->cell;
        $reach = max($conditions->riverReachM, 0.75 * $cell);
        $stamp = (int) ceil($reach / $cell);
        foreach ($lines as [$ax, $ay, $bx, $by]) {
            $length = hypot($bx - $ax, $by - $ay);
            if ($length < 1.0) {
                continue;
            }
            $ux = ($bx - $ax) / $length;
            $uy = ($by - $ay) / $length;
            $steps = max(1, (int) ceil($length / ($cell / 2)));
            for ($s = 0; $s <= $steps; $s++) {
                $x = $ax + ($bx - $ax) * $s / $steps;
                $y = $ay + ($by - $ay) * $s / $steps;
                $c0 = (int) floor(($x - $grid->x0) / $cell);
                $r0 = (int) floor(($y - $grid->y0) / $cell);
                for ($r = $r0 - $stamp; $r <= $r0 + $stamp; $r++) {
                    if ($r < 0 || $r >= $grid->rows) {
                        continue;
                    }
                    for ($c = $c0 - $stamp; $c <= $c0 + $stamp; $c++) {
                        if ($c < 0 || $c >= $grid->cols) {
                            continue;
                        }
                        $index = $r * $grid->cols + $c;
                        if ($grid->state[$index] !== 1 || isset($flowKind[$index])) {
                            continue;
                        }
                        [$cx, $cy] = $grid->centreXY($index);
                        if (hypot($cx - $x, $cy - $y) > $reach) {
                            continue;
                        }
                        $flowX[$index] = $ux;
                        $flowY[$index] = $uy;
                        $flowKind[$index] = 0;
                    }
                }
            }
        }
        if ($flowKind === [] || $conditions->tidal === null) {
            return [$flowX, $flowY, $flowKind, [], false];
        }

        // tidal rivers: one lookup per bucket of river, nearest to the start first
        $buckets = [];
        foreach (array_keys($flowKind) as $index) {
            [$x, $y] = $grid->centreXY($index);
            $key = (int) floor($x / self::TIDAL_BUCKET_M) . ':' . (int) floor($y / self::TIDAL_BUCKET_M);
            if (!isset($buckets[$key])) {
                $buckets[$key] = [$x, $y, hypot($x, $y)];
            }
        }
        uasort($buckets, static fn (array $a, array $b): int => $a[2] <=> $b[2]);
        $checked = [];
        $tides = [];
        $tidalWithoutTimes = false;
        foreach (array_slice($buckets, 0, self::TIDAL_CHECKS) as [$x, $y]) {
            $result = ($conditions->tidal)($grid->field->lat($y), $grid->field->lng($x));
            $kind = 0;
            if ($result !== null) {
                $tide = $result['tide'];
                if ($tide === null) {
                    $kind = -1;
                    $tidalWithoutTimes = true;
                } else {
                    $kind = null;
                    foreach ($tides as $t => $known) {
                        if (($known['source'] ?? null) === ($tide['source'] ?? null) && ($known['station'] ?? null) === ($tide['station'] ?? null)) {
                            $kind = $t + 1;
                        }
                    }
                    if ($kind === null) {
                        $tides[] = $tide;
                        $kind = count($tides);
                    }
                }
            }
            $checked[] = [$x, $y, $kind];
        }
        if (array_filter($checked, static fn (array $b): bool => $b[2] !== 0) !== []) {
            foreach (array_keys($flowKind) as $index) {
                [$x, $y] = $grid->centreXY($index);
                $best = INF;
                foreach ($checked as [$bx, $by, $kind]) {
                    $d = ($bx - $x) ** 2 + ($by - $y) ** 2;
                    if ($d < $best) {
                        $best = $d;
                        $flowKind[$index] = $kind;
                    }
                }
            }
        }

        return [$flowX, $flowY, $flowKind, $tides, $tidalWithoutTimes];
    }

    /**
     * The time-dependent Dijkstra of reachTimed(): seconds from the start to
     * every water cell (INF = not reached); cells beyond $limit get a value
     * but are not expanded. Null = over the time budget.
     *
     * @param array<int, float> $flowX
     * @param array<int, float> $flowY
     * @param array<int, int> $flowKind
     * @param array<int, list<float>> $tidalFactor
     * @return list<float>|null
     */
    private function times(
        WaterGrid $grid,
        int $start,
        float $limit,
        int $startTime,
        float $v0,
        float $k,
        ?WindField $wind,
        array $flowX,
        array $flowY,
        array $flowKind,
        array $tidalFactor,
        float $riverMs,
        float $tidalMs,
        float $deadline,
    ): ?array {
        $cols = $grid->cols;
        $rows = $grid->rows;
        $state = $grid->state;
        $cell = $grid->cell;
        $vMin = $v0 * RouteRating::MIN_SPEED_FACTOR;
        $vMax = $v0 * RouteRating::MAX_SPEED_FACTOR;

        // the wind grid point of every row and column (the reach grid is much finer)
        $windU = [[0.0]];
        $windV = [[0.0]];
        $windRow = array_fill(0, $rows, 0);
        $windCol = array_fill(0, $cols, 0);
        $windCols = 1;
        $windHours = 1;
        $windOffset = 0;
        if ($wind !== null) {
            $windU = $wind->u;
            $windV = $wind->v;
            $windCols = $wind->cols;
            $windHours = $wind->hours();
            $windOffset = $startTime - $wind->t0;
            $field = $grid->field;
            for ($r = 0; $r < $rows; $r++) {
                $windRow[$r] = intdiv($wind->nearestIndex($field->lat($grid->y0 + ($r + 0.5) * $cell), $field->lng(0.0)), $windCols);
            }
            for ($c = 0; $c < $cols; $c++) {
                $windCol[$c] = $wind->nearestIndex($field->lat(0.0), $field->lng($grid->x0 + ($c + 0.5) * $cell)) % $windCols;
            }
        }

        $moves = [];
        foreach (self::timedMoves() as [$dr, $dc, $via, $unitX, $unitY, $length]) {
            $moves[] = [$dr, $dc, $via, $unitX, $unitY, $length * $cell];
        }
        $time = array_fill(0, $cols * $rows, INF);
        $time[$start] = 0.0;
        $done = [];
        $open = new \SplPriorityQueue();
        $open->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $open->insert($start, 0.0);
        $pops = 0;
        $tidalSlots = $tidalFactor === [] ? 0 : count(reset($tidalFactor));
        while (!$open->isEmpty()) {
            $current = $open->extract();
            if (isset($done[$current])) {
                continue;
            }
            $done[$current] = true;
            if ((++$pops & 4095) === 0 && microtime(true) > $deadline) {
                return null;
            }
            $t = $time[$current];
            if ($t > $limit) {
                continue;
            }
            $r = intdiv($current, $cols);
            $c = $current % $cols;
            $h = max(0, min($windHours - 1, intdiv((int) $t + $windOffset, 3600)));
            $w = $windRow[$r] * $windCols + $windCol[$c];
            $u = $windU[$h][$w];
            $v = $windV[$h][$w];
            $fx = 0.0;
            $fy = 0.0;
            if (isset($flowKind[$current])) {
                $kind = $flowKind[$current];
                $strength = $kind === 0 ? $riverMs
                    : ($kind > 0 ? $tidalMs * $tidalFactor[$kind - 1][min($tidalSlots - 1, intdiv((int) $t, self::TIDAL_SLOT_S))] : 0.0);
                $fx = $flowX[$current] * $strength;
                $fy = $flowY[$current] * $strength;
            }
            foreach ($moves as [$dr, $dc, $via, $unitX, $unitY, $length]) {
                $nr = $r + $dr;
                $nc = $c + $dc;
                if ($nr < 0 || $nr >= $rows || $nc < 0 || $nc >= $cols) {
                    continue;
                }
                $next = $nr * $cols + $nc;
                if ($state[$next] !== 1 || isset($done[$next])) {
                    continue;
                }
                foreach ($via as [$vr, $vc]) {
                    if ($state[($r + $vr) * $cols + $c + $vc] !== 1) {
                        continue 2;
                    }
                }
                // paddling speed: headwind slows, tailwind helps (RouteRating::paddleSpeed(), inlined for speed)
                $paddle = $v0 + $k * ($u * $unitX + $v * $unitY);
                $paddle = $paddle < $vMin ? $vMin : ($paddle > $vMax ? $vMax : $paddle);
                $ground = $paddle + $fx * $unitX + $fy * $unitY;
                $candidate = $t + $length / ($ground < self::MIN_GROUND_MS ? self::MIN_GROUND_MS : $ground);
                if ($candidate < $time[$next]) {
                    $time[$next] = $candidate;
                    $open->insert($next, -$candidate);
                }
            }
        }

        return $time;
    }

    /**
     * Moves up to three cells far in 32 directions - (1,0), (1,1), (2,1),
     * (3,1), (3,2) with every sign and swap - each with the cells its straight
     * line passes besides start and target (a cell it only touches at a corner
     * counts too, like reach()'s MOVES), its unit vector (x east, y north) and
     * its length in cells.
     *
     * @return list<array{0: int, 1: int, 2: list<array{0: int, 1: int}>, 3: float, 4: float, 5: float}>
     */
    private static function timedMoves(): array
    {
        if (self::$timedMoves !== null) {
            return self::$timedMoves;
        }
        $moves = [];
        $seen = [];
        foreach ([[1, 0], [1, 1], [2, 1], [3, 1], [3, 2]] as [$a, $b]) {
            foreach ([[$a, $b], [$b, $a]] as [$p, $q]) {
                foreach ([1, -1] as $sp) {
                    foreach ([1, -1] as $sq) {
                        $dr = $p * $sp;
                        $dc = $q * $sq;
                        if (isset($seen[$dr . ':' . $dc])) {
                            continue;
                        }
                        $seen[$dr . ':' . $dc] = true;
                        // every crossing of a cell border lies at a multiple of 1/12 of the
                        // way, so sampling at 1/24 steps finds every cell (both at a border)
                        $via = [];
                        for ($s = 1; $s < 24; $s++) {
                            $rows = self::roundBoth($dr * $s / 24);
                            $cols = self::roundBoth($dc * $s / 24);
                            foreach ($rows as $vr) {
                                foreach ($cols as $vc) {
                                    if (($vr !== 0 || $vc !== 0) && ($vr !== $dr || $vc !== $dc)) {
                                        $via[$vr . ':' . $vc] = [$vr, $vc];
                                    }
                                }
                            }
                        }
                        $length = hypot($dr, $dc);
                        $moves[] = [$dr, $dc, array_values($via), $dc / $length, $dr / $length, $length];
                    }
                }
            }
        }

        return self::$timedMoves = $moves;
    }

    /** The cell(s) a coordinate (in cells from a cell centre) lies in: both neighbours exactly on a border. @return list<int> */
    private static function roundBoth(float $value): array
    {
        $floor = (int) floor($value + 0.5);
        if (abs($value + 0.5 - round($value + 0.5)) < 1e-9) {
            return [$floor - 1, $floor];
        }

        return [$floor];
    }

    /** The distances of the lines: every 5 km (every 10 km above an etmal of 30 km), the etmal itself last. @return list<float> km */
    public static function levels(float $km): array
    {
        $step = $km <= 30.0 ? 5.0 : 10.0;
        $levels = [];
        for ($level = $step; $level < $km - 0.5; $level += $step) {
            $levels[] = $level;
        }
        $levels[] = $km;

        return $levels;
    }

    /**
     * @return array{status: string, etmal_km?: float, cell_m?: float, lines?: list<array{km: float, main: bool, paths: list<list<array{lat: float, lng: float}>>}>}
     *         status 'ok', 'on_land', 'not_covered' or 'too_large'
     */
    public function reach(float $lat, float $lng, float $km): array
    {
        $status = $this->router->launchStatus($lat, $lng);
        if ($status !== 'ok') {
            return ['status' => $status];
        }
        $radius = $km * 1000.0;
        $grid = WaterGrid::build(
            $this->source, $lat, $lng, 2 * $radius, 2 * $radius, max(1000.0, 0.05 * $radius),
            self::MIN_CELL_M, self::MAX_CELLS, self::MAX_CELL_M, 0.0,
            // no shore anywhere near = open sea (launchStatus() already said "ok" for the start)
            fn (): ?bool => $this->router->waterAt($lat, $lng) !== false
        );
        if ($grid === null) {
            return ['status' => 'too_large'];
        }
        $start = $grid->cellOf($lat, $lng);
        // the start may lie up to Autorouter::LAUNCH_TOLERANCE_M beside the water (beach, slipway)
        $grid->state[$start] = 1;

        $levels = self::levels($km);
        $distance = $this->distances($grid, $start, $radius + 3 * $grid->cell);
        $distance = $this->straightWhereVisible($grid, $start, $distance, $grid->field->x($lng), $grid->field->y($lat));
        $lines = [];
        foreach ($levels as $i => $level) {
            $paths = $this->isolines($grid, $distance, $level * 1000.0);
            $lines[] = ['km' => $level, 'main' => $i === count($levels) - 1, 'paths' => $paths];
        }

        return ['status' => 'ok', 'etmal_km' => $km, 'cell_m' => round($grid->cell, 1), 'lines' => $lines];
    }

    /**
     * Dijkstra from the start over water cells; cells beyond $limit are given a
     * value but not expanded. Unreached cells stay INF.
     *
     * @return list<float>
     */
    private function distances(WaterGrid $grid, int $start, float $limit): array
    {
        $cols = $grid->cols;
        $rows = $grid->rows;
        $state = $grid->state;
        $cell = $grid->cell;
        $dist = array_fill(0, $cols * $rows, INF);
        $dist[$start] = 0.0;
        $done = [];
        $open = new \SplPriorityQueue();
        $open->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $open->insert($start, 0.0);
        $moves = [];
        foreach (self::MOVES as [$dr, $dc, $via]) {
            $moves[] = [$dr, $dc, $via, hypot($dr, $dc) * $cell];
        }
        while (!$open->isEmpty()) {
            $current = $open->extract();
            if (isset($done[$current])) {
                continue;
            }
            $done[$current] = true;
            $d = $dist[$current];
            if ($d > $limit) {
                continue;
            }
            $r = intdiv($current, $cols);
            $c = $current % $cols;
            foreach ($moves as [$dr, $dc, $via, $length]) {
                $nr = $r + $dr;
                $nc = $c + $dc;
                if ($nr < 0 || $nr >= $rows || $nc < 0 || $nc >= $cols) {
                    continue;
                }
                $next = $nr * $cols + $nc;
                if ($state[$next] !== 1 || isset($done[$next])) {
                    continue;
                }
                foreach ($via as [$vr, $vc]) {
                    if ($state[($r + $vr) * $cols + $c + $vc] !== 1) {
                        continue 2;
                    }
                }
                $candidate = $d + $length;
                if ($candidate < $dist[$next]) {
                    $dist[$next] = $candidate;
                    $open->insert($next, -$candidate);
                }
            }
        }

        return $dist;
    }

    /**
     * The grid search alone draws a 16-gon instead of a circle (its distances
     * grow piecewise linear with the direction). Every water cell the start can
     * SEE over water gets the straight distance instead: visibility spreads
     * outward ring by ring (a raster viewshed) - a cell is visible when it is
     * water and the cell(s) one step back on the line to the start are visible
     * (both of them when that line runs between two cells, so it never slips
     * through a diagonal gap in the land). Cells in the shadow of land keep the
     * grid distance, which is longer anyway.
     *
     * @param list<float> $dist
     * @return list<float>
     */
    private function straightWhereVisible(WaterGrid $grid, int $start, array $dist, float $sx, float $sy): array
    {
        $cols = $grid->cols;
        $rows = $grid->rows;
        $state = $grid->state;
        $rs = intdiv($start, $cols);
        $cs = $start % $cols;
        $visible = [$start => true];
        $maxRing = max($rs, $rows - 1 - $rs, $cs, $cols - 1 - $cs);
        for ($ring = 1; $ring <= $maxRing; $ring++) {
            $any = false;
            for ($r = max(0, $rs - $ring); $r <= min($rows - 1, $rs + $ring); $r++) {
                $dy = $r - $rs;
                $edgeRow = abs($dy) === $ring;
                $step = $edgeRow ? 1 : 2 * $ring;
                for ($c = $cs - $ring; $c <= $cs + $ring; $c += $step) {
                    if ($c < 0 || $c >= $cols) {
                        continue;
                    }
                    $index = $r * $cols + $c;
                    if ($state[$index] !== 1 || !is_finite($dist[$index])) {
                        continue;
                    }
                    $dx = $c - $cs;
                    if (abs($dx) >= abs($dy)) {
                        // one column back towards the start; the line crosses it between two rows
                        $backC = $c - ($dx <=> 0);
                        $rowAt = $rs + $dy * (abs($dx) - 1) / abs($dx);
                        $backs = [[(int) floor($rowAt), $backC], [(int) ceil($rowAt), $backC]];
                    } else {
                        $backR = $r - ($dy <=> 0);
                        $colAt = $cs + $dx * (abs($dy) - 1) / abs($dy);
                        $backs = [[$backR, (int) floor($colAt)], [$backR, (int) ceil($colAt)]];
                    }
                    $seen = true;
                    foreach ($backs as [$br, $bc]) {
                        if (!isset($visible[$br * $cols + $bc])) {
                            $seen = false;
                            break;
                        }
                    }
                    if (!$seen) {
                        continue;
                    }
                    $visible[$index] = true;
                    $any = true;
                    [$x, $y] = $grid->centreXY($index);
                    $straight = hypot($x - $sx, $y - $sy);
                    if ($straight < $dist[$index]) {
                        $dist[$index] = $straight;
                    }
                }
            }
            if (!$any) {
                break;
            }
        }

        return $dist;
    }

    /**
     * Marching squares over the cell centres at one distance; squares with a
     * land corner are skipped. The segments are joined into polylines (closed
     * ones repeat their first point) and simplified to a fifth of a cell.
     *
     * @param list<float> $dist
     * @return list<list<array{lat: float, lng: float}>>
     */
    private function isolines(WaterGrid $grid, array $dist, float $level): array
    {
        $cols = $grid->cols;
        $rows = $grid->rows;
        $state = $grid->state;
        $cell = $grid->cell;
        // edge keys: 'h' r c = between centre (r, c) and (r, c+1); 'v' r c = between (r, c) and (r+1, c)
        $points = [];
        $links = [];
        $point = static function (string $key, int $ia, int $ib, float $t) use (&$points, $grid): void {
            if (!isset($points[$key])) {
                [$ax, $ay] = $grid->centreXY($ia);
                [$bx, $by] = $grid->centreXY($ib);
                $points[$key] = [$ax + $t * ($bx - $ax), $ay + $t * ($by - $ay)];
            }
        };
        $cross = static function (float $a, float $b) use ($level): float {
            if (is_infinite($a) || is_infinite($b)) {
                return 0.5;
            }

            return $b === $a ? 0.5 : max(0.0, min(1.0, ($level - $a) / ($b - $a)));
        };
        for ($r = 0; $r < $rows - 1; $r++) {
            for ($c = 0; $c < $cols - 1; $c++) {
                $i0 = $r * $cols + $c;          // bottom left (r, c)
                $i1 = $i0 + 1;                  // bottom right (r, c+1)
                $i2 = $i0 + $cols + 1;          // top right (r+1, c+1)
                $i3 = $i0 + $cols;              // top left (r+1, c)
                if ($state[$i0] !== 1 || $state[$i1] !== 1 || $state[$i2] !== 1 || $state[$i3] !== 1) {
                    continue;
                }
                $d0 = $dist[$i0];
                $d1 = $dist[$i1];
                $d2 = $dist[$i2];
                $d3 = $dist[$i3];
                $mask = ($d0 < $level ? 1 : 0) | ($d1 < $level ? 2 : 0) | ($d2 < $level ? 4 : 0) | ($d3 < $level ? 8 : 0);
                if ($mask === 0 || $mask === 15) {
                    continue;
                }
                // the four edges of the square
                $edges = [
                    'b' => ['h' . $r . '_' . $c, $i0, $i1, $d0, $d1],
                    'r' => ['v' . $r . '_' . ($c + 1), $i1, $i2, $d1, $d2],
                    't' => ['h' . ($r + 1) . '_' . $c, $i3, $i2, $d3, $d2],
                    'l' => ['v' . $r . '_' . $c, $i0, $i3, $d0, $d3],
                ];
                $pairs = match ($mask) {
                    1, 14 => [['l', 'b']],
                    2, 13 => [['b', 'r']],
                    3, 12 => [['l', 'r']],
                    4, 11 => [['r', 't']],
                    6, 9 => [['b', 't']],
                    7, 8 => [['l', 't']],
                    5, 10 => (($d0 + $d1 + $d2 + $d3) / 4 < $level) === ($mask === 5)
                        ? [['l', 't'], ['b', 'r']]
                        : [['l', 'b'], ['r', 't']],
                };
                foreach ($pairs as [$ea, $eb]) {
                    foreach ([$ea, $eb] as $e) {
                        [$key, $ia, $ib, $da, $db] = $edges[$e];
                        $point($key, $ia, $ib, $cross($da, $db));
                    }
                    $links[$edges[$ea][0]][] = $edges[$eb][0];
                    $links[$edges[$eb][0]][] = $edges[$ea][0];
                }
            }
        }

        // join the segments into chains: start at loose ends first, then the closed rings
        $used = [];
        $chains = [];
        $walk = static function (string $from) use (&$links, &$used): array {
            $chain = [$from];
            $current = $from;
            while (true) {
                $next = null;
                foreach ($links[$current] ?? [] as $candidate) {
                    $edge = $current < $candidate ? $current . '|' . $candidate : $candidate . '|' . $current;
                    if (!isset($used[$edge])) {
                        $used[$edge] = true;
                        $next = $candidate;
                        break;
                    }
                }
                if ($next === null) {
                    return $chain;
                }
                $chain[] = $next;
                $current = $next;
                if ($next === $from) {
                    return $chain;
                }
            }
        };
        foreach ($links as $key => $neighbours) {
            if (count($neighbours) === 1) {
                $chain = $walk((string) $key);
                if (count($chain) > 1) {
                    $chains[] = $chain;
                }
            }
        }
        foreach (array_keys($links) as $key) {
            $chain = $walk((string) $key);
            if (count($chain) > 1) {
                $chains[] = $chain;
            }
        }

        $paths = [];
        $field = $grid->field;
        foreach ($chains as $chain) {
            $xy = array_map(static fn (string $key): array => $points[$key], $chain);
            if (count($xy) < 3 && hypot($xy[0][0] - $xy[count($xy) - 1][0], $xy[0][1] - $xy[count($xy) - 1][1]) < 2 * $cell) {
                continue; // a crumb at a shore
            }
            // a fifth of a cell: half a cell made a 5 km circle an 18-gon with visible corners
            $kept = WaterGrid::douglasPeucker($xy, $cell / 5);
            $paths[] = array_map(static fn (int $i): array => [
                'lat' => round($field->lat($xy[$i][1]), 6),
                'lng' => round($field->lng($xy[$i][0]), 6),
            ], $kept);
        }

        return $paths;
    }
}
