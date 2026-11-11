<?php

declare(strict_types=1);

namespace Ytan\Service\Overpass;

use Ytan\Exception\ValidationException;
use Ytan\Service\RadarConfig;

/**
 * Splits a country into tiles for the per-country Overpass imports
 * (campsites, clubs, shelters): one query for a whole big country can run
 * for many minutes - all French campsites with their surroundings were still
 * arriving after 6:40 minutes (measured 2026-11-11) - far beyond the HTTP
 * timeout and the web server's request limit, and a request killed from
 * outside leaves no log line. So the admin pages ask for one tile per
 * request and the CLI scripts loop over them.
 *
 * The tiles cover the country's box from RadarConfig::PRESETS (its European
 * part - overseas territories inside the ISO3166-1 area are left out), split
 * evenly into cells of at most the given size; a country without a box is one
 * query over its whole area (null). The query keeps the area filter and adds
 * the tile as a bounding box, so an element on a tile border can come twice -
 * the imports upsert by their source reference, which keeps one row.
 */
final class CountryTiles
{
    /**
     * @return list<array{south:float, west:float, north:float, east:float}|null>
     */
    public static function tiles(string $country, float $degrees): array
    {
        $box = RadarConfig::PRESETS[strtolower(trim($country))] ?? null;
        if ($box === null) {
            return [null];
        }
        $rows = max(1, (int) ceil(($box['north'] - $box['south']) / $degrees - 1e-9));
        $cols = max(1, (int) ceil(($box['east'] - $box['west']) / $degrees - 1e-9));
        $height = ($box['north'] - $box['south']) / $rows;
        $width = ($box['east'] - $box['west']) / $cols;
        $tiles = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $tiles[] = [
                    'south' => round($box['south'] + $r * $height, 4),
                    'west' => round($box['west'] + $c * $width, 4),
                    'north' => round($box['south'] + ($r + 1) * $height, 4),
                    'east' => round($box['west'] + ($c + 1) * $width, 4),
                ];
            }
        }

        return $tiles;
    }

    /**
     * The tile with this index, or a ValidationException.
     *
     * @param list<array{south:float, west:float, north:float, east:float}|null> $tiles
     * @return array{south:float, west:float, north:float, east:float}|null
     */
    public static function pick(array $tiles, int $tile): ?array
    {
        if ($tile < 0 || $tile >= count($tiles)) {
            throw new ValidationException('tile must be between 0 and ' . (count($tiles) - 1) . '.');
        }

        return $tiles[$tile];
    }

    /**
     * The Overpass bounding-box filter for a tile ("(s,w,n,e)"), '' for null.
     *
     * @param array{south:float, west:float, north:float, east:float}|null $tile
     */
    public static function bbox(?array $tile): string
    {
        return $tile === null ? '' : '(' . $tile['south'] . ',' . $tile['west'] . ',' . $tile['north'] . ',' . $tile['east'] . ')';
    }
}
