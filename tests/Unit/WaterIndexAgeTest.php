<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Ytan\Service\Water\WaterIndexAge;

/** The age of the water index and its colour: green up to 12 months, yellow up to 13, red beyond. */
final class WaterIndexAgeTest extends TestCase
{
    private function now(string $time = '2026-11-20 12:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function testAFreshIndexIsGreenAndCountsFullMonths(): void
    {
        $age = WaterIndexAge::describe('202611140634', $this->now());
        $this->assertSame(['built_at' => '2026-11-14T06:34:00Z', 'age_days' => 6, 'age_months' => 0, 'state' => 'ok'], $age);

        $age = WaterIndexAge::describe('202603200000', $this->now());
        $this->assertSame([8, 'ok'], [$age['age_months'], $age['state']]);
    }

    /** @return array<string, array{string, string}> */
    public static function boundaries(): array
    {
        return [
            'exactly 12 months is still green' => ['202511201200', 'ok'],
            'a minute over 12 months is yellow' => ['202511201159', 'warn'],
            'one day over 12 months is yellow' => ['202511190000', 'warn'],
            'exactly 13 months is still yellow' => ['202510201200', 'warn'],
            'a minute over 13 months is red' => ['202510201159', 'old'],
            'two years is red' => ['202411200000', 'old'],
        ];
    }

    /** @dataProvider boundaries */
    public function testTheColourBoundaries(string $version, string $state): void
    {
        $this->assertSame($state, WaterIndexAge::describe($version, $this->now())['state']);
    }

    public function testMonthsAreCountedInFull(): void
    {
        $this->assertSame(12, WaterIndexAge::describe('202511201200', $this->now())['age_months']);
        $this->assertSame(11, WaterIndexAge::describe('202511211200', $this->now())['age_months']);
        $this->assertSame(13, WaterIndexAge::describe('202510201200', $this->now())['age_months']);
    }

    public function testTheTimeZoneOfTheCallerDoesNotMatter(): void
    {
        $berlin = new DateTimeImmutable('2026-11-20 13:00:00', new DateTimeZone('Europe/Berlin')); // = 12:00 UTC
        $this->assertSame('ok', WaterIndexAge::describe('202511201200', $berlin)['state']);
        $this->assertSame('warn', WaterIndexAge::describe('202511201159', $berlin)['state']);
    }

    public function testAVersionInTheFutureIsAsYoungAsItGets(): void
    {
        $age = WaterIndexAge::describe('202701010000', $this->now());
        $this->assertSame(['ok', 0, 0], [$age['state'], $age['age_days'], $age['age_months']]);
    }

    public function testNoIndexOrAVersionThatIsNoTimeGivesNothing(): void
    {
        foreach ([null, '', 'abc', '2026111', '20261114063', '2026111406345', '202613400000', '202611145999'] as $version) {
            $this->assertNull(WaterIndexAge::describe($version, $this->now()), var_export($version, true));
        }
    }
}
