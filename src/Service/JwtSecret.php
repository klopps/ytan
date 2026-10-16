<?php

declare(strict_types=1);

namespace Ytan\Service;

use RuntimeException;

/**
 * The HMAC key for every JWT this app signs (login tokens, AuthService;
 * captcha challenges, CaptchaService), from .env's JWT_SECRET.
 *
 * firebase/php-jwt 7 refuses HS256 keys shorter than 32 bytes
 * (CVE-2025-45769). A secret of that length or more is used unchanged, so
 * tokens issued before the upgrade stay valid. A shorter one is stretched
 * with SHA-256 (64 hex characters) instead of failing every login - all
 * existing tokens become invalid once (everybody signs in again) and a
 * warning is logged: replace it with a long random value
 * (php -r "echo bin2hex(random_bytes(32));").
 *
 * A missing/empty secret is fatal: the old fallback "insecure-dev-secret"
 * is public, so anybody could have forged an admin token with it.
 */
final class JwtSecret
{
    public const MIN_BYTES = 32;

    public static function key(?string $secret): string
    {
        if ($secret === null || $secret === '') {
            throw new RuntimeException('JWT_SECRET is not set in .env - generate one with: php -r "echo bin2hex(random_bytes(32));"');
        }
        if (strlen($secret) >= self::MIN_BYTES) {
            return $secret;
        }

        error_log('YTAN: JWT_SECRET is shorter than ' . self::MIN_BYTES . ' bytes - using a derived key; replace it with a long random value.');

        return hash('sha256', 'ytan-jwt|' . $secret);
    }
}
