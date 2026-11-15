<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\App;
use Ytan\Exception\ConflictException;
use Ytan\Exception\NotFoundException;

final class AppErrorPayloadTest extends TestCase
{
    private string|false $previousLog;
    private string $log;

    protected function setUp(): void
    {
        // keep the deliberate error_log() lines out of the test output
        $this->log = tempnam(sys_get_temp_dir(), 'ytan-errlog-');
        $this->previousLog = ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousLog);
        @unlink($this->log);
    }

    public function testAnUnexpectedExceptionHidesItsMessageAndIsLogged(): void
    {
        [$status, $payload] = App::errorPayload(new \PDOException("SQLSTATE[42S22]: Unknown column 'secret_col' in 'user'"), false);

        $this->assertSame(500, $status);
        $this->assertSame(['error' => ['message' => 'Internal server error.']], $payload);
        $this->assertStringContainsString('secret_col', (string) file_get_contents($this->log));
    }

    public function testWithDebugTheRealMessageAndTraceAreSent(): void
    {
        [, $payload] = App::errorPayload(new \RuntimeException('boom'), true);

        $this->assertSame('boom', $payload['error']['message']);
        $this->assertIsArray($payload['error']['trace']);
    }

    public function testAnApiExceptionKeepsMessageCodeAndIsNotLogged(): void
    {
        [$status, $payload] = App::errorPayload(new NotFoundException('Route not found.', 'route.not_found'), false);

        $this->assertSame(404, $status);
        $this->assertSame(['message' => 'Route not found.', 'code' => 'route.not_found'], $payload['error']);
        $this->assertSame('', (string) file_get_contents($this->log));
    }

    public function testAPayloadExceptionMergesItsFields(): void
    {
        $conflict = new ConflictException(['id' => 7, 'updated_at' => '2026-11-15 10:00:00']);

        [$status, $payload] = App::errorPayload($conflict, false);

        $this->assertSame(409, $status);
        $this->assertSame(['id' => 7, 'updated_at' => '2026-11-15 10:00:00'], $payload['error']['server']);
        $this->assertSame($conflict->getMessage(), $payload['error']['message']);
    }
}
