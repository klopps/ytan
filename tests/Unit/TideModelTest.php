<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Tide\OpenMeteoTideSource;
use Ytan\Service\Tide\TideService;
use Ytan\Service\Tide\TideSource;

/**
 * Open-Meteo's sea level curve to high/low waters, the tidal current phase
 * and the order of the tide sources.
 */
final class TideModelTest extends TestCase
{
    /** A semidiurnal tide (12 h 25 min, amplitude 1.5 m) as Open-Meteo's hourly rows, local time UTC+1. */
    private static function payload(int $hours = 48, float $amplitude = 1.5): array
    {
        $hourly = [];
        $start = strtotime('2026-11-15 00:00:00 UTC');
        for ($h = 0; $h < $hours; $h++) {
            $hourly[] = [
                'time' => gmdate('Y-m-d\TH:i', $start + $h * 3600),
                'tide_height' => round($amplitude * cos(2 * M_PI * $h / 12.4167), 3),
            ];
        }

        return ['utc_offset_seconds' => 3600, 'hourly' => $hourly];
    }

    public function testHighAndLowWatersFromTheHourlyCurve(): void
    {
        $events = OpenMeteoTideSource::extremes(OpenMeteoTideSource::series(self::payload()));

        // the high water in the very first hour is no extreme (no hour before it): low water first
        $this->assertSame(['NW', 'HW', 'NW', 'HW', 'NW', 'HW', 'NW'], array_column($events, 'type'));
        // the next high water 12.42 h after the first row ("00:00" local = 23:00 UTC the day before)
        $firstHw = strtotime('2026-11-15 00:00:00 UTC') - 3600 + (int) round(12.4167 * 3600);
        $this->assertEqualsWithDelta($firstHw, $events[1]['time'], 600, 'refined between the hours');
        $this->assertEqualsWithDelta(-1.5, $events[0]['height_m'], 0.05);
        $this->assertEqualsWithDelta(1.5, $events[1]['height_m'], 0.05);
    }

    public function testOpenMeteoAsksAtTheCoastAndGivesTheMeanRange(): void
    {
        $asked = [];
        $source = new OpenMeteoTideSource(function (float $lat, float $lng) use (&$asked) {
            $asked[] = [$lat, $lng];

            return self::payload();
        });

        $tide = $source->tide(53.54, 9.97, ['lat' => 53.87, 'lng' => 8.70], 0, PHP_INT_MAX, 15000);

        $this->assertSame([[53.87, 8.70]], $asked);
        $this->assertSame('open-meteo', $tide['source']);
        $this->assertEqualsWithDelta(3.0, $tide['mean_range_m'], 0.1);
        $this->assertGreaterThan(80000, $tide['distance_m']);
        $this->assertNull($source->tide(53.54, 9.97, null, 0, 1, 15000), 'no coast, nothing to ask');
    }

    public function testNoSeaLevelInAnEstuaryMeansNoData(): void
    {
        $source = new OpenMeteoTideSource(fn () => ['utc_offset_seconds' => 0, 'hourly' => [['time' => '2026-11-15T00:00', 'tide_height' => null]]]);

        $this->assertNull($source->tide(53.58, 9.66, ['lat' => 53.58, 'lng' => 9.66], 0, 1, 15000));
    }

    public function testAWobbleWithoutATideGivesASmallRange(): void
    {
        $source = new OpenMeteoTideSource(fn () => self::payload(48, 0.15));

        $this->assertLessThan(0.5, $source->tide(54.09, 12.13, ['lat' => 54.18, 'lng' => 12.11], 0, PHP_INT_MAX, 15000)['mean_range_m']);
    }

    public function testFloodFromLowToHighWaterAndEbbBack(): void
    {
        $events = [['time' => 0, 'type' => 'NW'], ['time' => 6 * 3600, 'type' => 'HW'], ['time' => 12 * 3600, 'type' => 'NW']];

        $flood = TideService::phase($events, 3 * 3600);
        $this->assertSame('flood', $flood['phase']);
        $this->assertSame(1.0, $flood['strength']);
        $this->assertFalse($flood['slack']);

        $ebb = TideService::phase($events, 9 * 3600);
        $this->assertSame('ebb', $ebb['phase']);

        $this->assertTrue(TideService::phase($events, 6 * 3600 + 600)['slack'], 'just after high water');
        $this->assertNull(TideService::phase($events, 13 * 3600), 'after the last event');
    }

    public function testTheCurrentTurnsLaterWithALag(): void
    {
        $events = [['time' => 0, 'type' => 'NW'], ['time' => 6 * 3600, 'type' => 'HW'], ['time' => 12 * 3600, 'type' => 'NW']];

        // 30 min after high water: without a lag already ebb, with a 1 h lag still flood
        $this->assertSame('ebb', TideService::phase($events, 6 * 3600 + 1800)['phase']);
        $this->assertSame('flood', TideService::phase($events, 6 * 3600 + 1800, 3600)['phase']);
    }

    public function testTheFirstSourceWithDataWins(): void
    {
        $none = new class implements TideSource {
            public int $calls = 0;

            public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
            {
                $this->calls++;

                return null;
            }
        };
        $some = new class implements TideSource {
            public function tide(float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStationMeters): ?array
            {
                return ['station' => 'X', 'source' => 'x', 'attribution' => 'x', 'lat' => 1.0, 'lng' => 2.0, 'distance_m' => 1, 'mean_range_m' => 2.0, 'events' => []];
            }
        };
        $service = new TideService([$none, $some]);

        $this->assertSame('X', $service->tide(1, 2, null, 0, 1, 1000)['station']);
        $service->tide(1, 2, null, 0, 1, 1000);
        $this->assertSame(1, $none->calls, 'remembered per position');
    }
}
