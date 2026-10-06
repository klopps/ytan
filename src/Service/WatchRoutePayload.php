<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Compact route representation for the Garmin watch data field (watch/).
 * A data field may only fetch from its background process, and whatever
 * that process hands back is capped at a few KB - so points are simplified
 * (a GPS recording easily has thousands) and sent as one flat integer list
 * of lat/lng in 1e-5 degrees (~1 m), not as JSON objects.
 *
 *   {"v": "<route id>-<updated_at>", "n": "<name>", "u": "m"|"n", "p": [lat0, lng0, lat1, lng1, ...]}
 *
 * "v" changes whenever the route or its selection changes, so the watch
 * knows to restart at the first waypoint. An empty "p" means "no route".
 */
final class WatchRoutePayload
{
    public const MAX_POINTS = 250;
    private const START_TOLERANCE_METERS = 5.0;
    private const MAX_NAME_LENGTH = 40;

    public static function build(?array $route, string $unit, string $selectedAt): array
    {
        $unitCode = $unit === 'nautical' ? 'n' : 'm';
        if ($route === null) {
            return ['v' => 'none', 'n' => '', 'u' => $unitCode, 'p' => []];
        }

        $points = [];
        foreach (json_decode((string) $route['points'], true) ?: [] as $point) {
            if (isset($point['lat'], $point['lng'])) {
                $points[] = ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
            }
        }

        $tolerance = self::START_TOLERANCE_METERS;
        $simplified = GeometryService::simplifyPolyline($points, $tolerance);
        while (count($simplified) > self::MAX_POINTS) {
            $tolerance *= 2;
            $simplified = GeometryService::simplifyPolyline($points, $tolerance);
        }

        $flat = [];
        foreach ($simplified as $point) {
            $flat[] = (int) round($point['lat'] * 100000);
            $flat[] = (int) round($point['lng'] * 100000);
        }

        return [
            'v' => $route['id'] . '-' . ($route['updated_at'] ?? '') . '-' . $selectedAt,
            'n' => mb_substr((string) $route['name'], 0, self::MAX_NAME_LENGTH),
            'u' => $unitCode,
            'p' => $flat,
        ];
    }
}
