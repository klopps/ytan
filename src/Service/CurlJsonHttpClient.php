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
    private const USER_AGENT = 'YTAN/1.0 (+https://ytan.pesr.org; no-reply@pesr.org)';

    public function getJson(string $baseUrl, array $query): ?array
    {
        $url = $baseUrl . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        $body = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $body === false || $statusCode !== 200) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
