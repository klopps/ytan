<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

use Ytan\Service\GeometryService;

/**
 * Autorouting: moves the parts of a route that lead over land, or closer to
 * the shore than wanted, onto water.
 *
 * The water index holds shore LINES only (sea coastline, lake shore with the
 * water on the right, river/canal centre lines), so land and water are told
 * by the side of the nearest shore line (ShoreField). The route is sampled
 * every 25 m; runs of bad samples are replaced by a detour found with A* on a
 * local grid around them (land blocked, cells nearer to the shore than the
 * wanted distance only expensive - so a narrow passage is still used, with a
 * warning, instead of giving up), then straightened again. Everything else of
 * the route stays as drawn.
 *
 * Near the start and end the distance rule is off (a launch beach is always
 * near the shore), and they may lie up to LAUNCH_TOLERANCE_M beside known
 * water (beach, slipway, harbour wall) - only land touching the end counts
 * as such a launch, land with water between it and the end is gone around.
 * Every grid cell a river/canal line runs through is water, so a passage
 * narrower than a cell stays open when a canal line is drawn through it.
 */
final class Autorouter
{
    public const LAUNCH_TOLERANCE_M = 100.0;
    public const MAX_ROUTE_M = 300000.0;
    public const MAX_POINTS = 500;

    private const SAMPLE_M = 25.0;
    private const CHUNK_SAMPLES = 400;
    private const MAX_CELLS = 160000;
    private const MAX_CELL_M = 400.0;
    private const MERGE_GAP_M = 300.0;
    /** A way 85 % of the wanted distance from the shore counts as keeping it (the grid is only as exact as its cells). */
    private const CLEARANCE_TOLERANCE = 0.85;
    private const LOOK_AHEAD = 160;
    /** A detour may start this far (at most half the way to the next one) before the first bad sample, so it can leave the line on a tangent. */
    private const EXTEND_M = 1000.0;
    /** A way up to 115 % of the wanted maximum distance counts as keeping it. */
    private const FAR_TOLERANCE = 1.15;
    /** The farthest maximum distance to the shore anybody can ask for (metres). */
    public const FAR_MAX_M = 5000.0;
    private const METERS_PER_DEGREE = 111320.0;
    private const BOXES_DEGREES = [0.03, 0.1, 0.3];

    public function __construct(private readonly ShoreSource $source, private readonly float $budgetSeconds = 20.0)
    {
    }

    /** Length of a polyline [[lat, lng], ...] in metres. */
    public static function length(array $points): float
    {
        $length = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $length += GeometryService::distanceMeters($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
        }

        return $length;
    }

    /**
     * Whether start and end are usable: 'start_not_covered', 'end_not_covered'
     * (outside the index's countries / no index), 'start_on_land', 'end_on_land'.
     * Empty = fine.
     *
     * @param list<array{0: float, 1: float}> $points
     * @return list<string>
     */
    public function check(array $points): array
    {
        $problems = [];
        foreach (['start' => $points[0], 'end' => $points[count($points) - 1]] as $name => $point) {
            $status = $this->launchStatus($point[0], $point[1]);
            if ($status !== 'ok') {
                $problems[] = $name . '_' . $status;
            }
        }

        return $problems;
    }

    /**
     * How many metres of the line lie over land (samples of 25 m, not counting
     * the launch tolerance at start and end) - cheap, no detour is searched.
     * Used to ask "put it on water?" before a route is saved.
     *
     * @param list<array{0: float, 1: float}> $points
     */
    public function landMeters(array $points): float
    {
        [$samples, $cum, , $total] = $this->densify($points);
        [$water] = $this->classify($samples, 50.0);
        $launch = $this->launchExempt($water, $cum, $total);
        $land = 0.0;
        foreach ($samples as $i => $_) {
            if (!$water[$i] && !$launch[$i]) {
                $land += self::SAMPLE_M;
            }
        }

        return $land;
    }

    /**
     * The land samples that belong to a launch: the unbroken run of land
     * samples starting at the first sample or ending at the last one, as far as
     * it lies within LAUNCH_TOLERANCE_M of that end (beach, slipway, harbour
     * wall). Land with water between it and the end is never exempt - otherwise
     * a dam crossed by a short segment between two waypoints on the water was
     * taken for "already on water" (found 2026-11-25).
     *
     * @param list<bool> $water
     * @param list<float> $cum
     * @return list<bool>
     */
    private function launchExempt(array $water, array $cum, float $total): array
    {
        $count = count($water);
        $exempt = array_fill(0, $count, false);
        for ($i = 0; $i < $count && !$water[$i] && $cum[$i] <= self::LAUNCH_TOLERANCE_M; $i++) {
            $exempt[$i] = true;
        }
        for ($i = $count - 1; $i >= 0 && !$water[$i] && $total - $cum[$i] <= self::LAUNCH_TOLERANCE_M; $i--) {
            $exempt[$i] = true;
        }

        return $exempt;
    }

