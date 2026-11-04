<?php

declare(strict_types=1);

namespace Ytan\Service\Campsite;

/**
 * Names for campsites that have neither a name nor an address town: the
 * places (place=city/town/village/hamlet/suburb, nodes) and named streets
 * (highway=* ways with their geometry) Overpass returned around those
 * campsites in the same answer (see CampsiteOsmImportService::query()).
 * Nearest place within PLACE_RADIUS_M, nearest street within
 * STREET_RADIUS_M; lookups go through a 0.1 degree grid, so a country with
 * thousands of unnamed sites and tens of thousands of hamlets stays fast.
 */
final class CampsiteSurroundings
{
    public const PLACE_RADIUS_M = 10000;
    public const STREET_RADIUS_M = 200;
    public const PLACE_TYPES = ['city', 'town', 'village', 'hamlet', 'suburb'];

    private const CELL = 0.1;
    private const EARTH_RADIUS_M = 6371000.0;

    /** @var array<string, list<array{0:float,1:float,2:string}>> cell => [lat, lng, name] */
    private array $places = [];

    /** @var array<string, list<array{0:float,1:float,2:float,3:float,4:string}>> cell => segment [lat1, lng1, lat2, lng2, name] */
    private array $streets = [];

    /** False for a campsite (tourism=camp_site); anything else is kept if it is a usable place or street. */
    public function add(array $element): bool
    {
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        if (($tags['tourism'] ?? null) === 'camp_site') {
            return false;
        }
        $name = trim((string) ($tags['name'] ?? ''));
        $type = $element['type'] ?? null;
        if ($name === '') {
            return true;
        }
        if ($type === 'node' && in_array($tags['place'] ?? null, self::PLACE_TYPES, true)
            && is_numeric($element['lat'] ?? null) && is_numeric($element['lon'] ?? null)) {
            $lat = (float) $element['lat'];
            $lng = (float) $element['lon'];
            $this->places[self::cell($lat, $lng)][] = [$lat, $lng, $name];
        } elseif ($type === 'way' && isset($tags['highway'])) {
            $points = [];
            foreach (is_array($element['geometry'] ?? null) ? $element['geometry'] : [] as $point) {
                if (is_numeric($point['lat'] ?? null) && is_numeric($point['lon'] ?? null)) {
                    $points[] = [(float) $point['lat'], (float) $point['lon']];
                }
            }
            for ($i = 1, $n = count($points); $i < $n; $i++) {
                $segment = [$points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $name];
                // Filed under every cell its end points touch; segments longer than a cell are rare for streets.
                foreach (array_unique([self::cell($segment[0], $segment[1]), self::cell($segment[2], $segment[3])]) as $cell) {
                    $this->streets[$cell][] = $segment;
                }
            }
        }

        return true;
    }

    /** Name of the nearest place within PLACE_RADIUS_M, '' if none. */
    public function placeNear(float $lat, float $lng): string
    {
        $best = '';
        $bestDistance = self::PLACE_RADIUS_M;
        foreach ($this->near($this->places, $lat, $lng) as [$pLat, $pLng, $name]) {
            $distance = self::distance($lat, $lng, $pLat, $pLng);
            if ($distance <= $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /** Name of the nearest named street within STREET_RADIUS_M, '' if none. */
    public function streetNear(float $lat, float $lng): string
    {
        $best = '';
        $bestDistance = self::STREET_RADIUS_M;
        foreach ($this->near($this->streets, $lat, $lng) as [$lat1, $lng1, $lat2, $lng2, $name]) {
            $distance = self::segmentDistance($lat, $lng, $lat1, $lng1, $lat2, $lng2);
            if ($distance <= $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * Entries of the cell of the position and its neighbours (a 10 km radius
     * needs one ring: a cell is 0.1 degrees, >= 11 km north-south and wider
     * east-west than 10 km up to 63 N; further north two rings).
     *
     * @template T
     * @param array<string, list<T>> $grid
     * @return iterable<T>
     */
    private function near(array $grid, float $lat, float $lng): iterable
    {
        $row = (int) floor($lat / self::CELL);
        $col = (int) floor($lng / self::CELL);
        $ring = abs($lat) > 63 ? 2 : 1;
        for ($r = $row - 1; $r <= $row + 1; $r++) {
            for ($c = $col - $ring; $c <= $col + $ring; $c++) {
                yield from $grid[$r . ':' . $c] ?? [];
            }
        }
    }

    private static function cell(float $lat, float $lng): string
    {
        return (int) floor($lat / self::CELL) . ':' . (int) floor($lng / self::CELL);
    }

    private static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }

    /** Distance to a segment on a local flat projection (fine for 200 m). */
    private static function segmentDistance(float $lat, float $lng, float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $scale = cos(deg2rad($lat));
        $ax = ($lng1 - $lng) * $scale;
        $ay = $lat1 - $lat;
        $bx = ($lng2 - $lng) * $scale;
        $by = $lat2 - $lat;
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $length = $dx * $dx + $dy * $dy;
        $t = $length > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $length)) : 0.0;
        $x = $ax + $t * $dx;
        $y = $ay + $t * $dy;

        return deg2rad(sqrt($x * $x + $y * $y)) * self::EARTH_RADIUS_M;
    }
}
