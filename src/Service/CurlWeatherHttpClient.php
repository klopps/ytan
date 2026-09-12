<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Real WeatherHttpClient - plain curl, no HTTP client dependency needed for
 * two simple GETs (composer.json has no Guzzle/PSR-18 client, and nothing
 * else in this app makes outbound HTTP calls). Short timeouts so a hung
 * upstream (Open-Meteo) never hangs the map's request cycle. Never throws -
 * every failure mode (network error, non-200, invalid JSON) just returns
 * null; WeatherService decides what a null means for each endpoint.
 */
final class CurlWeatherHttpClient implements WeatherHttpClient
{
    public function getJson(string $baseUrl, array $query): ?array
    {
        $url = $baseUrl . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
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
