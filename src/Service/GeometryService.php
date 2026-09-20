<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Pure, stateless geometry helpers used to decide which POIs/Areas belong
 * in a Tour's generated PDF document (TourDocumentService) - there is no
 * route_poi/route_area relationship in the schema, so this is computed
 * fresh from route.points/area.points every time a document is generated.
 *
 * Points are plain ['lat' => float, 'lng' => float] arrays throughout,
 * matching the shape stored in route.points/area.points and poi's own
 * latitude/longitude columns. Polygon/segment math treats lat/lng as flat
 * planar (x,y) coordinates - correct for point-in-polygon and
 * segment-intersection (topological, not metric, tests) at the scale a
 * single kayak tour spans; only the proximity distance check needs an
 * actual metric distance, via haversine.
 */
final class GeometryService
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    public static function haversineDistanceMeters(array $a, array $b): float
    {
        $lat1 = deg2rad((float) $a['lat']);
        $lat2 = deg2rad((float) $b['lat']);
        $dLat = deg2rad((float) $b['lat'] - (float) $a['lat']);
        $dLng = deg2rad((float) $b['lng'] - (float) $a['lng']);

        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }

    /**
     * Shortest distance from $point to the segment $segStart-$segEnd, in
     * meters. Projects $point onto the (locally flat) segment, clamped to
     * the segment's own extent, then measures the haversine distance to
     * that projection - accurate enough at the scale route segments span
     * (short enough that flat-earth projection error is negligible).
     */
    public static function distancePointToSegmentMeters(array $point, array $segStart, array $segEnd): float
    {
        $dx = (float) $segEnd['lng'] - (float) $segStart['lng'];
        $dy = (float) $segEnd['lat'] - (float) $segStart['lat'];

        if ($dx === 0.0 && $dy === 0.0) {
            return self::haversineDistanceMeters($point, $segStart);
        }

        $t = (
            ((float) $point['lng'] - (float) $segStart['lng']) * $dx
            + ((float) $point['lat'] - (float) $segStart['lat']) * $dy
        ) / ($dx * $dx + $dy * $dy);
        $t = max(0.0, min(1.0, $t));

        $projection = [
            'lat' => (float) $segStart['lat'] + $t * $dy,
            'lng' => (float) $segStart['lng'] + $t * $dx,
        ];

        return self::haversineDistanceMeters($point, $projection);
    }

    /**
     * True if $point is within $meters of any segment of $polyline (or of
     * any single point, if $polyline has fewer than 2 vertices).
     */
    public static function isPointNearPolyline(array $point, array $polyline, float $meters): bool
    {
        if (count($polyline) < 2) {
            foreach ($polyline as $vertex) {
                if (self::haversineDistanceMeters($point, $vertex) <= $meters) {
                    return true;
                }
            }

            return false;
        }

        for ($i = 0; $i < count($polyline) - 1; $i++) {
            if (self::distancePointToSegmentMeters($point, $polyline[$i], $polyline[$i + 1]) <= $meters) {
                return true;
            }
        }

        return false;
    }

    /**
     * Standard ray-casting point-in-polygon test. A point exactly on an
     * edge is treated as inside (checked explicitly via
     * isPointOnSegment() before falling back to the ray-casting count),
     * rather than being left to depend on floating-point luck.
     */
    public static function isPointInPolygon(array $point, array $polygon): bool
    {
        $count = count($polygon);
        if ($count < 3) {
            return false;
        }

        for ($i = 0; $i < $count; $i++) {
            $next = ($i + 1) % $count;
            if (self::isPointOnSegment($point, $polygon[$i], $polygon[$next])) {
                return true;
            }
        }

        $inside = false;
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) $polygon[$i]['lng'];
            $yi = (float) $polygon[$i]['lat'];
            $xj = (float) $polygon[$j]['lng'];
            $yj = (float) $polygon[$j]['lat'];
            $x = (float) $point['lng'];
            $y = (float) $point['lat'];

            $intersects = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);
            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * True if the two segments p1-p2 and p3-p4 cross, touch at an
     * endpoint, or overlap collinearly - standard orientation-based test.
     */
    public static function segmentsIntersect(array $p1, array $p2, array $p3, array $p4): bool
    {
        $d1 = self::orientation($p3, $p4, $p1);
        $d2 = self::orientation($p3, $p4, $p2);
        $d3 = self::orientation($p1, $p2, $p3);
        $d4 = self::orientation($p1, $p2, $p4);

        if ((($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0))
            && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0))
        ) {
            return true;
        }

        if ($d1 === 0.0 && self::isPointOnSegment($p1, $p3, $p4)) {
            return true;
        }
        if ($d2 === 0.0 && self::isPointOnSegment($p2, $p3, $p4)) {
            return true;
        }
        if ($d3 === 0.0 && self::isPointOnSegment($p3, $p1, $p2)) {
            return true;
        }
        if ($d4 === 0.0 && self::isPointOnSegment($p4, $p1, $p2)) {
            return true;
        }

        return false;
    }

    /**
     * True if any segment of $polyline crosses the boundary of $polygon,
     * OR any vertex of $polyline lies inside $polygon - the second check
     * catches a route that starts/ends fully inside an area without ever
     * crossing its boundary, which a pure segment-crossing test would miss.
     */
    public static function doesPolylineIntersectPolygon(array $polyline, array $polygon): bool
    {
        if (count($polygon) < 3) {
            return false;
        }

        foreach ($polyline as $vertex) {
            if (self::isPointInPolygon($vertex, $polygon)) {
                return true;
            }
        }

        $polygonCount = count($polygon);
        for ($i = 0; $i < count($polyline) - 1; $i++) {
            for ($j = 0; $j < $polygonCount; $j++) {
                $k = ($j + 1) % $polygonCount;
                if (self::segmentsIntersect($polyline[$i], $polyline[$i + 1], $polygon[$j], $polygon[$k])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function orientation(array $a, array $b, array $c): float
    {
        return ((float) $b['lng'] - (float) $a['lng']) * ((float) $c['lat'] - (float) $a['lat'])
            - ((float) $b['lat'] - (float) $a['lat']) * ((float) $c['lng'] - (float) $a['lng']);
    }

    private static function isPointOnSegment(array $point, array $segStart, array $segEnd): bool
    {
        $epsilon = 1e-9;

        $cross = ((float) $point['lng'] - (float) $segStart['lng']) * ((float) $segEnd['lat'] - (float) $segStart['lat'])
            - ((float) $point['lat'] - (float) $segStart['lat']) * ((float) $segEnd['lng'] - (float) $segStart['lng']);
        if (abs($cross) > $epsilon) {
            return false;
        }

        $minLng = min((float) $segStart['lng'], (float) $segEnd['lng']) - $epsilon;
        $maxLng = max((float) $segStart['lng'], (float) $segEnd['lng']) + $epsilon;
        $minLat = min((float) $segStart['lat'], (float) $segEnd['lat']) - $epsilon;
        $maxLat = max((float) $segStart['lat'], (float) $segEnd['lat']) + $epsilon;

        return (float) $point['lng'] >= $minLng && (float) $point['lng'] <= $maxLng
            && (float) $point['lat'] >= $minLat && (float) $point['lat'] <= $maxLat;
    }
}
