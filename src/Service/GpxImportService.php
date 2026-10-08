<?php

declare(strict_types=1);

namespace Ytan\Service;

use DOMDocument;
use DOMElement;
use Ytan\Exception\ValidationException;

/**
 * Reads a GPX file (1.1, and 1.0 - elements are matched by local name, so
 * the namespace doesn't matter) for GpxImportController.
 *
 * parse() returns the document's own name/desc/time and one entry per
 * <trk> and <rte>, in document order:
 *   ['name', 'desc', 'time', 'tracks' => [[
 *       'index', 'kind' ('trk'|'rte'), 'name', 'desc', 'time',
 *       'points' => [['lat', 'lng', 'time'(?string)], ...],
 *       'started_at', 'ended_at' (?string, UTC ISO 8601, from point times),
 *   ], ...]]
 * A track's <trkseg>s are joined into one line. A track's "time" is its
 * first point's time (GPX 1.1 has no <time> on <trk>/<rte> itself). If
 * the file holds exactly one track, its missing name/desc/time are taken
 * from the document (<metadata> in 1.1, top level in 1.0).
 * Also 'waypoints' => [['index', 'name', 'desc', 'lat', 'lng'], ...]: every
 * <wpt> with a name and/or a description (others carry nothing worth a
 * POI) - imported as POIs on request, see toPoi().
 *
 * toRoute() turns one or more parsed tracks into the data for
 * RouteRepository::create(): points simplified to at most MAX_POINTS (the
 * route.points column is a TEXT, ~64 KB), length in meters, and - if the
 * points carry times - recorded_at/recording_duration_seconds like a GPS
 * recording.
 */
final class GpxImportService
{
    // The file travels JSON-encoded (quotes escaped, ~10-20% bigger) and must stay
    // under PHP's default post_max_size of 8 MB.
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_POINTS = 500;
    private const SIMPLIFY_START_TOLERANCE_METERS = 5.0;

    /**
     * Colors for routes imported side by side (mode "separate"), in turn -
     * so a multi-track import doesn't come out as one indistinguishable
     * line. Starts with the app's default route color
     * (RouteRepository::create()); all chosen to stand out on the hybrid/
     * satellite and the terrain map.
     */
    public const ROUTE_COLORS = ['#BF409F', '#E8711A', '#1E88E5', '#2E9E44', '#E53935', '#8E44AD', '#00ACC1', '#C9A100'];

    /** POI type of imported waypoints ("General location markers"). */
    public const WAYPOINT_POI_TYPE = 0;
    private const WAYPOINT_NAME_PREFIX = 'IMPORT: ';
    private const WAYPOINT_NAME_FROM_DESC_LENGTH = 20;

