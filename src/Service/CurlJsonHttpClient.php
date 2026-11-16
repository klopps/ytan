<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Real JsonHttpClient - plain curl, no HTTP client dependency needed for a
 * handful of simple GETs (composer.json has no Guzzle/PSR-18 client, and
 * nothing else in this app makes outbound HTTP calls). Short timeouts so a
 * hung upstream (Open-Meteo, Nominatim) never hangs the map's request
 * cycle. Never throws - every failure mode (network error, non-200,
 * invalid JSON) just returns null; the calling service decides what a
 * null means.
 *
 * A descriptive User-Agent is set unconditionally, not just for
 * Nominatim's calls - Nominatim's usage policy requires one identifying
 * the application (https://operations.osmfoundation.org/policies/nominatim/),
 * and Open-Meteo doesn't care either way, so one shared client can serve
 * both without needing per-call options.
 */
final class CurlJsonHttpClient implements JsonHttpClient
{
    public function getJson(string $baseUrl, array $query): ?array
    {
        $body = Http\HttpFetch::body($baseUrl . '?' . http_build_query($query), [], 4, 8);
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
