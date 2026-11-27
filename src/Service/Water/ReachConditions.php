<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

use Ytan\Service\Wind\WindField;

/**
 * What WaterReach::reachTimed() needs besides the water: the wind, the
 * river and tidal current and the rules of the speed model - as values and
 * closures, so the tests need no wind files, network or tide source.
 */
final class ReachConditions
{
    /**
     * @param \Closure(float, float, float, float): ?WindField $wind wind over an area (south, west, north, east) for the time span (WindSampler::field()); null = calm
     * @param (\Closure(float, float): ?array{tide: ?array<string, mixed>})|null $tidal whether a river position is tidal (TidalRiver::check() for the time span); null = never
     * @param float $slowdown km/h per m/s of headwind (BriefingRules headwind_slowdown); a tailwind helps the same way
     * @param float $riverKmh assumed river current (BriefingRules river_current_kmh or the panel's chips)
     * @param float $tidalKmh tidal current at half tide (BriefingRules tidal_current_kmh or the panel's chips)
     * @param int $tidalLagSeconds the current turns this long after high/low water at the gauge (BriefingRules tidal_lag_min)
     * @param float $riverReachM water cells within this distance of a river centre line flow with it (BriefingRules river_max_m)
     */
    public function __construct(
        public readonly \Closure $wind,
        public readonly ?\Closure $tidal,
        public readonly float $slowdown,
        public readonly float $riverKmh,
        public readonly float $tidalKmh,
        public readonly int $tidalLagSeconds,
        public readonly float $riverReachM,
    ) {
    }
}
