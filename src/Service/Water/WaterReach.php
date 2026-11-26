<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

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
 * along it. Pure distance over water - no shore distance, wind or current.
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

    public function __construct(private readonly ShoreSource $source, private readonly Autorouter $router)
    {
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
