<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

use Ytan\Service\Water\TidelessSeas;

/**
 * Whether a river position is tidal - shared by the Routencheck
 * (RouteBriefingBuilder) and the etmal reach lines with wind and current
 * (WaterReach::reachTimed()).
 *
 * Only within the rules' tidal_sea_km of a sea coastline (cheap, local;
 * nothing is asked for rivers far inland). There the tide sources decide by
 * the mean tidal range (>= tidal_min_range_m); without a range from them the
 * rough TidelessSeas polygons decide.
 */
final class TidalRiver
{
    /**
     * @param (\Closure(float, float, int): ?array{distance: int, lat: float, lng: float})|null $sea nearest sea coastline point within a distance (WaterDistanceService::nearestSea()); null = never tidal
     * @param (\Closure(float, float, ?array, int, int, int): ?array)|null $tide tide data for a position (TideService::tide(): lat, lng, coast, from, to, max station metres); null = only the coastline rule
     */
    public function __construct(private readonly ?\Closure $sea, private readonly ?\Closure $tide)
    {
    }

    /**
     * @param array<string, mixed> $rules BriefingRules::normalize()d
     * @return array{tide: ?array<string, mixed>}|null null = not tidal; tide = the tide data with high/low water events, null = tidal but no times known
     */
    public function check(float $lat, float $lng, array $rules, int $from, int $to): ?array
    {
        $seaMeters = (int) round((float) ($rules['tidal_sea_km'] ?? 0) * 1000);
        if ($this->sea === null || $seaMeters <= 0) {
            return null;
        }
        $coast = ($this->sea)($lat, $lng, $seaMeters);
        if ($coast === null) {
            return null;
        }
        $tide = $this->tide !== null
            ? ($this->tide)($lat, $lng, ['lat' => (float) $coast['lat'], 'lng' => (float) $coast['lng']], $from, $to, (int) round((float) $rules['tidal_station_km'] * 1000))
            : null;
        if ($tide !== null && $tide['mean_range_m'] !== null) {
            if ($tide['mean_range_m'] < (float) $rules['tidal_min_range_m']) {
                return null;
            }
        } elseif (TidelessSeas::contains((float) $coast['lat'], (float) $coast['lng'])) {
            return null;
        }

        return ['tide' => $tide !== null && $tide['events'] !== [] ? $tide : null];
    }
}
