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
    /**
     * After a request that got no connection at all (DNS, connect timeout),
     * further requests to the same host are answered with null at once for
     * this long - otherwise each of them waits for the connect timeout again
     * (a Routencheck asks for up to 5 forecast points, 2-4 calls each).
     */
    public const HOST_PAUSE_SECONDS = 60;

    /** @var array<string, int> host => paused until (unix time) */
    private array $pausedUntil = [];

    /** @var \Closure(string): array{status: int, body: ?string, error: string} */
    private \Closure $fetch;

    /** @var \Closure(string): void */
    private \Closure $log;

    /** @var \Closure(): int */
    private \Closure $now;

    /**
     * The closures are for tests only: $fetch replaces HttpFetch::request(),
     * $log replaces error_log(), $now replaces time().
     */
    public function __construct(?\Closure $fetch = null, ?\Closure $log = null, ?\Closure $now = null)
    {
        $this->fetch = $fetch ?? static fn (string $url): array => Http\HttpFetch::request($url, [], 4, 8);
        $this->log = $log ?? static function (string $line): void {
            error_log($line);
        };
        $this->now = $now ?? static fn (): int => time();
    }

    public function getJson(string $baseUrl, array $query): ?array
    {
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        if (($this->pausedUntil[$host] ?? 0) > ($this->now)()) {
            return null;
        }

        $result = ($this->fetch)($baseUrl . ($query === [] ? '' : '?' . http_build_query($query)));
        $status = $result['status'];
        if ($status !== 200 || $result['body'] === null) {
            // The query is never logged (coordinates, possibly keys).
            $target = $host . (string) parse_url($baseUrl, PHP_URL_PATH);
            if ($status === 0) {
                $this->pausedUntil[$host] = ($this->now)() + self::HOST_PAUSE_SECONDS;
                ($this->log)('YTAN HTTP: ' . $target . ' failed: no connection (' . $result['error']
                    . '), further requests to ' . $host . ' skipped for ' . self::HOST_PAUSE_SECONDS . ' s');
            } elseif ($status === 429 || $status >= 500) {
                ($this->log)('YTAN HTTP: ' . $target . ' failed: HTTP ' . $status);
            }

            return null;
        }

        $decoded = json_decode($result['body'], true);

        return is_array($decoded) ? $decoded : null;
    }
}
