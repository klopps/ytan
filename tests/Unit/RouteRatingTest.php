<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Ytan\Service\Ai\BriefingRules;
use Ytan\Service\Ai\RouteGeometry;
use Ytan\Service\Ai\RouteRating;

/**
 * The Routencheck's traffic light (RouteRating) on a synthetic 10 km route
 * heading north, with hand-made forecasts: thresholds, the level factor, lee/
 * exposed/offshore from the open water around the route, waves from the side,
 * darkness, missing data and the start hour windows.
 */
final class RouteRatingTest extends TestCase
{
    private const DATE = '2026-11-14';

    /** @param array<string, mixed> $hour values for every hour (km/h like the weather payload) */
    private function rating(array $hour, ?\Closure $openWater = null, float $levelFactor = 1.0, bool $withHours = true): RouteRating
    {
        $zone = new DateTimeZone('+01:00');
        $geometry = new RouteGeometry([[56.0, 10.0], [56.09, 10.0]]);
        $sections = array_map(fn (array $s) => $s + ['weather' => 0], $geometry->sections());
        $hourly = [];
        if ($withHours) {
            for ($h = 0; $h < 24; $h++) {
                $key = sprintf('%sT%02d:00', self::DATE, $h);
                $hourly[$key] = $hour + ['time' => $key, 'wind_speed' => 10.0, 'wind_gusts' => 15.0, 'wind_direction' => 270.0, 'weather_code' => 1];
            }
        }
        $forecasts = [[
            'hourly' => $hourly,
            'sun' => [self::DATE => [
                'sunrise' => new DateTimeImmutable(self::DATE . ' 07:30', $zone),
                'sunset' => new DateTimeImmutable(self::DATE . ' 16:30', $zone),
            ]],
        ]];

        return new RouteRating($sections, $forecasts, $openWater ?? fn () => null, BriefingRules::defaults(), $levelFactor, $zone);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable(self::DATE . ' ' . $time, new DateTimeZone('+01:00'));
    }

    private function criteria(array $result): array
    {
        return array_column($result['reasons'], 'criterion');
    }

    public function testCalmWeatherIsGreen(): void
    {
        $result = $this->rating([])->assess($this->at('10:00'), 6.0);

        $this->assertSame(0, $result['rating']);
        $this->assertFalse($result['partial']);
        $this->assertSame([], $result['reasons']);
        $this->assertCount(4, $result['sections']);
    }

    public function testWindAndGustThresholds(): void
    {
        $yellow = $this->rating(['wind_speed' => 6 * 3.6])->assess($this->at('10:00'), 6.0);
        $this->assertSame(1, $yellow['rating']);
        $this->assertSame('wind', $yellow['reasons'][0]['criterion']);
        $this->assertEquals(5.0, $yellow['reasons'][0]['threshold']);

        $red = $this->rating(['wind_gusts' => 12 * 3.6])->assess($this->at('10:00'), 6.0);
        $this->assertSame(2, $red['rating']);
        $this->assertSame('gust', $red['reasons'][0]['criterion']);
    }

    public function testThunderstormIsRed(): void
    {
        $result = $this->rating(['weather_code' => 95])->assess($this->at('10:00'), 6.0);

        $this->assertSame(2, $result['rating']);
        $this->assertContains('thunder', $this->criteria($result));
    }

    public function testDarknessAndDusk(): void
    {
        $this->assertSame('dark', $this->rating([])->assess($this->at('06:00'), 6.0)['reasons'][0]['criterion']);
        // 16:00 start, ~1:40 h for 10 km: ends after sunset + 30 min = red; starting 15:00 ends in the dusk.
        $this->assertSame(2, $this->rating([])->assess($this->at('16:00'), 6.0)['rating']);
        $dusk = $this->rating([])->assess($this->at('15:00'), 6.0);
        $this->assertSame(1, $dusk['rating']);
        $this->assertSame('dusk', $dusk['reasons'][0]['criterion']);
    }

    public function testWithoutForecastEverythingIsGrey(): void
    {
        $result = $this->rating([], null, 1.0, false)->assess($this->at('10:00'), 6.0);

        $this->assertNull($result['rating']);
        $this->assertTrue($result['partial']);
    }

