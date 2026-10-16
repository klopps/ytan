<?php

declare(strict_types=1);

namespace Ytan\Service;

use PDO;
use Ytan\Exception\TooManyRequestsException;

/**
 * Rate limits (security audit 2026-10-16): brute-force protection for POST
 * /auth/login and /auth/forgot-password, and a cap on the anonymous
 * endpoints that call external services. Counts attempts in the auth_attempt table
 * (migration 040) per identifier (username/email as typed, lowercased) and
 * per client IP within a time window, and refuses with 429 once a limit is
 * reached:
 * - login: failed attempts only - a successful login clears the
 *   identifier's failures; 10 per identifier / 50 per IP in 15 minutes
 *   (the IP limit is higher since several people may share one address);
 * - forgot: every request (each one sends an email) - 3 per identifier /
 *   10 per IP per hour, so the reset mail can't be used to flood a mailbox;
 * - geo: the anonymous weather/reverse-geocoding endpoints, every request,
 *   per IP only (identifier = the IP) - 120 per 10 minutes. Each new
 *   coordinate costs a request to Open-Meteo/Nominatim (whose usage policy
 *   can block the server's IP) plus a cache file / DB row.
 * The window slides: the oldest attempt dropping out lets the next one in.
 * The error code is the same for all ("auth.too_many_attempts", "Too many
 * attempts. Please wait a few minutes...").
 */
final class RequestThrottle
{
    public const LOGIN = 'login';
    public const FORGOT = 'forgot';
    public const GEO = 'geo';
    public const CSP_REPORT = 'csp';

    /** kind => [window seconds, max per identifier, max per IP] */
    private const LIMITS = [
        self::LOGIN => [900, 10, 50],
        self::FORGOT => [3600, 3, 10],
        self::GEO => [600, 120, 120],
        // Browser CSP violation reports - they only end up in the error
        // log, which must not be floodable.
        self::CSP_REPORT => [600, 30, 30],
    ];

    private const RETENTION_SECONDS = 86400;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @throws TooManyRequestsException */
    public function assertAllowed(string $kind, string $identifier, string $ip): void
    {
        [$window, $maxPerIdentifier, $maxPerIp] = self::LIMITS[$kind];
        $since = date('Y-m-d H:i:s', time() - $window);

        if ($this->count($kind, 'identifier', self::normalize($identifier), $since) >= $maxPerIdentifier
            || $this->count($kind, 'ip', $ip, $since) >= $maxPerIp) {
            throw new TooManyRequestsException();
        }
    }

    public function record(string $kind, string $identifier, string $ip): void
    {
        // Housekeeping on the way: nothing older than a day is ever counted.
        $this->db->prepare('DELETE FROM auth_attempt WHERE created_at < ?')
            ->execute([date('Y-m-d H:i:s', time() - self::RETENTION_SECONDS)]);

        $this->db->prepare('INSERT INTO auth_attempt (kind, identifier, ip, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$kind, self::normalize($identifier), mb_substr($ip, 0, 45), date('Y-m-d H:i:s')]);
    }

    /** After a successful login: the identifier's earlier failures no longer count. */
    /**
     * For a request that counts every time, per IP only (GEO): check, then
     * record. 429 once the IP is over its limit.
     *
     * @throws TooManyRequestsException
     */
    public function hit(string $kind, string $ip): void
    {
        $this->assertAllowed($kind, $ip, $ip);
        $this->record($kind, $ip, $ip);
    }

    public function clear(string $kind, string $identifier): void
    {
        $this->db->prepare('DELETE FROM auth_attempt WHERE kind = ? AND identifier = ?')
            ->execute([$kind, self::normalize($identifier)]);
    }

    private function count(string $kind, string $column, string $value, string $since): int
    {
        // $column is one of two fixed names, never user input.
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM auth_attempt WHERE kind = ? AND $column = ? AND created_at >= ?");
        $stmt->execute([$kind, $value, $since]);

        return (int) $stmt->fetchColumn();
    }

    private static function normalize(string $identifier): string
    {
        return mb_substr(mb_strtolower(trim($identifier)), 0, 190);
    }
}
