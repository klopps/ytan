<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Fetches map-view images for a Touren-Dokument PDF from Google's Static
 * Maps API and caches them to disk, content-addressed by their own query
 * string - same validate/check-cache/fetch/cache/return shape as
 * WsiRenderer, but for a raster PNG instead of a generated SVG.
 *
 * Cached under storage/ (outside the webroot), unlike WsiRenderer's
 * public/images/wsi: WSI icons are deliberately public, independently
 * linkable assets, while these map crops are only ever embedded into one
 * specific (possibly private) tour's PDF and are never meant to be
 * reachable via a guessable URL of their own.
 *
 * Uses a plain curl call rather than CurlJsonHttpClient, since that class
 * json_decode()s every response body - which would corrupt these PNG
 * bytes.
 */
final class StaticMapImageService
{
    private const BASE_URL = 'https://maps.googleapis.com/maps/api/staticmap';
    private const USER_AGENT = 'YTAN/1.0 (+https://ytan.pesr.org; no-reply@pesr.org)';
    private const SIZE = '640x400';
    private const SCALE = 2;
    private const VALID_MAP_TYPES = ['hybrid', 'terrain', 'satellite'];

    // Unlike GeocodingService's CACHE_TTL_SECONDS, this is NOT a freshness
    // TTL - a cached image is content-addressed by its full query string
    // (see fetch() below), so it never goes stale on its own; a route/area
    // edit simply produces a new hash and leaves the old file orphaned
    // rather than invalidating it. This is purely a disk-retention window
    // for bin/cleanup-file-caches.php (via FileCacheCleanupService) to
    // reclaim space from images no tour document will ever request again.
    public const CACHE_MAX_AGE_SECONDS = 90 * 24 * 3600;

    public function __construct(
        private readonly string $cacheDir,
        private readonly string $apiKey,
    ) {
    }

    /**
     * Renders one static map combining any number of colored route
     * polylines, labeled POI markers and labeled area polygons - used for
     * both the tour overview (routes only, one per route, in that
     * route's own color) and each per-route section map (that route plus
     * the POI/Area markers of its own sub-entries), so a marker's label
     * on the image lines up with the same number in front of that
     * sub-entry's heading in the document text.
     *
     * Viewport is auto-fit by Static Maps itself (no center/zoom passed)
     * from whatever path=/markers= params end up in the query string.
     *
     * @param list<array{polyline: list<array{lat: float, lng: float}>, color: string}> $routes
     * @param list<array{lat: float, lng: float, label: string}> $markers
     * @param list<array{polygon: list<array{lat: float, lng: float}>, label: string, color: string}> $areas
     */
    public function render(array $routes, array $markers, array $areas, string $mapType): ?string
    {
        $params = [];

        foreach ($areas as $area) {
            if (count($area['polygon']) < 3) {
                continue;
            }

            $closed = $area['polygon'];
            $closed[] = $area['polygon'][0];
            $strokeColor = '0x' . ltrim($area['color'], '#') . 'cc';
            $fillColor = '0x' . ltrim($area['color'], '#') . '33';

            $params[] = 'path=color:' . $strokeColor . '|weight:3|fillcolor:' . $fillColor
                . '|enc:' . rawurlencode(self::encodePolyline($closed));

            $centroid = self::polygonCentroid($area['polygon']);
            $params[] = 'markers=color:0x' . ltrim($area['color'], '#') . '|label:' . rawurlencode($area['label'])
                . '|' . rawurlencode($centroid['lat'] . ',' . $centroid['lng']);
        }

        foreach ($routes as $route) {
            if (count($route['polyline']) < 2) {
                continue;
            }

            $color = '0x' . ltrim($route['color'], '#') . 'cc';
            $params[] = 'path=color:' . $color . '|weight:4|enc:' . rawurlencode(self::encodePolyline($route['polyline']));
        }

        foreach ($markers as $marker) {
            $params[] = 'markers=color:red|label:' . rawurlencode($marker['label'])
                . '|' . rawurlencode($marker['lat'] . ',' . $marker['lng']);
        }

        if ($params === []) {
            return null;
        }

        return $this->fetch($params, $mapType);
    }

    private function fetch(array $pathAndMarkerParams, string $mapType): ?string
    {
        if ($this->apiKey === '') {
            return null;
        }
        if (!in_array($mapType, self::VALID_MAP_TYPES, true)) {
            $mapType = 'hybrid';
        }

        $query = array_merge($pathAndMarkerParams, [
            'size=' . self::SIZE,
            'scale=' . self::SCALE,
            'maptype=' . $mapType,
            'key=' . rawurlencode($this->apiKey),
        ]);
        sort($query);
        $queryString = implode('&', $query);

        $cacheFile = rtrim($this->cacheDir, '/\\') . '/staticmap_' . hash('sha256', $queryString) . '.png';
        if (is_file($cacheFile)) {
            return file_get_contents($cacheFile) ?: null;
        }

        $bytes = $this->fetchBinary(self::BASE_URL . '?' . $queryString);
        if ($bytes === null) {
            return null;
        }

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
        file_put_contents($cacheFile, $bytes);

        return $bytes;
    }

    private function fetchBinary(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        $body = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $body === false || $statusCode !== 200 || !str_starts_with((string) $contentType, 'image/')) {
            return null;
        }

        return $body;
    }

    /**
     * Plain average of an area's own vertices - not a true geometric
     * centroid for an irregular polygon, but good enough to place a
     * number label roughly inside the shapes areas are drawn as here
     * (small, close to convex, kayak-touring-scale polygons), without
     * pulling in a full centroid-of-polygon-by-signed-area formula for a
     * label position nobody needs pixel-perfect.
     *
     * @param list<array{lat: float, lng: float}> $polygon
     * @return array{lat: float, lng: float}
     */
    private static function polygonCentroid(array $polygon): array
    {
        $latSum = 0.0;
        $lngSum = 0.0;
        foreach ($polygon as $point) {
            $latSum += (float) $point['lat'];
            $lngSum += (float) $point['lng'];
        }
        $count = count($polygon);

        return ['lat' => $latSum / $count, 'lng' => $lngSum / $count];
    }

    /**
     * Google's own polyline encoding algorithm (used by the JS SDK too),
     * needed to keep the Static Maps URL well under its length limit for
     * longer recorded routes instead of passing literal lat,lng pairs.
     *
     * @param array<int,array{lat:float,lng:float}> $points
     */
    private static function encodePolyline(array $points): string
    {
        $encoded = '';
        $prevLat = 0;
        $prevLng = 0;

        foreach ($points as $point) {
            $lat = (int) round(((float) $point['lat']) * 1e5);
            $lng = (int) round(((float) $point['lng']) * 1e5);

            $encoded .= self::encodeSignedNumber($lat - $prevLat);
            $encoded .= self::encodeSignedNumber($lng - $prevLng);

            $prevLat = $lat;
            $prevLng = $lng;
        }

        return $encoded;
    }

    private static function encodeSignedNumber(int $num): string
    {
        $shifted = $num << 1;
        if ($num < 0) {
            $shifted = ~$shifted;
        }

        return self::encodeUnsignedNumber($shifted);
    }

    private static function encodeUnsignedNumber(int $num): string
    {
        $encoded = '';
        while ($num >= 0x20) {
            $encoded .= chr((0x20 | ($num & 0x1f)) + 63);
            $num >>= 5;
        }
        $encoded .= chr($num + 63);

        return $encoded;
    }
}
