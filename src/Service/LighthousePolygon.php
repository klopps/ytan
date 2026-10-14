<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ValidationException;

/**
 * An area drawn on the map of the admin tool "Lighthouse import": the
 * polygon travels as the query parameter "lat,lng;lat,lng;..." and is used to
 * pick the staged entries that lie inside it. Pure geometry on plain
 * latitude/longitude - no database, no map library. Polygons that cross the
 * 180th meridian are not supported (the vertices would have to be unwrapped).
 */
final class LighthousePolygon
{
    public const MIN_VERTICES = 3;
    public const MAX_VERTICES = 100;

    /**
     * @return list<array{0:float, 1:float}> the vertices as [latitude, longitude]
     * @throws ValidationException when the text is no valid polygon
     */
    public static function parse(string $text): array
    {
        $vertices = [];
        foreach (explode(';', trim($text)) as $pair) {
            $parts = explode(',', trim($pair));
            if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                throw new ValidationException('polygon must be a list of "lat,lng" pairs separated by ";".');
            }
            $lat = (float) $parts[0];
            $lng = (float) $parts[1];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                throw new ValidationException('polygon: latitude must be within ±90 and longitude within ±180.');
            }
            $vertices[] = [$lat, $lng];
        }

        if (count($vertices) < self::MIN_VERTICES || count($vertices) > self::MAX_VERTICES) {
            throw new ValidationException(
                'polygon needs between ' . self::MIN_VERTICES . ' and ' . self::MAX_VERTICES . ' vertices.'
            );
        }

        return $vertices;
    }

    /**
     * @param list<array{0:float, 1:float}> $polygon
     * @return array{south:float, west:float, north:float, east:float}
     */
    public static function boundingBox(array $polygon): array
    {
        $lats = array_column($polygon, 0);
        $lngs = array_column($polygon, 1);

        return ['south' => min($lats), 'west' => min($lngs), 'north' => max($lats), 'east' => max($lngs)];
    }

    /**
     * Ray casting: counts how often a ray from the point towards east crosses
     * an edge; odd = inside. A point exactly on an edge may count either way.
     *
     * @param list<array{0:float, 1:float}> $polygon
     */
    public static function contains(array $polygon, float $lat, float $lng): bool
    {
        $inside = false;
        $count = count($polygon);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$latI, $lngI] = $polygon[$i];
            [$latJ, $lngJ] = $polygon[$j];
            if (($latI > $lat) !== ($latJ > $lat)
                && $lng < ($lngJ - $lngI) * ($lat - $latI) / ($latJ - $latI) + $lngI
            ) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
