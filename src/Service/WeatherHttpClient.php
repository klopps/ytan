<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Injection seam for WeatherService's outbound HTTP calls, so tests never
 * make a real network request - mirrors the "injectable dependency" spirit
 * of TranslationRepository's injectable $resourcesDir, applied to network
 * access instead of a filesystem path.
 */
interface WeatherHttpClient
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
