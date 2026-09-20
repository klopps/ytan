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
    private const POI_ZOOM = 15;
    private const VALID_MAP_TYPES = ['hybrid', 'terrain', 'satellite'];

    public function __construct(
        private readonly string $cacheDir,
        private readonly string $apiKey,
    ) {
    }

    /**
     * Tour overview: every route drawn as its own polyline, viewport
     * auto-fit by Static Maps itself (no center/zoom passed) since only
     * path= params are supplied.
     *
     * @param array<int,array<int,array{lat:float,lng:float}>> $polylines
     */
    public function forRoutes(array $polylines, string $mapType): ?string
    {
        $params = [];
        foreach ($polylines as $polyline) {
            if (count($polyline) < 2) {
                continue;
            }
            $params[] = 'path=color:0x3388ffcc|weight:4|enc:' . rawurlencode(self::encodePolyline($polyline));
        }

        if ($params === []) {
            return null;
        }

        return $this->fetch($params, $mapType);
    }

    public function forPoint(float $lat, float $lng, string $mapType): ?string
    {
        $params = [
            'center=' . rawurlencode($lat . ',' . $lng),
            'zoom=' . self::POI_ZOOM,
            'markers=color:red|' . rawurlencode($lat . ',' . $lng),
        ];

        return $this->fetch($params, $mapType);
    }

    /**
     * Area outline, drawn as a closed path so Static Maps renders it as a
     * polygon; viewport auto-fit the same way forRoutes() is.
     *
     * @param array<int,array{lat:float,lng:float}> $polygon
     */
    public function forPolygon(array $polygon, string $mapType, string $color = '#00FF30'): ?string
    {
        if (count($polygon) < 3) {
            return null;
        }

        $closed = $polygon;
        $closed[] = $polygon[0];

        $fillColor = '0x' . ltrim($color, '#') . '33';
        $strokeColor = '0x' . ltrim($color, '#') . 'cc';

        $params = [
            'path=color:' . $strokeColor . '|weight:3|fillcolor:' . $fillColor . '|enc:' . rawurlencode(self::encodePolyline($closed)),
        ];

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