    /** The color for the $n-th (0-based) route of an import, cycling through ROUTE_COLORS. */
    public static function routeColor(int $n): string
    {
        return self::ROUTE_COLORS[$n % count(self::ROUTE_COLORS)];
    }

    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            throw new ValidationException('The GPX file is empty.');
        }
        if (strlen($xml) > self::MAX_BYTES) {
            throw new ValidationException('The GPX file is too large (max. 5 MB).');
        }
        // No DTDs at all: rules out entity expansion (billion laughs) and
        // external entities (XXE) regardless of libxml defaults. GPX never
        // needs one.
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new ValidationException('The GPX file must not contain a DOCTYPE.');
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $doc->documentElement === null || $doc->documentElement->localName !== 'gpx') {
            throw new ValidationException('This is not a valid GPX file.');
        }
        $gpx = $doc->documentElement;

        $meta = $this->child($gpx, 'metadata') ?? $gpx;
        $result = [
            'name' => $this->childText($meta, 'name'),
            'desc' => $this->childText($meta, 'desc'),
            'time' => $this->isoTime($this->childText($meta, 'time')),
            'tracks' => [],
            'waypoints' => [],
        ];

        foreach ($gpx->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'wpt') {
                $waypoint = $this->waypoint($node);
                if ($waypoint !== null) {
                    $result['waypoints'][] = ['index' => count($result['waypoints'])] + $waypoint;
                }
                continue;
            }
            if (!$node instanceof DOMElement || !in_array($node->localName, ['trk', 'rte'], true)) {
                continue;
            }
            $points = $node->localName === 'trk'
                ? $this->points($node, 'trkpt', 'trkseg')
                : $this->points($node, 'rtept', null);
            $times = array_values(array_filter(array_column($points, 'time')));

            $result['tracks'][] = [
                'index' => count($result['tracks']),
                'kind' => $node->localName,
                'name' => $this->childText($node, 'name'),
                'desc' => $this->childText($node, 'desc'),
                'time' => $times[0] ?? null,
                'points' => $points,
                'started_at' => $times[0] ?? null,
                'ended_at' => $times !== [] ? $times[count($times) - 1] : null,
            ];
        }

        if (count($result['tracks']) === 1) {
            foreach (['name', 'desc', 'time'] as $field) {
                if ($result['tracks'][0][$field] === null) {
                    $result['tracks'][0][$field] = $result[$field];
                }
            }
        }

        return $result;
    }

    /**
     * The preview the import dialog shows - parse() without the points,
     * plus each track's point count, the count it keeps as a separate route
     * (route_point_count, lower once simplified, see toRoute()) and length,
     * and the waypoints (as the POI names they would get).
     */
    public function summary(array $parsed): array
    {
        $summary = $parsed;
        $summary['waypoints'] = array_map(fn (array $waypoint) => [
            'index' => $waypoint['index'],
            'name' => $this->toPoi($waypoint)['name'],
        ], $parsed['waypoints']);
        $summary['tracks'] = array_map(fn (array $track) => [
            'index' => $track['index'],
            'kind' => $track['kind'],
            'name' => $track['name'],
            'desc' => $track['desc'],
            'time' => $track['time'],
            'point_count' => count($track['points']),
            'route_point_count' => count($track['points']) > self::MAX_POINTS
                ? count(json_decode($this->toRoute([$track], '', '')['points'], true))
                : count($track['points']),
            'length' => (int) round($this->length($track['points'])),
        ], $parsed['tracks']);

        return $summary;
    }

    /**
     * @param array<int, array> $tracks parsed tracks, joined in this order
     * @return array{name: string, description: string, points: string, length: int, recorded_at: ?string, recording_duration_seconds: ?int}
     */
    public function toRoute(array $tracks, string $name, string $description): array
    {
        $points = [];
        foreach ($tracks as $track) {
            foreach ($track['points'] as $point) {
                $points[] = ['lat' => $point['lat'], 'lng' => $point['lng']];
            }
        }

        $simplified = $points;
        if (count($simplified) > self::MAX_POINTS) {
            // Douglas-Peucker degrades towards O(n²) on dense, jittery GPS
            // data (a 5,000-point zigzag took over a minute) - thinning by
            // spacing first caps its input at 2 × MAX_POINTS (30,000 noisy
            // points: 0.2 s).
            $thinned = $this->thin($points, 2 * self::MAX_POINTS);
            $tolerance = self::SIMPLIFY_START_TOLERANCE_METERS;
            $simplified = GeometryService::simplifyPolyline($thinned, $tolerance);
            while (count($simplified) > self::MAX_POINTS) {
                $tolerance *= 2;
                $simplified = GeometryService::simplifyPolyline($thinned, $tolerance);
            }
        }
        $simplified = array_map(fn (array $p) => ['lat' => round($p['lat'], 7), 'lng' => round($p['lng'], 7)], $simplified);

        // Recording window = earliest start .. latest end over all tracks.
        $starts = array_filter(array_column($tracks, 'started_at'));
        $ends = array_filter(array_column($tracks, 'ended_at'));
        $recordedAt = null;
        $duration = null;
        if ($starts !== [] && $ends !== []) {
            $start = min(array_map('strtotime', $starts));
            $end = max(array_map('strtotime', $ends));
            if ($end >= $start) {
                $recordedAt = gmdate('Y-m-d H:i:s', $start);
                $duration = $end - $start;
            }
        }

        return [
            'name' => mb_substr($name, 0, 150),
            'description' => $description,
            // Length of the full-resolution line, not the simplified one -
            // simplifying cuts corners and would understate it.
            'length' => (int) round($this->length($points)),
            'points' => json_encode($simplified),
            'recorded_at' => $recordedAt,
            'recording_duration_seconds' => $duration,
        ];
    }

    /**
     * The data for PoiRepository::create(): type WAYPOINT_POI_TYPE, the
     * waypoint's name - or, without one, "IMPORT: " + the first 20
     * characters of its description - and the description. Description
     * and url are '' rather than null when empty, as for a POI saved in the
     * editor - poi.js renders both as strings (marked.parse(null) throws,
     * a null url showed up as a "null" link and form value).
     */
    public function toPoi(array $waypoint): array
    {
        $name = $waypoint['name']
            ?? self::WAYPOINT_NAME_PREFIX . rtrim(mb_substr((string) $waypoint['desc'], 0, self::WAYPOINT_NAME_FROM_DESC_LENGTH));

        return [
            'poitype_id' => self::WAYPOINT_POI_TYPE,
            'name' => mb_substr($name, 0, 200),
            'description' => $waypoint['desc'] ?? '',
            'latitude' => $waypoint['lat'],
            'longitude' => $waypoint['lng'],
            'url' => '',
        ];
    }

    /** A <wpt> with valid coordinates and a name and/or description, else null. */
    private function waypoint(DOMElement $node): ?array
    {
        $lat = $node->getAttribute('lat');
        $lon = $node->getAttribute('lon');
        $name = $this->childText($node, 'name');
        $desc = $this->childText($node, 'desc');
        if (($name === null && $desc === null)
            || !is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
            return null;
        }

        return ['name' => $name, 'desc' => $desc, 'lat' => (float) $lat, 'lng' => (float) $lon];
    }

    /** @return list<array{lat: float, lng: float, time: ?string}> */
    private function points(DOMElement $parent, string $pointName, ?string $segmentName): array
    {
        $containers = [$parent];
        if ($segmentName !== null) {
            $containers = [];
            foreach ($parent->childNodes as $node) {
                if ($node instanceof DOMElement && $node->localName === $segmentName) {
                    $containers[] = $node;
                }
            }
        }

        $points = [];
        foreach ($containers as $container) {
            foreach ($container->childNodes as $node) {
                if (!$node instanceof DOMElement || $node->localName !== $pointName) {
                    continue;
                }
                $lat = $node->getAttribute('lat');
                $lon = $node->getAttribute('lon');
                if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
                    continue;
                }
                $points[] = [
                    'lat' => (float) $lat,
                    'lng' => (float) $lon,
                    'time' => $this->isoTime($this->childText($node, 'time')),
                ];
            }
        }

        return $points;
    }

    /**
     * Keeps first and last point and every point at least
     * length / $target meters from the previously kept one - about $target
     * points, evenly spread along the line.
     */
    private function thin(array $points, int $target): array
    {
        $count = count($points);
        if ($count <= $target) {
            return $points;
        }

        $spacing = $this->length($points) / $target;
        $kept = [$points[0]];
        $last = $points[0];
        for ($i = 1; $i < $count - 1; $i++) {
            if (GeometryService::haversineDistanceMeters($last, $points[$i]) >= $spacing) {
                $kept[] = $points[$i];
                $last = $points[$i];
            }
        }
        $kept[] = $points[$count - 1];

        return $kept;
    }

    private function length(array $points): float
    {
        $length = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $length += GeometryService::haversineDistanceMeters($points[$i - 1], $points[$i]);
        }

        return $length;
    }

    private function child(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    private function childText(DOMElement $parent, string $name): ?string
    {
        $child = $this->child($parent, $name);
        $text = $child !== null ? trim($child->textContent) : '';

        return $text === '' ? null : $text;
    }

    /** A GPX time as UTC ISO 8601 ("2026-10-08T09:15:00Z"), null if missing/unparseable. */
    private function isoTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