    /**
     * @param list<array{0: float, 1: float}> $points [lat, lng], at least 2
     * @param float|null $shoreMeters wanted distance to the shore, null/0 = only keep off the land
     * @param float|null $maxMeters wanted largest distance to the shore (soft: the way is only made more expensive
     *                              the farther it is, so open water that cannot be avoided stays), null/0 = no limit;
     *                              ignored without $shoreMeters
     * @return array{points: list<array{0: float, 1: float}>, changed_sections: int, length_before: float, length_after: float, warnings: list<array<string, mixed>>}
     */
    public function route(array $points, ?float $shoreMeters, ?float $maxMeters = null): array
    {
        $started = microtime(true);
        $shoreOn = $shoreMeters !== null && $shoreMeters > 0.0;
        $shore = $shoreOn ? $shoreMeters : 0.0;
        $far = null;
        if ($shoreMeters !== null && $maxMeters !== null && $maxMeters > 0.0) {
            $far = min(self::FAR_MAX_M, max($maxMeters, $shore + 50.0, 200.0));
        }
        $lengthBefore = self::length($points);
        $result = ['points' => $points, 'changed_sections' => 0, 'length_before' => $lengthBefore, 'length_after' => $lengthBefore, 'warnings' => []];

        [$samples, $cum, $vertexCum, $total] = $this->densify($points);
        [$water, $distance] = $this->classify($samples, max($shore, 50.0));
        $tooFar = $far === null ? [] : $this->farSamples($samples, $far);

        $count = count($samples);
        $bad = array_fill(0, $count, false);
        $farOnly = array_fill(0, $count, false);
        $clearanceExempt = max(200.0, $shore);
        $launch = $this->launchExempt($water, $cum, $total);
        for ($i = 0; $i < $count; $i++) {
            $fromEnd = min($cum[$i], $total - $cum[$i]);
            if (!$water[$i]) {
                $bad[$i] = !$launch[$i];
            } elseif ($shoreOn && $distance[$i] !== null && $distance[$i] < $shore * self::CLEARANCE_TOLERANCE && $fromEnd > $clearanceExempt) {
                $bad[$i] = true;
            } elseif ($far !== null && $tooFar[$i] && $fromEnd > $clearanceExempt) {
                $bad[$i] = true;
                $farOnly[$i] = true;
            }
        }

        // Runs of bad samples; runs with only a short good stretch between them
        // become one, or the way would leave the good bit and come back to it.
        $mergeGap = max(self::MERGE_GAP_M, 3.0 * $shore);
        $runs = [];
        for ($i = 0; $i < $count; $i++) {
            if (!$bad[$i]) {
                continue;
            }
            $last = count($runs) - 1;
            if ($last >= 0 && $cum[$i] - $cum[$runs[$last][1]] <= $mergeGap) {
                $runs[$last][1] = $i;
                $runs[$last][2] = $runs[$last][2] && $farOnly[$i];
            } else {
                $runs[] = [$i, $i, $farOnly[$i]];
            }
        }

        $ends = [$points[0], $points[count($points) - 1]];
        $replacements = [];
        $extend = (int) ceil(self::EXTEND_M / self::SAMPLE_M);
        foreach ($runs as $k => [$a, $b, $onlyFar]) {
            if (microtime(true) - $started > $this->budgetSeconds) {
                $result['warnings'][] = ['code' => 'truncated'];
                break;
            }
            $mid = $samples[intdiv($a + $b, 2)];
            if ($onlyFar && !$this->source->covers($mid[0], $mid[1])) {
                continue;
            }
            // Start the detour a little before the bad stretch and end it a little after, but not
            // into the neighbouring run's share: the way can then leave the line on a tangent
            // instead of being forced through the sample right at the edge of the bad part.
            $leftLimit = $k === 0 ? 0 : intdiv($runs[$k - 1][1] + $a, 2);
            $rightLimit = $k === count($runs) - 1 ? $count - 1 : intdiv($b + $runs[$k + 1][0], 2);
            $from = max($leftLimit, $a - 1 - $extend);
            $to = min($rightLimit, $b + 1 + $extend);
            $detour = $this->detour(array_slice($samples, $from, $to - $from + 1), $shore, $shoreOn, $ends, $far);
            if ($detour === null) {
                if (!$onlyFar) {
                    $result['warnings'][] = ['code' => 'no_path', 'lat' => round($samples[$a][0], 6), 'lng' => round($samples[$a][1], 6)];
                }
                continue;
            }
            if ($detour['relaxed']) {
                $result['warnings'][] = ['code' => 'clearance_relaxed', 'lat' => round($samples[$a][0], 6), 'lng' => round($samples[$a][1], 6)];
            }
            $replacements[] = ['from' => $cum[$from], 'to' => $cum[$to], 'points' => $detour['points']];
        }
        if ($replacements === []) {
            return $result;
        }

        // original vertices outside the replaced stretches + the detours
        $output = [];
        $k = 0;
        $vertices = count($points);
        foreach ($replacements as $replacement) {
            while ($k < $vertices && $vertexCum[$k] <= $replacement['from'] + 0.01) {
                $output[] = $points[$k++];
            }
            foreach ($replacement['points'] as $point) {
                $output[] = $point;
            }
            while ($k < $vertices && $vertexCum[$k] <= $replacement['to'] + 0.01) {
                $k++;
            }
        }
        while ($k < $vertices) {
            $output[] = $points[$k++];
        }
        $output = $this->withoutDuplicates($output);
        $output = $this->limitPoints($output);

        $result['points'] = $output;
        $result['changed_sections'] = count($replacements);
        $result['length_after'] = self::length($output);

        return $result;
    }

