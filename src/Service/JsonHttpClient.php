<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Injection seam for outbound HTTP+JSON GET calls (WeatherService,
 * GeocodingService), so tests never make a real network request - mirrors
 * the "injectable dependency" spirit of TranslationRepository's injectable
 * $resourcesDir, applied to network access instead of a filesystem path.
 *
 * Named generically (not WeatherHttpClient, its original name before
 * GeocodingService also needed the exact same "GET a URL, decode JSON,
 * null on any failure" contract) since nothing about the interface itself
 * is weather-specific.
 */
interface JsonHttpClient
{
    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>|null decoded JSON, or null on any
     *         failure (network error, non-2xx, invalid JSON) - callers
     *         decide what a failure means, this only reports "got JSON or
     *         didn't."
     */
    public function getJson(string $baseUrl, array $query): ?array;
}
