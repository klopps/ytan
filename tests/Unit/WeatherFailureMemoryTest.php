<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Weather\WeatherFailureMemory;

final class WeatherFailureMemoryTest extends TestCase
{
    private string $dir;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-weather-failures-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function memory(): WeatherFailureMemory
    {
        return new WeatherFailureMemory($this->dir, fn () => $this->now);
    }

    public function testRemembersAFailedPointUntilTheTtlRunsOut(): void
    {
        $memory = $this->memory();
        $this->assertFalse($memory->recentlyFailed(54.35, 11.34));

        $memory->remember(54.35, 11.34);
        $this->assertTrue($memory->recentlyFailed(54.35, 11.34));
        $this->assertFalse($memory->recentlyFailed(54.36, 11.34), 'other points are not affected');

        $this->now += WeatherFailureMemory::TTL_SECONDS - 1;
        $this->assertTrue($this->memory()->recentlyFailed(54.35, 11.34), 'shared between instances (requests)');

        $this->now += 1;
        $this->assertFalse($memory->recentlyFailed(54.35, 11.34));
    }

    public function testWritingAFailureRemovesExpiredOnes(): void
    {
        $memory = $this->memory();
        $memory->remember(54.35, 11.34);
        $this->now += WeatherFailureMemory::TTL_SECONDS;
        $memory->remember(55.31, 14.99);

        $this->assertCount(1, glob($this->dir . '/*.fail'));
        $this->assertTrue($memory->recentlyFailed(55.31, 14.99));
    }

    public function testAnUnusableDirectoryRemembersNothingAndNeverThrows(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ytan');
        $memory = new WeatherFailureMemory($file . '/sub');

        $memory->remember(54.35, 11.34);

        $this->assertFalse($memory->recentlyFailed(54.35, 11.34));
        unlink($file);
    }
}
