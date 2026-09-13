<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Reverse-geocodes a coordinate to a short place name via Nominatim
 * (OpenStreetMap's free reverse-geocoding API, nominatim.org - no API key,
 * but a real User-Agent is required and a strict ~1 request/second usage
 * policy applies: https://operations.osmfoundation.org/policies/nominatim/).
 * Backs GET /api/v1/geocode/reverse, used by the weather timeline
 * (public/js/weather.js) to show a place name instead of raw coordinates
 * in its title.
 *
 * A disk cache (mirroring WeatherService's own pattern) keeps real load on
 * Nominatim far below their rate limit even under concurrent use, and
 * place names change far less often than weather - hence the much longer
 * TTL than WeatherService's.
 *
 * Never throws: a failed request, an error response, or a genuinely
 * unnamed point (open ocean far from any coastline) all just resolve to
 * null - the frontend falls back to showing plain coordinates, exactly
 * like it did before this feature existed. There is no equivalent of
 * WeatherService's "general forecast must always succeed" case here,
 * since a place name is always a nice-to-have, never core data.
 */
final class GeocodingService
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/reverse';

    // A finer grid than WeatherService's 2 decimals (~1.1km) - place names
    // (e.g. a small hamlet vs. its neighbour further down a coastline) can
    // legitimately differ over a much shorter distance than weather does.
    private const COORD_PRECISION = 3;

    // Place names essentially never change - a long TTL keeps real load on
    // Nominatim's shared, rate-limited service minimal.
    private const CACHE_TTL_SECONDS = 30 * 24 * 3600;

    // Nominatim's own "zoom" parameter for reverse geocoding: how detailed
    // the matched feature should be (3=country, 10=city, 14=suburb/hamlet,
    // 18=building). 14 favours a small named place (relevant for a kayak
    // touring app, where most points are villages/hamlets, not cities)
    // while still falling back to a bigger enclosing area (region/country)
    // when no smaller feature exists at that point - confirmed live for an
    // inland hamlet, a stretch of open coastal water, and open sea further
    // from any coast.
    private const ZOOM = 14;

    public function __construct(
        private readonly string $cacheDir,
        private readonly JsonHttpClient $httpClient,
        private readonly string $locale = 'en',
    ) {
    }

    public function reverseGeocode(float $lat, float $lng): ?string
    {
        [$roundedLat, $roundedLng] = $this->roundCoordinates($lat, $lng);
        $file = $this->cacheFilePath($roundedLat, $roundedLng);

        $cached = $this->readCache($file);
        if ($cached !== false) {
            return $cached;
        }

        $placeName = $this->fetchPlaceName($roundedLat, $roundedLng);
        $this->writeCache($file, $placeName);

        return $placeName;
    }

    private function fetchPlaceName(float $lat, float $lng): ?string
    {
        $response = $this->httpClient->getJson(self::NOMINATIM_URL, [
            'format' => 'jsonv2',
            'lat' => $lat,
            'lon' => $lng,
            'zoom' => self::ZOOM,
            'accept-language' => $this->locale,
        ]);

        if ($response === null || isset($response['error'])) {
            return null;
        }

        // "name" is Nominatim's own label for the best-matched feature
        // (e.g. "Lyø By", "Kungsbacka kommun", "Danmark") - exactly the
        // short "Ortsbezeichnung" this is for, unlike "display_name"
        // (a full comma-joined address) which would be too long for a
        // panel title.
        if (isset($response['name']) && is_string($response['name']) && $response['name'] !== '') {
            return $response['name'];
        }

        if (isset($response['display_name']) && is_string($response['display_name']) && $response['display_name'] !== '') {
            return $response['display_name'];
        }

        return null;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function roundCoordinates(float $lat, float $lng): array
    {
        return [round($lat, self::COORD_PRECISION), round($lng, self::COORD_PRECISION)];
    }

    private function cacheFilePath(float $lat, float $lng): string
    {
        return $this->cacheDir . '/geocode_' . $lat . '_' . $lng . '.json';
    }

    /**
     * @return string|null|false false = cache miss (file missing, corrupt,
     *         or expired); null/string = a cached "no place name found"
     *         result or a cached place name, both valid cache hits that
     *         must NOT trigger a re-fetch
     */
    private function readCache(string $file): string|null|false
    {
        if (!is_file($file)) {
            return false;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || !array_key_exists('fetched_at', $decoded) || !array_key_exists('place_name', $decoded)) {
            return false;
        }

        if (time() - (int) $decoded['fetched_at'] > self::CACHE_TTL_SECONDS) {
            return false;
        }

        return $decoded['place_name'];
    }

    private function writeCache(string $file, ?string $placeName): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }

        $payload = json_encode(['fetched_at' => time(), 'place_name' => $placeName], JSON_UNESCAPED_UNICODE);
        file_put_contents($file, $payload);
    }
}
