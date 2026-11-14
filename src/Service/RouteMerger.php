<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Joins the lines of two routes into one (RouteMergeController). Pure, no
 * database - points are ['lat' => float, 'lng' => float] arrays.
 *
 * Only END points can be joined - a line can only be extended at its ends,
 * a join in the middle would make a branch. Of the four combinations (start
 * or end of the kept route x start or end of the other one) the pair with
 * identical coordinates (compared at PointsJson's 6 decimals) wins, else the
 * closest pair. The kept route keeps its direction; the other route is
 * reversed when needed so the line runs through:
 * - joined at the kept route's END: other (starting at the join) follows it;
 * - joined at the kept route's START: other (ending at the join) comes first.
 * The join itself: identical points are kept once ('shared'); points closer
 * than $midpointMaxMeters are replaced by one point halfway between them
 * ('midpoint'); otherwise both stay and the line between them is the new
 * connecting segment ('segment').
 *
 * public/js/route.js's routeMergeOtherFirst() mirrors the choice of the end
 * point pair (for the order of the names in the dialog) - keep both in step.
 */
final class RouteMerger
{
    /**
     * @param list<array{lat: float, lng: float}> $kept  at least 1 point
     * @param list<array{lat: float, lng: float}> $other at least 1 point
     * @return array{points: list<array{lat: float, lng: float}>, join: string, gap_m: float, other_first: bool, other_reversed: bool}
     */
    public static function merge(array $kept, array $other, float $midpointMaxMeters): array
    {
        if ($kept === [] || $other === []) {
            throw new \InvalidArgumentException('Both routes need at least one point.');
        }
        $kept = array_values($kept);
        $other = array_values($other);

        $join = self::closestEnds($kept, $other);
        $otherFirst = !$join['kept_end'];
        // At the kept end the other route must START at the join, at the
        // kept start it must END there - otherwise it runs the wrong way.
        $reverse = $join['kept_end'] ? $join['other_end'] : !$join['other_end'];
        if ($reverse) {
            $other = array_reverse($other);
        }

        $first = $otherFirst ? $other : $kept;
        $second = $otherFirst ? $kept : $other;
        $a = array_pop($first);   // last point of the first line
        $b = array_shift($second); // first point of the second line

        if ($join['identical']) {
            $middle = [$a];
            $type = 'shared';
        } elseif ($join['distance'] < $midpointMaxMeters) {
            $middle = [[
                'lat' => ((float) $a['lat'] + (float) $b['lat']) / 2,
                'lng' => ((float) $a['lng'] + (float) $b['lng']) / 2,
            ]];
            $type = 'midpoint';
        } else {
            $middle = [$a, $b];
            $type = 'segment';
        }

        return [
            'points' => array_values(array_merge($first, $middle, $second)),
            'join' => $type,
            'gap_m' => $join['identical'] ? 0.0 : $join['distance'],
            'other_first' => $otherFirst,
            'other_reversed' => $reverse,
        ];
    }

    /**
     * The end point pair to join. Candidates in this order, so a tie keeps
     * the more natural result: kept end + other start (no reversal), kept
     * end + other end, kept start + other end, kept start + other start.
     *
     * @return array{kept_end: bool, other_end: bool, identical: bool, distance: float}
     */
    private static function closestEnds(array $kept, array $other): array
    {
        $keptEnds = [true => $kept[count($kept) - 1], false => $kept[0]];
        $otherEnds = [true => $other[count($other) - 1], false => $other[0]];
        $candidates = [[true, false], [true, true], [false, true], [false, false]];

        $best = null;
        foreach ($candidates as [$keptEnd, $otherEnd]) {
            $p = $keptEnds[$keptEnd];
            $q = $otherEnds[$otherEnd];
            $identical = self::same($p, $q);
            $distance = $identical ? 0.0 : GeometryService::haversineDistanceMeters($p, $q);
            if ($best === null || ($identical && !$best['identical'])
                || ($identical === $best['identical'] && $distance < $best['distance'])) {
                $best = ['kept_end' => $keptEnd, 'other_end' => $otherEnd, 'identical' => $identical, 'distance' => $distance];
            }
        }

        return $best;
    }

    private static function same(array $p, array $q): bool
    {
        return round((float) $p['lat'], PointsJson::DECIMALS) === round((float) $q['lat'], PointsJson::DECIMALS)
            && round((float) $p['lng'], PointsJson::DECIMALS) === round((float) $q['lng'], PointsJson::DECIMALS);
    }
}
