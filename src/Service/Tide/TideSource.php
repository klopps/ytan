<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * A source of tide predictions (Routencheck, tidal rivers): high and low
 * water times at the gauge or sea point that stands for a position.
 *
 * Decision 2026-11-14: national sources for Europe (BshTideSource for the
 * German coasts and estuaries, more countries later), Open-Meteo as the
 * fallback everywhere (OpenMeteoTideSource) - TideService asks them in that
 * order.
 */
interface TideSource
{
    /**
     * Tide data for a position, null = this source has nothing there.
     *
     * $coast is the nearest sea coastline point (WaterDistanceService::
     * nearestSea()) - a source that only knows the open sea asks there.
     * Times are unix seconds; 'mean_range_m' is the mean tidal range (high
     * minus low water), null when unknown; 'events' covers at least
     * $from..$to (plus the event before $from, so the phase at $from is
     * known) and may be empty. A gauge-based source takes only a gauge
     * within $maxStationMeters of the position.
     *
     * @param array{lat: float, lng: float}|null $coast
     * @return array{station: string, source: string, attribution: string, lat: float, lng: float, distance_m: int, mean_range_m: ?float, events: list<array{time: int, type: 'HW'|'NW', height_m: ?float}>}|null
     */
    public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array;
}
