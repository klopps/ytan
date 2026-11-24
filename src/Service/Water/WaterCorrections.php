<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

use Ytan\Service\LighthousePolygon;

/**
 * Hand-made corrections of the water index (admin page /admin/water), applied
 * while reading so they take effect at once in every user of the index
 * (autorouting, Routencheck, POI water distances, imports) and survive a
 * rebuild of the index: segments have no stable id (a rebuild simplifies them
 * anew), so a correction is a geometric operation, never a segment reference.
 *
 *  - delete: segments of the given kinds whose midpoint lies inside the polygon disappear
 *  - flip:   such segments are reversed (sea/lake: the water side changes; river: the flow direction)
 *  - add:    a new line of one kind; for sea/lake the drawing direction keeps the water on the right
 *  - replace: an existing line ('original', one kind) is taken out - every segment of that kind
 *            with BOTH ends within REPLACE_BUFFER_M of it - and 'points' (may be empty = the
 *            line is just deleted) put in; both ends, so a line joining it at a junction stays
 *
 * They apply in their order (id), so a later delete also removes an earlier
 * added line. Read from storage/water/water-corrections.json, which
 * WaterCorrectionFile writes from the water_correction table after every change.
 */
final class WaterCorrections
{
    public const TYPES = ['delete', 'flip', 'add', 'replace'];

    /** How far a segment's ends may lie from a replaced line's original and still count as part of it. */
    public const REPLACE_BUFFER_M = 10.0;

    private const METERS_PER_DEGREE = 111320.0;

    /**
     * @var list<array{
     *   type: string,
     *   kinds: list<string>,
     *   points: list<array{0: float, 1: float}>,
     *   box: array{south: float, west: float, north: float, east: float},
     *   segments: list<array{0: float, 1: float, 2: float, 3: float}>
     * }>
     */
    private array $operations = [];

    /** @param list<array{type?: mixed, kinds?: mixed, points?: mixed}> $corrections */
    public function __construct(array $corrections)
    {
        foreach ($corrections as $correction) {
            $operation = self::operation($correction);
            if ($operation !== null) {
                $this->operations[] = $operation;
            }
        }
    }

