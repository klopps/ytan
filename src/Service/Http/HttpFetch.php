<?php

declare(strict_types=1);

namespace Ytan\Service\Http;

/**
 * The one place that talks curl for YTAN's outgoing requests (Overpass, the
 * NGA web service, tide and weather sources, geocoding, static maps, the
 * shelter lists) - until 2026-11-15 each client had its own copy of the
 * curl setup and of the User-Agent. The clients keep their own timeouts and
 * their own idea of an error (null, an exception, a retry); this only does
 * the request.
 *
 * Defaults: no redirects, gzip accepted, YTAN's identifying User-Agent
 * (Overpass, Nominatim and the NGA ask automated clients for one).
 */
final class HttpFetch
{
    public const USER_AGENT = 'YTAN/1.0 (+https://ytan.pesr.org; no-reply@pesr.org)';

    /**
     * @param array<int, mixed> $options extra/overriding CURLOPT_* settings
     * @return array{status: int, body: ?string, error: string, content_type: string}
     *         status 0 = no answer; body null when curl failed
     */
    public static function request(string $url, array $options = [], int $connectTimeout = 4, int $timeout = 12): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);
        $body = curl_exec($ch);
        $failed = curl_errno($ch) !== 0 || !is_string($body);

        return [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body' => $failed ? null : $body,
            'error' => curl_error($ch),
            'content_type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        ];
    }

    /** The body of a 200 answer, null for anything else (no answer, an error status, a curl failure). */
    public static function body(string $url, array $options = [], int $connectTimeout = 4, int $timeout = 12): ?string
    {
        $result = self::request($url, $options, $connectTimeout, $timeout);

        return $result['status'] === 200 ? $result['body'] : null;
    }
}