    /** 'ok', 'not_covered' or 'on_land' for a start/end position. */
    private function launchStatus(float $lat, float $lng): string
    {
        if (!$this->source->available() || !$this->source->covers($lat, $lng)) {
            return 'not_covered';
        }
        $water = $this->waterAt($lat, $lng);
        if ($water !== false) {
            // true, or null = no shore, river or lake within the widest box (open sea
            // far from any coast: the index has no side to tell, and a kayak route
            // there can only be on water)
            return 'ok';
        }
        $field = $this->fieldAround($lat, $lng, self::BOXES_DEGREES[0], 2500.0);
        if ($field !== null) {
            $metersPerLng = self::METERS_PER_DEGREE * cos(deg2rad($lat));
            foreach ([25.0, 50.0, 75.0, self::LAUNCH_TOLERANCE_M] as $radius) {
                for ($angle = 0; $angle < 360; $angle += 45) {
                    $pLat = $lat + $radius * cos(deg2rad($angle)) / self::METERS_PER_DEGREE;
                    $pLng = $lng + $radius * sin(deg2rad($angle)) / $metersPerLng;
                    $probe = $field->probe($pLat, $pLng, 2500.0);
                    if ($field->onRiver($pLat, $pLng) || ($probe !== null && $probe['water'])) {
                        return 'ok';
                    }
                }
            }
        }

        return 'on_land';
    }

    /** Water at a position, from the nearest shore within a few kilometres (wider boxes step by step); null = no shore found. */
    private function waterAt(float $lat, float $lng): ?bool
    {
        foreach (self::BOXES_DEGREES as $half) {
            $radius = $half * self::METERS_PER_DEGREE * 0.9;
            $field = $this->fieldAround($lat, $lng, $half, $radius);
            if ($field === null) {
                continue;
            }
            if ($field->onRiver($lat, $lng)) {
                return true;
            }
            $probe = $field->probe($lat, $lng, $radius);
            if ($probe !== null) {
                return $probe['water'];
            }
        }

        return null;
    }

    private function fieldAround(float $lat, float $lng, float $halfDegrees, float $bucket): ?ShoreField
    {
        $halfLng = $halfDegrees / max(0.05, cos(deg2rad($lat)));
        $segments = $this->source->segmentsInBox($lat - $halfDegrees, $lng - $halfLng, $lat + $halfDegrees, $lng + $halfLng);

        return $segments === [] ? null : new ShoreField($segments, $lat, $lng, $bucket);
    }

    /**
     * Samples every SAMPLE_M along the polyline.
     *
     * @param list<array{0: float, 1: float}> $points
     * @return array{0: list<array{0: float, 1: float}>, 1: list<float>, 2: list<float>, 3: float} samples, their distance from the start, the vertices' distances, total
     */
    private function densify(array $points): array
    {
        $samples = [];
        $cum = [];
        $vertexCum = [0.0];
        $along = 0.0;
        for ($k = 0; $k < count($points) - 1; $k++) {
            [$lat1, $lng1] = $points[$k];
            [$lat2, $lng2] = $points[$k + 1];
            $length = GeometryService::distanceMeters($lat1, $lng1, $lat2, $lng2);
            $steps = max(1, (int) ceil($length / self::SAMPLE_M));
            for ($s = 0; $s < $steps; $s++) {
                $t = $s / $steps;
                $samples[] = [$lat1 + $t * ($lat2 - $lat1), $lng1 + $t * ($lng2 - $lng1)];
                $cum[] = $along + $t * $length;
            }
            $along += $length;
            $vertexCum[] = $along;
        }
        $samples[] = $points[count($points) - 1];
        $cum[] = $along;

        return [$samples, $cum, $vertexCum, $along];
    }

