<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Tide\TideService;
use Ytan\Service\Tide\TideSource;
use Ytan\Service\Tide\WeatherTideOverlay;

/**
 * The weather timeline's tide curve from a national gauge: replaces
 * Open-Meteo's hourly tide_height, half cosine between high and low water,
 * heights relative to their mean, and leaves the payload alone without one.
 */
final class WeatherTideOverlayTest extends TestCase
{
    /** 12 hours of 2026-11-15 in local time UTC+1, Open-Meteo without marine data (an estuary). */
    private static function payload(): array
    {
        $hourly = [];
        for ($h = 0; $h < 12; $h++) {
            $hourly[] = ['time' => sprintf('2026-11-15T%02d:00', $h), 'wind_speed' => 10.0, 'tide_height' => null, 'wave_height' => null];
        }

        return ['utc_offset_seconds' => 3600, 'has_marine_data' => false, 'hourly' => $hourly];
    }

    /** @param list<array{time: int, type: string, height_m: ?float}>|null $events null = no gauge */
    private static function overlay(?array $events, ?float $range = 3.8): WeatherTideOverlay
    {
        $source = new class ($events, $range) implements TideSource {
            public array $asked = [];

            public function __construct(private ?array $events, private ?float $range)
            {
            }

            public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
            {
                $this->asked[] = [$from, $to, $maxStationMeters];

                return $this->events === null ? null : [
                    'station' => 'Hamburg, St. Pauli, Elbe', 'source' => 'bsh', 'attribution' => '©BSH',
                    'lat' => 53.5456, 'lng' => 9.97, 'distance_m' => 300, 'mean_range_m' => $this->range, 'events' => $this->events,
                ];
            }
        };

        return new WeatherTideOverlay(new TideService([$source]));
    }

    public function testTheGaugeReplacesTheCurve(): void
    {
        // low water 01:00 local (00:00 UTC) 3.5 m, high water 07:00 local 7.3 m, low water 13:00 local 3.5 m
        $base = strtotime('2026-11-15 00:00:00 UTC');
        $result = self::overlay([
            ['time' => $base, 'type' => 'NW', 'height_m' => 3.5],
            ['time' => $base + 6 * 3600, 'type' => 'HW', 'height_m' => 7.3],
            ['time' => $base + 12 * 3600, 'type' => 'NW', 'height_m' => 3.5],
        ])->apply(self::payload(), 53.546, 9.966, 15000);

        $heights = array_column($result['hourly'], 'tide_height', 'time');
        $this->assertNull($heights['2026-11-15T00:00'], 'before the first event');
        $this->assertEqualsWithDelta(-1.27, $heights['2026-11-15T01:00'], 0.01, 'low water, relative to the mean of 3.5/7.3/3.5');
        $this->assertEqualsWithDelta(2.53, $heights['2026-11-15T07:00'], 0.01, 'high water');
        $this->assertEqualsWithDelta(0.63, $heights['2026-11-15T04:00'], 0.01, 'half tide = halfway between low and high');
        $this->assertTrue($result['has_marine_data'], 'the tide row is shown even without Open-Meteo marine data');
        $this->assertSame('©BSH', $result['tide_source']['attribution']);
        $this->assertSame('Hamburg, St. Pauli, Elbe', $result['tide_source']['station']);
    }

    public function testWithoutAGaugeTheOpenMeteoCurveStays(): void
    {
        $payload = self::payload();
        $payload['has_marine_data'] = true;
        $payload['hourly'][3]['tide_height'] = 0.42;

        $this->assertSame($payload, self::overlay(null)->apply($payload, 54.0, 11.0, 15000));
    }

    public function testASmallRangeKeepsOpenMeteosCurveUnlessItHasNone(): void
    {
        $base = strtotime('2026-11-15 00:00:00 UTC');
        $baltic = [
            ['time' => $base, 'type' => 'NW', 'height_m' => 4.95],
            ['time' => $base + 6 * 3600, 'type' => 'HW', 'height_m' => 5.07],
        ];
        $payload = self::payload();
        $payload['has_marine_data'] = true;
        $payload['hourly'][3]['tide_height'] = -0.21;

        $this->assertSame($payload, self::overlay($baltic, 0.12)->apply($payload, 54.18, 12.09, 15000, 0.5), 'Open-Meteo with its wind set-up stays');

        $estuary = self::overlay($baltic, 0.12)->apply(self::payload(), 54.6, 9.9, 15000, 0.5);
        $this->assertSame('©BSH', $estuary['tide_source']['attribution'], 'no Open-Meteo curve: the gauge is better than nothing');
    }

    public function testEventsWithoutHeightsUseHalfTheMeanRange(): void
    {
        $base = strtotime('2026-11-15 00:00:00 UTC');
        $result = self::overlay([
            ['time' => $base, 'type' => 'NW', 'height_m' => null],
            ['time' => $base + 6 * 3600, 'type' => 'HW', 'height_m' => null],
        ], 3.8)->apply(self::payload(), 53.5, 10.0, 15000);

        $heights = array_column($result['hourly'], 'tide_height', 'time');
        $this->assertEqualsWithDelta(-1.9, $heights['2026-11-15T01:00'], 0.001);
        $this->assertEqualsWithDelta(1.9, $heights['2026-11-15T07:00'], 0.001);

        $this->assertArrayNotHasKey('tide_source', self::overlay([
            ['time' => $base, 'type' => 'NW', 'height_m' => null],
            ['time' => $base + 6 * 3600, 'type' => 'HW', 'height_m' => null],
        ], null)->apply(self::payload(), 53.5, 10.0, 15000), 'no heights, no range: nothing usable');
    }
}
