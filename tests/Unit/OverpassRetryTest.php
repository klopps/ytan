<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ytan\Exception\OverpassException;
use Ytan\Service\Overpass\OverpassRetry;

/**
 * OverpassRetry: an OverpassException is retried up to 3 times 30 s apart,
 * every pending retry is logged, the final failure says "GAVE UP", other
 * errors are not retried.
 */
final class OverpassRetryTest extends TestCase
{
    public function testRetriesUntilItWorksAndLogsEachPendingRetry(): void
    {
        $calls = 0;
        $lines = [];
        $slept = [];
        $result = OverpassRetry::logged(function () use (&$calls) {
            if (++$calls < 3) {
                throw new OverpassException('Overpass API not available (overpass-api.de: HTTP 502).');
            }

            return 'ok';
        }, function (string $line) use (&$lines) {
            $lines[] = $line;
        }, 'load OpenStreetMap FR tile 3/20', function (int $s) use (&$slept) {
            $slept[] = $s;
        });

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
        $this->assertSame([30, 30], $slept);
        $this->assertSame([
            'load OpenStreetMap FR tile 3/20: retry 1/3 pending in 30 s after: Overpass API not available (overpass-api.de: HTTP 502).',
            'load OpenStreetMap FR tile 3/20: retry 2/3 pending in 30 s after: Overpass API not available (overpass-api.de: HTTP 502).',
        ], $lines);
    }

    public function testGivesUpAfterThreeRetries(): void
    {
        $calls = 0;
        $lines = [];
        try {
            OverpassRetry::logged(function () use (&$calls) {
                $calls++;
                throw new OverpassException('busy');
            }, function (string $line) use (&$lines) {
                $lines[] = $line;
            }, 'x', fn (int $s) => null);
            $this->fail('Expected an exception.');
        } catch (OverpassException $e) {
            $this->assertSame('GAVE UP after 4 attempt(s): busy', $e->getMessage());
        }
        $this->assertSame(4, $calls);
        $this->assertCount(3, $lines);
    }

    public function testOtherErrorsAreNotRetried(): void
    {
        $calls = 0;
        $this->expectException(RuntimeException::class);
        try {
            OverpassRetry::logged(function () use (&$calls) {
                $calls++;
                throw new RuntimeException('database gone');
            }, fn (string $line) => null, 'x', fn (int $s) => null);
        } finally {
            $this->assertSame(1, $calls);
        }
    }
}