    /**
     * Water or land and the distance to the shore for every sample (null =
     * not nearer than $radius). Where no shore is that near the state of the
     * sample before holds: a shore between two samples is always within
     * $radius of one of them.
     *
     * @param list<array{0: float, 1: float}> $samples
     * @return array{0: list<bool>, 1: list<?float>}
     */
    private function classify(array $samples, float $radius): array
    {
        $count = count($samples);
        $water = [];
        $distance = [];
        $carry = null;
        for ($start = 0; $start < $count; $start += self::CHUNK_SAMPLES) {
            $end = min($count, $start + self::CHUNK_SAMPLES);
            $lats = array_column(array_slice($samples, $start, $end - $start), 0);
            $lngs = array_column(array_slice($samples, $start, $end - $start), 1);
            $pad = ($radius + 200.0) / self::METERS_PER_DEGREE;
            $midLat = (min($lats) + max($lats)) / 2;
            $padLng = $pad / max(0.05, cos(deg2rad($midLat)));
            $segments = $this->source->segmentsInBox(min($lats) - $pad, min($lngs) - $padLng, max($lats) + $pad, max($lngs) + $padLng);
            $field = $segments === [] ? null : new ShoreField($segments, $midLat, (min($lngs) + max($lngs)) / 2, $radius);
            for ($i = $start; $i < $end; $i++) {
                [$lat, $lng] = $samples[$i];
                $probe = $field?->probe($lat, $lng, $radius);
                if ($probe !== null) {
                    $carry = $probe['water'];
                    $state = $probe['water'];
                    $d = $probe['distance'];
                } else {
                    $carry ??= $this->waterAt($lat, $lng);
                    $state = $carry ?? true; // no shore within the widest box = open water
                    $d = null;
                }
                if ($field !== null && $field->hasRivers() && $field->onRiver($lat, $lng)) {
                    $state = true;
                    $d = null;
                }
                $water[$i] = $state;
                $distance[$i] = $state ? $d : null;
            }
        }

        return [$water, $distance];
    }

