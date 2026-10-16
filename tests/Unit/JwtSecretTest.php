<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ytan\Service\JwtSecret;

/**
 * php-jwt 7 needs HS256 keys of 32+ bytes: a long secret stays unchanged
 * (existing tokens stay valid), a short one is stretched, a missing one is
 * fatal (there used to be a public fallback secret).
 */
final class JwtSecretTest extends TestCase
{
    public function testLongSecretIsUsedUnchanged(): void
    {
        $secret = str_repeat('a', 32);
        $this->assertSame($secret, JwtSecret::key($secret));
    }

    public function testShortSecretIsStretchedTo64Characters(): void
    {
        $previous = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $key = JwtSecret::key('short');
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame(64, strlen($key));
        $this->assertNotSame('short', $key);
    }

    public function testMissingSecretIsFatal(): void
    {
        $this->expectException(RuntimeException::class);
        JwtSecret::key('');
    }
}
