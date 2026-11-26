<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

/**
 * A local land/water grid around a centre, built from the water index's shore
 * lines (ShoreField): each cell centre is classified by the side of the nearest
 * shore within the probe radius, cells without a shore that near take the state
 * of their nearest classified neighbour (flood fill), and every cell a
 * river/canal line runs through is water - so a passage narrower than a cell
 * stays open when such a line is drawn through it.
 *
 * Shared by the autorouting (Autorouter::detourIn(), A* over it) and the etmal
 * reach lines (WaterReach, Dijkstra over it).
 */
final class WaterGrid
{
    private const METERS_PER_DEGREE = 111320.0;

    /**
     * @param list<int> $state 0 unknown, 1 water, 2 land
     * @param list<float> $near distance of the cell centre to the nearest shore (INF = none within the radius / river cell)
     * @param array<int, true> $riverOnly cells that are water only because of a river/canal line
     */
    private function __construct(
        public array $state,
        public array $near,
        public array $riverOnly,
        public readonly ShoreField $field,
        public readonly float $x0,
        public readonly float $y0,
        public readonly float $cell,
        public readonly int $cols,
        public readonly int $rows,
        /** false = not a single cell had a shore within the radius (the whole grid took the fallback) */
        public readonly bool $hasShore,
    ) {
    }

    /**
     * @param float $width  width of the area to cover (metres, without the margin)
     * @param float $height height of the area to cover (metres, without the margin)
     * @param float $margin added on every side (metres)
     * @param float $cell   wanted cell size (metres); grows when the grid would exceed $maxCells
     * @param float $radiusAtLeast the probe radius is max(2 cells, this)
     * @param \Closure(): ?bool $fallbackWater asked when no cell has a shore near: true = all water
     * @return self|null null when the cells would have to be larger than $maxCell
     */
    public static function build(
        ShoreSource $source,
        float $lat0,
        float $lng0,
        float $width,
        float $height,
        float $margin,
        float $cell,
        int $maxCells,
        float $maxCell,
        float $radiusAtLeast,
        \Closure $fallbackWater,
    ): ?self {
        $metersPerLng = self::METERS_PER_DEGREE * cos(deg2rad($lat0));
        $cols = (int) ceil(($width + 2 * $margin) / $cell);
        $rows = (int) ceil(($height + 2 * $margin) / $cell);
        if ($cols * $rows > $maxCells) {
            $cell = sqrt(($width + 2 * $margin) * ($height + 2 * $margin) / $maxCells);
            $cols = (int) ceil(($width + 2 * $margin) / $cell);
            $rows = (int) ceil(($height + 2 * $margin) / $cell);
        }
        if ($cell > $maxCell) {
            return null;
        }
        $radius = max(2 * $cell, $radiusAtLeast);
        $x0 = -($cols * $cell) / 2;
        $y0 = -($rows * $cell) / 2;

        $padLat = ($rows * $cell / 2 + $radius) / self::METERS_PER_DEGREE;
        $padLng = ($cols * $cell / 2 + $radius) / $metersPerLng;
        $segments = $source->segmentsInBox($lat0 - $padLat, $lng0 - $padLng, $lat0 + $padLat, $lng0 + $padLng);
        $field = new ShoreField($segments, $lat0, $lng0, $radius);

        $total = $cols * $rows;
        $state = array_fill(0, $total, 0);
        $near = array_fill(0, $total, INF);
        $queue = [];
        for ($r = 0; $r < $rows; $r++) {
            $y = $y0 + ($r + 0.5) * $cell;
            for ($c = 0; $c < $cols; $c++) {
                $probe = $field->probeXY($x0 + ($c + 0.5) * $cell, $y, $radius);
                if ($probe !== null) {
                    $index = $r * $cols + $c;
                    $state[$index] = $probe['water'] ? 1 : 2;
                    $near[$index] = $probe['distance'];
                    $queue[] = $index;
                }
            }
        }
        if ($queue === []) {
            $fill = $fallbackWater() === true ? 1 : 2;
            $state = array_fill(0, $total, $fill);
        } else {
            for ($head = 0; $head < count($queue); $head++) {
                $index = $queue[$head];
                $r = intdiv($index, $cols);
                $c = $index % $cols;
                foreach ([[0, 1], [0, -1], [1, 0], [-1, 0]] as [$dr, $dc]) {
                    $nr = $r + $dr;
                    $nc = $c + $dc;
                    if ($nr < 0 || $nr >= $rows || $nc < 0 || $nc >= $cols) {
                        continue;
                    }
                    $next = $nr * $cols + $nc;
                    if ($state[$next] === 0) {
                        $state[$next] = $state[$index];
                        $queue[] = $next;
                    }
                }
            }
        }
        // cells that are water only because of a river/canal line (not by the shore): a way through
        // them is put onto the line itself, see Autorouter::snapToRivers() and lineCost()
        $riverOnly = [];
        if ($field->hasRivers()) {
            $markWater = static function (int $r, int $c) use (&$state, &$near, &$riverOnly, $cols, $rows): void {
                if ($r >= 0 && $r < $rows && $c >= 0 && $c < $cols) {
                    $index = $r * $cols + $c;
                    if ($state[$index] !== 1) {
                        $riverOnly[$index] = true;
                    }
                    $state[$index] = 1;
                    $near[$index] = INF;
                }
            };
            for ($r = 0; $r < $rows; $r++) {
                $y = $y0 + ($r + 0.5) * $cell;
                for ($c = 0; $c < $cols; $c++) {
                    if ($field->onRiverXY($x0 + ($c + 0.5) * $cell, $y)) {
                        $markWater($r, $c);
                    }
                }
            }
            // Every cell a river/canal line runs through is water too: the buffer test above only
            // looks at cell centres, so a canal drawn through a passage narrower than a cell could
            // miss every centre (or leave only cells touching at a corner) depending on where the grid lies.
            foreach ($field->riverLinesXY() as [$ax, $ay, $bx, $by]) {
                $steps = max(1, (int) ceil(hypot($bx - $ax, $by - $ay) / ($cell / 4)));
                $previous = null;
                for ($s = 0; $s <= $steps; $s++) {
                    $t = $s / $steps;
                    $c = (int) floor(($ax + $t * ($bx - $ax) - $x0) / $cell);
                    $r = (int) floor(($ay + $t * ($by - $ay) - $y0) / $cell);
                    if ($previous !== null && $previous[0] !== $r && $previous[1] !== $c) {
                        // a step across a corner: a search without corner cutting would not pass, so open a side neighbour
                        $markWater($previous[0], $c);
                    }
                    $markWater($r, $c);
                    $previous = [$r, $c];
                }
            }
        }

        return new self($state, $near, $riverOnly, $field, $x0, $y0, $cell, $cols, $rows, $queue !== []);
    }