    public function testTheLevelFactorScalesTheThresholds(): void
    {
        $hour = ['wind_speed' => 4 * 3.6];
        $this->assertSame(0, $this->rating($hour)->assess($this->at('10:00'), 6.0)['rating']);
        $this->assertSame(1, $this->rating($hour, null, 0.75)->assess($this->at('10:00'), 6.0)['rating']);
    }

    public function testLeeRelaxesAndOpenWaterTightens(): void
    {
        $hour = ['wind_speed' => 5.5 * 3.6];
        $this->assertSame(1, $this->rating($hour)->assess($this->at('10:00'), 6.0)['rating']);
        // land 300 m upwind (and downwind): thresholds x 1.2 = 6 m/s
        $this->assertSame(0, $this->rating($hour, fn () => 300)->assess($this->at('10:00'), 6.0)['rating']);

        $light = ['wind_speed' => 4.5 * 3.6];
        $this->assertSame(0, $this->rating($light)->assess($this->at('10:00'), 6.0)['rating']);
        // 10 km of open water upwind: thresholds x 0.8 = 4 m/s
        $exposed = $this->rating($light, fn () => 10000)->assess($this->at('10:00'), 6.0);
        $this->assertSame(1, $exposed['rating']);
        $this->assertTrue($exposed['sections'][0]['values']['exposed']);
    }

    public function testOffshoreWindIsAtLeastYellow(): void
    {
        // Wind from the west: land 500 m to the west, 20 km of open water to the east.
        $water = fn (array $position, float $bearing) => $bearing === 270.0 ? 500 : 20000;
        $result = $this->rating([], $water)->assess($this->at('10:00'), 6.0);

        $this->assertSame(1, $result['rating']);
        $this->assertSame('offshore', $result['reasons'][0]['criterion']);
        $this->assertTrue($result['sections'][0]['values']['offshore']);

        $windy = $this->rating(['wind_speed' => 6 * 3.6], $water)->assess($this->at('10:00'), 6.0);
        $this->assertSame(2, $windy['rating']);
    }

    public function testWavesFromTheSideRaiseTheLevel(): void
    {
        // Route heads north, wind and waves from the east = beam sea.
        $result = $this->rating(['wind_direction' => 90.0, 'wave_height' => 0.6])->assess($this->at('10:00'), 6.0);
        $this->assertSame(2, $result['rating']);
        $this->assertContains('beam_sea', $this->criteria($result));

        $ahead = $this->rating(['wind_direction' => 0.0, 'wave_height' => 0.6])->assess($this->at('10:00'), 6.0);
        $this->assertSame(1, $ahead['rating']);
    }

    public function testWavesAreCappedByTheFetch(): void
    {
        // The model says 1.2 m (red), but with 1 km of water upwind 5 m/s builds only ~0.08 m.
        $result = $this->rating(['wind_speed' => 4 * 3.6, 'wave_height' => 1.2], fn () => 1000)->assess($this->at('10:00'), 6.0);
        $this->assertSame(0, $result['rating']);
        $this->assertEqualsWithDelta(0.29, RouteRating::fetchLimitedWave(8.0, 5000), 0.01);
    }

    public function testHeadwindSlowsThePaddler(): void
    {
        $calm = $this->rating([])->assess($this->at('10:00'), 6.0);
        // 7 m/s from the north (straight ahead): 6 - 0.3 x 7 = 3.9 km/h
        $headwind = $this->rating(['wind_speed' => 7 * 3.6, 'wind_direction' => 0.0])->assess($this->at('10:00'), 6.0);

        $this->assertSame('11:40', $calm['end']->format('H:i'));
        $this->assertGreaterThan($calm['end'], $headwind['end']);
        $this->assertSame('12:33', $headwind['end']->format('H:i'));
    }

    public function testStartWindowsCoverTheDay(): void
    {
        $windows = $this->rating([])->windows(self::DATE, 6.0);

        $this->assertSame(range(6, 20), array_column($windows, 'hour'));
        $this->assertSame(2, $windows[0]['rating']);
        $this->assertSame('dark', $windows[0]['reason']);
        $this->assertSame(0, $windows[4]['rating']); // 10:00
    }
}