    /** null when the file is missing or unreadable (= no corrections). */
    public static function fromFile(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['corrections']) || !is_array($data['corrections'])) {
            error_log('YTAN: water corrections file is not valid: ' . $path);

            return null;
        }
        $corrections = new self(array_values($data['corrections']));

        return $corrections->isEmpty() ? null : $corrections;
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    /**
     * The segments of the cells (row, column0..column1) of one kind after the
     * corrections, like WaterIndex::segments() returns them: an added segment
     * comes once per cell of the run its bounding box touches.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $segments
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    public function apply(string $kind, int $row, int $column0, int $column1, float $cell, array $segments): array
    {
        $south = $row * $cell;
        $north = ($row + 1) * $cell;
        $west = $column0 * $cell;
        $east = ($column1 + 1) * $cell;
        foreach ($this->operations as $operation) {
            if (!in_array($kind, $operation['kinds'], true)) {
                continue;
            }
            $box = $operation['box'];
            if ($box['north'] < $south || $box['south'] > $north || $box['east'] < $west || $box['west'] > $east) {
                continue;
            }
            if ($operation['type'] === 'replace') {
                $segments = array_values(array_filter(
                    $segments,
                    static fn (array $s): bool => !self::alongOriginal($operation, $s)
                ));
            }
            if ($operation['type'] === 'add' || $operation['type'] === 'replace') {
                foreach ($operation['segments'] as $segment) {
                    [$lat1, $lng1, $lat2, $lng2] = $segment;
                    $r0 = (int) floor(min($lat1, $lat2) / $cell + 1e-9);
                    $r1 = (int) floor(max($lat1, $lat2) / $cell + 1e-9);
                    if ($row < $r0 || $row > $r1) {
                        continue;
                    }
                    $c0 = max($column0, (int) floor(min($lng1, $lng2) / $cell + 1e-9));
                    $c1 = min($column1, (int) floor(max($lng1, $lng2) / $cell + 1e-9));
                    for ($c = $c0; $c <= $c1; $c++) {
                        $segments[] = $segment;
                    }
                }
                continue;
            }
            $result = [];
            foreach ($segments as $segment) {
                [$lat1, $lng1, $lat2, $lng2] = $segment;
                if (!self::inside($operation, ($lat1 + $lat2) / 2, ($lng1 + $lng2) / 2)) {
                    $result[] = $segment;
                } elseif ($operation['type'] === 'flip') {
                    $result[] = [$lat2, $lng2, $lat1, $lng1];
                }
            }
            $segments = $result;
        }

        return $segments;
    }

    /**
     * Whether the segment would be removed by a delete correction (admin
     * overlay: the removed original lines are drawn dashed).
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $segment
     */
    public function removes(string $kind, array $segment): bool
    {
        $mid = [($segment[0] + $segment[2]) / 2, ($segment[1] + $segment[3]) / 2];
        foreach ($this->operations as $operation) {
            if (!in_array($kind, $operation['kinds'], true)) {
                continue;
            }
            if ($operation['type'] === 'delete' && self::inside($operation, $mid[0], $mid[1])) {
                return true;
            }
            if ($operation['type'] === 'replace' && self::alongOriginal($operation, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The correction (id) whose line a segment is part of - an added line or a
     * replaced line's new course; the latest one wins (a later flip reverses a
     * segment, so the reversed one counts too). null = none (a segment of the
     * index, possibly flipped). Admin overlay: editing or deleting an own line
     * changes that correction instead of stacking a new one on top.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $segment
     */
    public function sourceOf(string $kind, array $segment): ?int
    {
        [$lat1, $lng1, $lat2, $lng2] = $segment;
        for ($i = count($this->operations) - 1; $i >= 0; $i--) {
            $operation = $this->operations[$i];
            if ($operation['segments'] === [] || !in_array($kind, $operation['kinds'], true)) {
                continue;
            }
            foreach ($operation['segments'] as [$a, $b, $c, $d]) {
                if (($a === $lat1 && $b === $lng1 && $c === $lat2 && $d === $lng2)
                    || ($a === $lat2 && $b === $lng2 && $c === $lat1 && $d === $lng1)
                ) {
                    return $operation['id'] > 0 ? $operation['id'] : null;
                }
            }
        }

        return null;
    }

    /**
     * Whether both ends of the segment lie within REPLACE_BUFFER_M of the
     * replaced line's original (flat metric around the line - fine for metres).
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $segment
     */
    private static function alongOriginal(array $operation, array $segment): bool
    {
        $box = $operation['box'];
        foreach ([[$segment[0], $segment[1]], [$segment[2], $segment[3]]] as [$lat, $lng]) {
            if ($lat < $box['south'] || $lat > $box['north'] || $lng < $box['west'] || $lng > $box['east']) {
                return false;
            }
            if (!self::nearOriginal($operation, $lat, $lng)) {
                return false;
            }
        }

        return true;
    }

    private static function nearOriginal(array $operation, float $lat, float $lng): bool
    {
        $kx = $operation['kx'];
        $ky = self::METERS_PER_DEGREE;
        $limit = self::REPLACE_BUFFER_M;
        foreach ($operation['original'] as [$lat1, $lng1, $lat2, $lng2, $south, $west, $north, $east]) {
            if ($lat < $south || $lat > $north || $lng < $west || $lng > $east) {
                continue;
            }
            $ax = ($lng - $lng1) * $kx;
            $ay = ($lat - $lat1) * $ky;
            $dx = ($lng2 - $lng1) * $kx;
            $dy = ($lat2 - $lat1) * $ky;
            $length = $dx * $dx + $dy * $dy;
            $t = $length > 0 ? max(0.0, min(1.0, ($ax * $dx + $ay * $dy) / $length)) : 0.0;
            if (hypot($ax - $t * $dx, $ay - $t * $dy) <= $limit) {
                return true;
            }
        }

        return false;
    }

    /** @param array{box: array{south: float, west: float, north: float, east: float}, points: list<array{0: float, 1: float}>} $operation */
    private static function inside(array $operation, float $lat, float $lng): bool
    {
        $box = $operation['box'];

        return $lat >= $box['south'] && $lat <= $box['north'] && $lng >= $box['west'] && $lng <= $box['east']
            && LighthousePolygon::contains($operation['points'], $lat, $lng);
    }

    /** @param array<string, mixed> $correction */
    private static function operation(array $correction): ?array
    {
        $type = $correction['type'] ?? null;
        $kinds = $correction['kinds'] ?? null;
        $points = $correction['points'] ?? null;
        if (!in_array($type, self::TYPES, true) || !is_array($kinds) || !is_array($points)) {
            return null;
        }
        $kinds = array_values(array_intersect(WaterIndex::KINDS, array_map('strval', $kinds)));
        $vertices = self::vertices($points);
        $line = $type === 'add' || $type === 'replace';
        if ($kinds === [] || ($line && count($kinds) !== 1)) {
            return null;
        }
        if ($type === 'replace') {
            // the new line may be empty (= the old one is deleted), never a single point
            $original = self::vertices(is_array($correction['original'] ?? null) ? $correction['original'] : []);
            if (count($original) < 2 || count($vertices) === 1) {
                return null;
            }
        } elseif (count($vertices) < ($type === 'add' ? 2 : 3)) {
            return null;
        }
        $segments = [];
        if ($line) {
            for ($i = 1, $n = count($vertices); $i < $n; $i++) {
                $segments[] = [$vertices[$i - 1][0], $vertices[$i - 1][1], $vertices[$i][0], $vertices[$i][1]];
            }
        }
        $operation = [
            'id' => (int) ($correction['id'] ?? 0),
            'type' => $type,
            'kinds' => $kinds,
            'points' => $vertices,
            'box' => LighthousePolygon::boundingBox($vertices === [] ? [[0.0, 0.0]] : $vertices),
            'segments' => $segments,
        ];
        if ($type === 'replace') {
            $operation += self::originalLine($original);
            $all = array_merge($vertices, $original);
            $box = LighthousePolygon::boundingBox($all);
            $padLat = self::REPLACE_BUFFER_M / self::METERS_PER_DEGREE;
            $padLng = self::REPLACE_BUFFER_M / $operation['kx'];
            $operation['box'] = [
                'south' => $box['south'] - $padLat, 'west' => $box['west'] - $padLng,
                'north' => $box['north'] + $padLat, 'east' => $box['east'] + $padLng,
            ];
        }

        return $operation;
    }

    /**
     * The original of a replaced line as segments with their bounding box
     * grown by the buffer, and the metres per degree of longitude there.
     *
     * @param list<array{0: float, 1: float}> $original
     * @return array{original: list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>, kx: float}
     */
    private static function originalLine(array $original): array
    {
        $kx = self::METERS_PER_DEGREE * max(0.01, cos(deg2rad($original[0][0])));
        $padLat = self::REPLACE_BUFFER_M / self::METERS_PER_DEGREE;
        $padLng = self::REPLACE_BUFFER_M / $kx;
        $segments = [];
        for ($i = 1, $n = count($original); $i < $n; $i++) {
            [$lat1, $lng1] = $original[$i - 1];
            [$lat2, $lng2] = $original[$i];
            $segments[] = [
                $lat1, $lng1, $lat2, $lng2,
                min($lat1, $lat2) - $padLat, min($lng1, $lng2) - $padLng,
                max($lat1, $lat2) + $padLat, max($lng1, $lng2) + $padLng,
            ];
        }

        return ['original' => $segments, 'kx' => $kx];
    }

    /** @return list<array{0: float, 1: float}> */
    private static function vertices(array $points): array
    {
        $vertices = [];
        foreach ($points as $point) {
            if (is_array($point) && isset($point[0], $point[1]) && is_numeric($point[0]) && is_numeric($point[1])) {
                $vertices[] = [(float) $point[0], (float) $point[1]];
            }
        }

        return $vertices;
    }
}
