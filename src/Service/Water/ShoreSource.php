<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

/**
 * What the autorouting needs from the water index: the line segments of an
 * area. Implemented by WaterDistanceService; tests use an in-memory coast.
 */
interface ShoreSource
{
    /** Whether an index is there at all. */
    public function available(): bool;

    /** Whether the index has the water around this position (inside one of its countries). */
    public function covers(float $lat, float $lng): bool;

    /**
     * Segments of the area, each [kind, lat1, lng1, lat2, lng2]: kind 0 =
     * shore (sea coastline, lake shore - water on the RIGHT of the direction
     * of travel), 1 = river centre line, 2 = canal centre line. Lakes are left
     * out of an index whose rings are not oriented (no "oriented" in
     * water-index.json), since their side cannot be told.
     *
     * @return list<array{0: int, 1: float, 2: float, 3: float, 4: float}>
     */
    public function segmentsInBox(float $south, float $west, float $north, float $east): array;
}
