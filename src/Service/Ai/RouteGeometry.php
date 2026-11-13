<?php

declare(strict_types=1);

namespace Ytan\Service\Ai;

/**
 * Geometry on a route (list of [lat, lng] waypoints) for the Routencheck:
 * lengths on the sphere, courses, positions at a distance along the line and
 * the distance of a point to the line.
 */
final class RouteGeometry
{
    private const EARTH_RADIUS = 6371008.8;
    private const METERS_PER_DEGREE = 111320.0;

    /** @var list<array{0: float, 1: float}> */
    public readonly array $points;
    /** @var list<float> distance from the start to each waypoint (m) */
    public readonly array $cumulative;
    public readonly float $length;

    /** @param list<array{0: float, 1: float}> $points */
    public function __construct(array $points)
    {
        $this->points = array_values($points);
        $cumulative = [0.0];
        for ($i = 1, $n = count($this->points); $i < $n; $i++) {
            $cumulative[] = $cumulative[$i - 1] + self::distance($this->points[$i - 1], $this->points[$i]);
        }
        $this->cumulative = $cumulative;
        $this->length = (float) end($cumulative);
    }

    /**
     * The route's "points" column ([{lat, lng}, ...] JSON) as [lat, lng] pairs;
     * entries without numeric coordinates are skipped.
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function parsePoints(?string $json): array
    {
        $decoded = json_decode((string) $json, true);
        $points = [];
        foreach (is_array($decoded) ? $decoded : [] as $point) {
            if (is_array($point) && is_numeric($point['lat'] ?? null) && is_numeric($point['lng'] ?? null)) {
                $points[] = [(float) $point['lat'], (float) $point['lng']];
            }
        }

        return $points;
    }

    /** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b */
    public static function distance(array $a, array $b): float
    {
        $p1 = deg2rad($a[0]);
        $p2 = deg2rad($b[0]);
        $h = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($b[1] - $a[1]) / 2) ** 2;

        return 2 * self::EARTH_RADIUS * asin(min(1.0, sqrt($h)));
    }

    /** Initial course from $a to $b in degrees (0 = north, 90 = east). */
    public static function bearing(array $a, array $b): float
    {
        $p1 = deg2rad($a[0]);
        $p2 = deg2rad($b[0]);
        $dl = deg2rad($b[1] - $a[1]);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);

        return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
    }

    /** Smallest angle between two directions, 0..180 degrees. */
    public static function angleBetween(float $a, float $b): float
    {
        $d = fmod(abs($a - $b), 360.0);

        return $d > 180.0 ? 360.0 - $d : $d;
    }

    /** @return array{0: float, 1: float} the position $meters from the start along the line */
    public function at(float $meters): array
    {
        $n = count($this->points);
        if ($n === 1 || $meters <= 0) {
            return $this->points[0];
        }
        if ($meters >= $this->length) {
            return $this->points[$n - 1];
        }
        for ($i = 1; $i < $n; $i++) {
            if ($this->cumulative[$i] >= $meters) {
                $span = $this->cumulative[$i] - $this->cumulative[$i - 1];
                $t = $span > 0 ? ($meters - $this->cumulative[$i - 1]) / $span : 0.0;
                [$lat1, $lng1] = $this->points[$i - 1];
                [$lat2, $lng2] = $this->points[$i];

                return [$lat1 + $t * ($lat2 - $lat1), $lng1 + $t * ($lng2 - $lng1)];
            }
        }

        return $this->points[$n - 1];
    }

    /**
     * The line between two distances along it: the two cut positions and every
     * waypoint in between.
     *
     * @return list<array{0: float, 1: float}>
     */
    public function slice(float $from, float $to): array
    {
        $line = [$this->at($from)];
        foreach ($this->points as $i => $point) {
            if ($this->cumulative[$i] > $from && $this->cumulative[$i] < $to) {
                $line[] = $point;
            }
        }
        $line[] = $this->at($to);

        return $line;
    }

    /**
     * Distance of a position to the line and where along the line its nearest
     * point lies (flat geometry around the position).
     *
     * @return array{distance: float, along: float}
     */
    public function nearest(float $lat, float $lng): array
    {
        $scaleX = self::METERS_PER_DEGREE * cos(deg2rad($lat));
        $best = ['distance' => INF, 'along' => 0.0];
        $n = count($this->points);
        if ($n === 1) {
            return ['distance' => self::distance([$lat, $lng], $this->points[0]), 'along' => 0.0];
        }
        for ($i = 1; $i < $n; $i++) {
            $ax = ($this->points[$i - 1][1] - $lng) * $scaleX;
            $ay = ($this->points[$i - 1][0] - $lat) * self::METERS_PER_DEGREE;
            $dx = ($this->points[$i][1] - $this->points[$i - 1][1]) * $scaleX;
            $dy = ($this->points[$i][0] - $this->points[$i - 1][0]) * self::METERS_PER_DEGREE;
            $lengthSquared = $dx * $dx + $dy * $dy;
            $t = $lengthSquared > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $lengthSquared)) : 0.0;
            $d = hypot($ax + $t * $dx, $ay + $t * $dy);
            if ($d < $best['distance']) {
                $span = $this->cumulative[$i] - $this->cumulative[$i - 1];
                $best = ['distance' => $d, 'along' => $this->cumulative[$i - 1] + $t * $span];
            }
        }

        return $best;
    }

    /**
     * Cut the route into sections of about $targetMeters (at least one, at most
     * $maxSections), equally long.
     *
     * @return list<array{from: float, to: float, points: list<array{0: float, 1: float}>, mid: array{0: float, 1: float}, bearing: float, length: float}>
     */
    public function sections(float $targetMeters = 3000.0, int $maxSections = 12): array
    {
        $count = max(1, min($maxSections, (int) ceil($this->length / $targetMeters)));
        $sections = [];
        for ($i = 0; $i < $count; $i++) {
            $from = $this->length * $i / $count;
            $to = $this->length * ($i + 1) / $count;
            $start = $this->at($from);
            $end = $this->at($to);
            $sections[] = [
                'from' => $from,
                'to' => $to,
                'points' => $this->slice($from, $to),
                'mid' => $this->at(($from + $to) / 2),
                'bearing' => self::distance($start, $end) > 1.0 ? self::bearing($start, $end) : 0.0,
                'length' => $to - $from,
            ];
        }

        return $sections;
    }
}