    /**
     * A* from the first to the last of the given positions on a grid around
     * them. null = no way (or the area is too big for the grid).
     *
     * @param list<array{0: float, 1: float}> $path the original samples from the last good one to the next good one
     * @param list<array{0: float, 1: float}> $ends start and end of the whole route
     * @return array{points: list<array{0: float, 1: float}>, relaxed: bool}|null
     */
    private function detour(array $path, float $shore, bool $shoreOn, array $ends, ?float $far = null): ?array
    {
        $lats = array_column($path, 0);
        $lngs = array_column($path, 1);
        $metersPerLng = self::METERS_PER_DEGREE * cos(deg2rad((min($lats) + max($lats)) / 2));
        $extent = max((max($lngs) - min($lngs)) * $metersPerLng, (max($lats) - min($lats)) * self::METERS_PER_DEGREE);
        $base = max(2000.0, 0.5 * $extent);
        // a bigger corridor only when the small one has no way (a peninsula or island wider than it)
        foreach ([1.0, 2.0, 4.0] as $factor) {
            $result = $this->detourIn($path, $shore, $shoreOn, $ends, min(20000.0, $base * $factor), $far);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: float, 1: float}> $path
     * @param list<array{0: float, 1: float}> $ends
     * @return array{points: list<array{0: float, 1: float}>, relaxed: bool}|null
     */
    private function detourIn(array $path, float $shore, bool $shoreOn, array $ends, float $margin, ?float $far = null): ?array
    {
        $lats = array_column($path, 0);
        $lngs = array_column($path, 1);
        $lat0 = (min($lats) + max($lats)) / 2;
        $lng0 = (min($lngs) + max($lngs)) / 2;
        $metersPerLng = self::METERS_PER_DEGREE * cos(deg2rad($lat0));
        $width = (max($lngs) - min($lngs)) * $metersPerLng;
        $height = (max($lats) - min($lats)) * self::METERS_PER_DEGREE;

        $cell = max(25.0, $shore / 4);
        $cols = (int) ceil(($width + 2 * $margin) / $cell);
        $rows = (int) ceil(($height + 2 * $margin) / $cell);
        if ($cols * $rows > self::MAX_CELLS) {
            $cell = sqrt(($width + 2 * $margin) * ($height + 2 * $margin) / self::MAX_CELLS);
            $cols = (int) ceil(($width + 2 * $margin) / $cell);
            $rows = (int) ceil(($height + 2 * $margin) / $cell);
        }
        if ($cell > self::MAX_CELL_M) {
            return null;
        }
        $radius = max(2 * $cell, $shore);
        $x0 = -($cols * $cell) / 2;
        $y0 = -($rows * $cell) / 2;

        $padLat = ($rows * $cell / 2 + $radius) / self::METERS_PER_DEGREE;
        $padLng = ($cols * $cell / 2 + $radius) / $metersPerLng;
        $segments = $this->source->segmentsInBox($lat0 - $padLat, $lng0 - $padLng, $lat0 + $padLat, $lng0 + $padLng);
        $field = new ShoreField($segments, $lat0, $lng0, $radius);

        // 0 unknown, 1 water, 2 land
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
            $fill = $this->waterAt($lat0, $lng0) === true ? 1 : 2;
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
        // them is put onto the line itself, see snapToRivers() and lineCost()
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
                        // a step across a corner: A* does not cut corners past land, so open a side neighbour
                        $markWater($previous[0], $c);
                    }
                    $markWater($r, $c);
                    $previous = [$r, $c];
                }
            }
        }

        $cellOf = function (float $lat, float $lng) use ($field, $x0, $y0, $cell, $cols, $rows): int {
            $c = max(0, min($cols - 1, (int) floor(($field->x($lng) - $x0) / $cell)));
            $r = max(0, min($rows - 1, (int) floor(($field->y($lat) - $y0) / $cell)));

            return $r * $cols + $c;
        };
        $startCell = $cellOf($path[0][0], $path[0][1]);
        $endCell = $cellOf($path[count($path) - 1][0], $path[count($path) - 1][1]);
        $state[$startCell] = 1;
        $state[$endCell] = 1;

        // Cells near the shore cost more: the wanted distance, and always a little
        // (1.5 cells) so a way does not skim the coast cell by cell.
        // Beyond the wanted maximum distance the way gets up to twice as expensive (soft:
        // open water that cannot be avoided is still crossed on the shortest line).
        $tight = 1.5 * $cell;
        $reach = null;
        if ($far !== null && $queue !== []) {
            $reach = $this->shoreReach($state, $near, $cols, $rows, $cell);
        }
        $penalty = static function (int $index) use ($near, $shore, $shoreOn, $tight, $reach, $far): float {
            $cost = 1.0;
            if ($shoreOn && $near[$index] < $shore) {
                $cost += 4.0 * (1.0 - $near[$index] / $shore);
            }
            if ($near[$index] < $tight) {
                $cost += 2.0 * (1.0 - $near[$index] / $tight);
            }
            if ($reach !== null && $reach[$index] > $far) {
                $cost += min(1.0, ($reach[$index] - $far) / (0.5 * $far));
            }

            return $cost;
        };

        $cells = $this->search($state, $penalty, $cols, $rows, $cell, $startCell, $endCell);
        if ($cells === null) {
            return null;
        }

        // relaxed = a cell of the way is nearer than half the wanted distance (outside the launch zones)
        $relaxed = false;
        if ($shoreOn) {
            $exempt = max(200.0, $shore);
            foreach ($cells as $index) {
                if ($near[$index] >= $shore * 0.5) {
                    continue;
                }
                $lat = $field->lat($y0 + (intdiv($index, $cols) + 0.5) * $cell);
                $lng = $field->lng($x0 + ($index % $cols + 0.5) * $cell);
                $nearEnd = false;
                foreach ($ends as $end) {
                    $nearEnd = $nearEnd || GeometryService::distanceMeters($lat, $lng, $end[0], $end[1]) <= $exempt;
                }
                $relaxed = $relaxed || !$nearEnd;
            }
        }

        $xy = [];
        foreach ($cells as $index) {
            $xy[] = [$x0 + ($index % $cols + 0.5) * $cell, $y0 + (intdiv($index, $cols) + 0.5) * $cell, $index];
        }
        // the way starts and ends exactly at the given positions, not at the centres of their cells
        // (a centre past the end point made the last leg run backwards)
        $last = count($xy) - 1;
        $xy[0] = [$field->x($path[0][1]), $field->y($path[0][0]), $xy[0][2]];
        $xy[$last] = [$field->x($path[count($path) - 1][1]), $field->y($path[count($path) - 1][0]), $xy[$last][2]];
        if ($riverOnly !== []) {
            $xy = $this->snapToRivers($xy, $riverOnly, $field, $cols, $rows, $cell, $x0, $y0);
        }
        $xy = $this->straighten($xy, $state, $near, $penalty, $cols, $rows, $cell, $x0, $y0, $riverOnly, $field);
        $xy = $this->withoutBacktracking($xy, $state, $near, $penalty, $cols, $rows, $cell, $x0, $y0, $riverOnly, $field);

        $result = [[$path[0][0], $path[0][1]]];
        for ($i = 1; $i < count($xy) - 1; $i++) {
            $result[] = [$field->lat($xy[$i][1]), $field->lng($xy[$i][0])];
        }
        $result[] = [$path[count($path) - 1][0], $path[count($path) - 1][1]];

        return ['points' => $result, 'relaxed' => $relaxed];
    }

    /**
     * Whether each sample is farther from the shore than $far (with tolerance),
     * probed every few samples only (a big radius is expensive) and carried
     * over to the ones between.
     *
     * @param list<array{0: float, 1: float}> $samples
     * @return list<bool>
     */
    private function farSamples(array $samples, float $far): array
    {
        $count = count($samples);
        $radius = $far * self::FAR_TOLERANCE;
        $step = max(1, (int) floor(min(200.0, max(self::SAMPLE_M, $far / 5)) / self::SAMPLE_M));
        $out = array_fill(0, $count, false);
        $chunk = 300 * $step;
        for ($start = 0; $start < $count; $start += $chunk) {
            $end = min($count, $start + $chunk);
            $part = array_slice($samples, $start, $end - $start);
            $lats = array_column($part, 0);
            $lngs = array_column($part, 1);
            $midLat = (min($lats) + max($lats)) / 2;
            $pad = ($radius + 100.0) / self::METERS_PER_DEGREE;
            $padLng = $pad / max(0.05, cos(deg2rad($midLat)));
            $segments = $this->source->segmentsInBox(min($lats) - $pad, min($lngs) - $padLng, max($lats) + $pad, max($lngs) + $padLng);
            $field = $segments === [] ? null : new ShoreField($segments, $midLat, (min($lngs) + max($lngs)) / 2, $radius);
            for ($i = $start; $i < $end; $i += $step) {
                $isFar = $field === null || $field->probe($samples[$i][0], $samples[$i][1], $radius) === null;
                for ($k = $i; $k < min($end, $i + $step); $k++) {
                    $out[$k] = $isFar;
                }
            }
        }

        return $out;
    }

    /**
     * Rough distance of every cell to the nearest shore (metres, straight line
     * ignoring obstacles): land cells 0, probed water cells their distance,
     * the rest from a two-pass chamfer sweep.
     *
     * @param list<int> $state
     * @param list<float> $near
     * @return list<float>
     */
    private function shoreReach(array $state, array $near, int $cols, int $rows, float $cell): array
    {
        $d = [];
        $total = $cols * $rows;
        for ($i = 0; $i < $total; $i++) {
            $d[$i] = $state[$i] === 2 ? 0.0 : $near[$i];
        }
        $diag = $cell * M_SQRT2;
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $i = $r * $cols + $c;
                $v = $d[$i];
                if ($c > 0 && $d[$i - 1] + $cell < $v) {
                    $v = $d[$i - 1] + $cell;
                }
                if ($r > 0) {
                    if ($d[$i - $cols] + $cell < $v) {
                        $v = $d[$i - $cols] + $cell;
                    }
                    if ($c > 0 && $d[$i - $cols - 1] + $diag < $v) {
                        $v = $d[$i - $cols - 1] + $diag;
                    }
                    if ($c < $cols - 1 && $d[$i - $cols + 1] + $diag < $v) {
                        $v = $d[$i - $cols + 1] + $diag;
                    }
                }
                $d[$i] = $v;
            }
        }
        for ($r = $rows - 1; $r >= 0; $r--) {
            for ($c = $cols - 1; $c >= 0; $c--) {
                $i = $r * $cols + $c;
                $v = $d[$i];
                if ($c < $cols - 1 && $d[$i + 1] + $cell < $v) {
                    $v = $d[$i + 1] + $cell;
                }
                if ($r < $rows - 1) {
                    if ($d[$i + $cols] + $cell < $v) {
                        $v = $d[$i + $cols] + $cell;
                    }
                    if ($c < $cols - 1 && $d[$i + $cols + 1] + $diag < $v) {
                        $v = $d[$i + $cols + 1] + $diag;
                    }
                    if ($c > 0 && $d[$i + $cols - 1] + $diag < $v) {
                        $v = $d[$i + $cols - 1] + $diag;
                    }
                }
                $d[$i] = $v;
            }
        }

        return $d;
    }

    /**
     * A* over water cells, 8 neighbours (no corner cutting past land).
     *
     * @param list<int> $state
     * @return list<int>|null cell indexes from start to goal
     */
    private function search(array $state, \Closure $penalty, int $cols, int $rows, float $cell, int $start, int $goal): ?array
    {
        $gx = $goal % $cols;
        $gy = intdiv($goal, $cols);
        $g = [$start => 0.0];
        $came = [];
        $closed = [];
        $open = new \SplPriorityQueue();
        $open->insert($start, 0.0);
        $diagonal = $cell * M_SQRT2;
        while (!$open->isEmpty()) {
            $current = $open->extract();
            if ($current === $goal) {
                $path = [$current];
                while (isset($came[$current])) {
                    $current = $came[$current];
                    $path[] = $current;
                }

                return array_reverse($path);
            }
            if (isset($closed[$current])) {
                continue;
            }
            $closed[$current] = true;
            $r = intdiv($current, $cols);
            $c = $current % $cols;
            for ($dr = -1; $dr <= 1; $dr++) {
                for ($dc = -1; $dc <= 1; $dc++) {
                    if ($dr === 0 && $dc === 0) {
                        continue;
                    }
                    $nr = $r + $dr;
                    $nc = $c + $dc;
                    if ($nr < 0 || $nr >= $rows || $nc < 0 || $nc >= $cols) {
                        continue;
                    }
                    $next = $nr * $cols + $nc;
                    if ($state[$next] !== 1 || isset($closed[$next])) {
                        continue;
                    }
                    if ($dr !== 0 && $dc !== 0 && ($state[$r * $cols + $nc] !== 1 || $state[$nr * $cols + $c] !== 1)) {
                        continue;
                    }
                    $step = ($dr !== 0 && $dc !== 0 ? $diagonal : $cell) * ($penalty($current) + $penalty($next)) / 2;
                    $cost = $g[$current] + $step;
                    if (!isset($g[$next]) || $cost < $g[$next]) {
                        $g[$next] = $cost;
                        $came[$next] = $current;
                        $open->insert($next, -($cost + hypot(($nc - $gx) * $cell, ($nr - $gy) * $cell)));
                    }
                }
            }
        }

        return null;
    }

    /**
     * Puts the way's points in cells that are water only because of a
     * river/canal line onto the nearest point of that line: the cell centres lie
     * up to half a cell diagonal beside it, i.e. on the banks of a canal
     * narrower than a cell (reported 2026-11-25). Start and end stay.
     *
     * @param list<array{0: float, 1: float, 2: int}> $xy
     * @param array<int, true> $riverOnly
     * @return list<array{0: float, 1: float, 2: int}>
     */
    private function snapToRivers(array $xy, array $riverOnly, ShoreField $field, int $cols, int $rows, float $cell, float $x0, float $y0): array
    {
        for ($i = 1; $i < count($xy) - 1; $i++) {
            if (!isset($riverOnly[$xy[$i][2]])) {
                continue;
            }
            $point = $field->nearestRiverPointXY($xy[$i][0], $xy[$i][1]);
            if ($point === null) {
                continue;
            }
            $c = max(0, min($cols - 1, (int) floor(($point[0] - $x0) / $cell)));
            $r = max(0, min($rows - 1, (int) floor(($point[1] - $y0) / $cell)));
            $xy[$i] = [$point[0], $point[1], $r * $cols + $c];
        }

        return $xy;
    }

    /**
     * Drops the cell-centre zigzag: from each point the farthest later point
     * that can be reached in a straight line over water cells for no more than
     * the way along the cells costs.
     *
     * @param list<array{0: float, 1: float, 2: int}> $xy
     * @param list<int> $state
     * @return list<array{0: float, 1: float, 2: int}>
     */
    private function straighten(array $xy, array $state, array $near, \Closure $penalty, int $cols, int $rows, float $cell, float $x0, float $y0, array $riverOnly, ShoreField $field): array
    {
        $n = count($xy);
        if ($n <= 2) {
            return $xy;
        }
        $prefix = [0.0];
        for ($i = 1; $i < $n; $i++) {
            $prefix[$i] = $prefix[$i - 1] + hypot($xy[$i][0] - $xy[$i - 1][0], $xy[$i][1] - $xy[$i - 1][1])
                * ($penalty($xy[$i][2]) + $penalty($xy[$i - 1][2])) / 2;
        }
        $out = [$xy[0]];
        $i = 0;
        while ($i < $n - 1) {
            $next = $i + 1;
            for ($j = min($n - 1, $i + self::LOOK_AHEAD); $j > $i + 1; $j--) {
                $cost = $this->lineCost($xy[$i], $xy[$j], $state, $near, $penalty, $cols, $rows, $cell, $x0, $y0, $riverOnly, $field);
                if ($cost !== null && $cost <= ($prefix[$j] - $prefix[$i]) * 1.001) {
                    $next = $j;
                    break;
                }
            }
            $out[] = $xy[$next];
            $i = $next;
        }

        return $out;
    }

    /**
     * Removes points where the way turns back on itself (a spur: forward to a
     * point and backwards again) whenever the direct line past it is free.
     *
     * @param list<array{0: float, 1: float, 2: int}> $xy
     * @return list<array{0: float, 1: float, 2: int}>
     */
    private function withoutBacktracking(array $xy, array $state, array $near, \Closure $penalty, int $cols, int $rows, float $cell, float $x0, float $y0, array $riverOnly, ShoreField $field): array
    {
        $i = 1;
        while ($i < count($xy) - 1) {
            $dot = ($xy[$i][0] - $xy[$i - 1][0]) * ($xy[$i + 1][0] - $xy[$i][0]) + ($xy[$i][1] - $xy[$i - 1][1]) * ($xy[$i + 1][1] - $xy[$i][1]);
            if ($dot < 0.0 && $this->lineCost($xy[$i - 1], $xy[$i + 1], $state, $near, $penalty, $cols, $rows, $cell, $x0, $y0, $riverOnly, $field) !== null) {
                array_splice($xy, $i, 1);
                $i = max(1, $i - 1);
                continue;
            }
            $i++;
        }

        return $xy;
    }

    /**
     * Cost of the straight line between two cell centres, null when it touches
     * a non-water cell or a cell closer to the shore than one cell (the grid
     * does not know where inside such a cell the land is) - the cells of the
     * two ends themselves are exempt. In a cell that is water only because of a
     * river/canal line the line must stay within that line's buffer, or a
     * straightened way cut across the banks.
     *
     * @param array<int, true> $riverOnly
     */
    private function lineCost(array $a, array $b, array $state, array $near, \Closure $penalty, int $cols, int $rows, float $cell, float $x0, float $y0, array $riverOnly, ShoreField $field): ?float
    {
        $length = hypot($b[0] - $a[0], $b[1] - $a[1]);
        $steps = max(1, (int) ceil($length / ($cell / 2)));
        $sum = 0.0;
        for ($s = 0; $s <= $steps; $s++) {
            $t = $s / $steps;
            $px = $a[0] + $t * ($b[0] - $a[0]);
            $py = $a[1] + $t * ($b[1] - $a[1]);
            $c = (int) floor(($px - $x0) / $cell);
            $r = (int) floor(($py - $y0) / $cell);
            if ($c < 0 || $c >= $cols || $r < 0 || $r >= $rows) {
                return null;
            }
            $index = $r * $cols + $c;
            if ($state[$index] !== 1 || ($near[$index] < $cell && $index !== $a[2] && $index !== $b[2])) {
                return null;
            }
            if (isset($riverOnly[$index]) && $s > 0 && $s < $steps && !$field->onRiverXY($px, $py)) {
                return null;
            }
            $sum += $penalty($index);
        }

        return $length * $sum / ($steps + 1);
    }

    /** @param list<array{0: float, 1: float}> $points @return list<array{0: float, 1: float}> */
    private function withoutDuplicates(array $points): array
    {
        $out = [];
        foreach ($points as $point) {
            $last = $out === [] ? null : $out[count($out) - 1];
            if ($last === null || GeometryService::distanceMeters($last[0], $last[1], $point[0], $point[1]) >= 0.5) {
                $out[] = $point;
            }
        }

        return $out;
    }

    /** At most MAX_POINTS points: Douglas-Peucker with a growing tolerance. @param list<array{0: float, 1: float}> $points @return list<array{0: float, 1: float}> */
    private function limitPoints(array $points): array
    {
        if (count($points) <= self::MAX_POINTS) {
            return $points;
        }
        $lat0 = $points[0][0];
        $mx = self::METERS_PER_DEGREE * cos(deg2rad($lat0));
        $xy = array_map(fn (array $p): array => [($p[1] - $points[0][1]) * $mx, ($p[0] - $lat0) * self::METERS_PER_DEGREE], $points);
        foreach ([2.0, 5.0, 10.0, 20.0, 50.0, 100.0, 250.0, 500.0] as $tolerance) {
            $keep = $this->douglasPeucker($xy, $tolerance);
            if (count($keep) <= self::MAX_POINTS) {
                break;
            }
        }

        return array_map(fn (int $i): array => $points[$i], $keep);
    }

    /** @param list<array{0: float, 1: float}> $xy @return list<int> kept indexes */
    private function douglasPeucker(array $xy, float $tolerance): array
    {
        $n = count($xy);
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
}