    /**
     * Douglas-Peucker on x/y points (metres).
     *
     * @param list<array{0: float, 1: float}> $xy
     * @return list<int> kept indexes, ascending
     */
    public static function douglasPeucker(array $xy, float $tolerance): array
    {
        $n = count($xy);
        if ($n <= 2) {
            return array_keys($xy);
        }
        $keep = [0 => true, $n - 1 => true];
        $stack = [[0, $n - 1]];
        while ($stack !== []) {
            [$a, $b] = array_pop($stack);
            $dx = $xy[$b][0] - $xy[$a][0];
            $dy = $xy[$b][1] - $xy[$a][1];
            $length = hypot($dx, $dy);
            $max = 0.0;
            $at = -1;
            for ($i = $a + 1; $i < $b; $i++) {
                $d = $length > 0.0
                    ? abs($dy * ($xy[$i][0] - $xy[$a][0]) - $dx * ($xy[$i][1] - $xy[$a][1])) / $length
                    : hypot($xy[$i][0] - $xy[$a][0], $xy[$i][1] - $xy[$a][1]);
                if ($d > $max) {
                    $max = $d;
                    $at = $i;
                }
            }
            if ($at >= 0 && $max > $tolerance) {
                $keep[$at] = true;
                $stack[] = [$a, $at];
                $stack[] = [$at, $b];
            }
        }
        $indexes = array_keys($keep);
        sort($indexes);

        return $indexes;
    }

    /** The cell of a position (clamped to the grid). */
    public function cellOf(float $lat, float $lng): int
    {
        $c = max(0, min($this->cols - 1, (int) floor(($this->field->x($lng) - $this->x0) / $this->cell)));
        $r = max(0, min($this->rows - 1, (int) floor(($this->field->y($lat) - $this->y0) / $this->cell)));

        return $r * $this->cols + $c;
    }

    /** x/y (metres from the centre) of a cell's centre. @return array{0: float, 1: float} */
    public function centreXY(int $index): array
    {
        return [$this->x0 + ($index % $this->cols + 0.5) * $this->cell, $this->y0 + (intdiv($index, $this->cols) + 0.5) * $this->cell];
    }
}
