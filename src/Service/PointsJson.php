<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Shortens the coordinates in a route's/area's `points` JSON
 * (`[{"lat": ..., "lng": ...}, ...]`). Google Maps hands out 15-17 decimals,
 * 6 decimals are ~11 cm - more than any map click or GPS fix delivers - and
 * cut the stored and transferred lines by about 40 %.
 */
final class PointsJson
{
    public const DECIMALS = 6;

    /**
     * Rounds every {lat, lng} object in the JSON (at any depth, other keys
     * kept). Anything that isn't a JSON array is returned unchanged.
     */
    public static function round(?string $json, int $decimals = self::DECIMALS): ?string
    {
        if ($json === null || $json === '') {
            return $json;
        }

        $points = json_decode($json, true);
        if (!is_array($points)) {
            return $json;
        }

        return json_encode(self::roundValue($points, $decimals));
    }

    private static function roundValue(array $value, int $decimals): array
    {
        if (isset($value['lat'], $value['lng']) && is_numeric($value['lat']) && is_numeric($value['lng'])) {
            $value['lat'] = round((float) $value['lat'], $decimals);
            $value['lng'] = round((float) $value['lng'], $decimals);
            return $value;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::roundValue($item, $decimals);
            }
        }

        return $value;
    }
}
