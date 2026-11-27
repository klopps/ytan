<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\GeometryService;
use Ytan\Service\Water\Autorouter;
use Ytan\Service\Water\ReachConditions;
use Ytan\Service\Water\WaterReach;
use Ytan\Service\Wind\WindField;
use Ytan\Tests\Fixtures\FakeShoreSource;

final class WaterReachTest extends TestCase
{
    private static function reach(FakeShoreSource $source): WaterReach
    {
        return new WaterReach($source, new Autorouter($source));
    }

    /** @return list<array{lat: float, lng: float}> every point of the main (etmal) line */
    private static function mainLine(array $result): array
    {
        $main = array_values(array_filter($result['lines'], static fn (array $l): bool => $l['main']));
        self::assertCount(1, $main);

        return array_merge(...$main[0]['paths']);
    }

    public function testLevelsAreEveryFiveKmUpToThirtyAndEveryTenAbove(): void
    {
        $this->assertSame([5.0, 10.0, 15.0, 20.0, 25.0], WaterReach::levels(25.0));
        $this->assertSame([5.0], WaterReach::levels(5.0));
        $this->assertSame([5.0, 7.0], WaterReach::levels(7.0));
        $this->assertSame([10.0, 20.0, 30.0, 32.0], WaterReach::levels(32.0));
        $this->assertSame([10.0, 20.0, 30.0, 40.0, 50.0, 60.0], WaterReach::levels(60.0));
    }

    public function testOpenWaterGivesCirclesOfTheRightRadius(): void
    {
        $result = self::reach(new FakeShoreSource([]))->reach(55.0, 10.0, 10.0);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([5.0, 10.0], array_column($result['lines'], 'km'));
        foreach ($result['lines'] as $line) {
            $this->assertCount(1, $line['paths'], 'one closed ring per level');
            $ring = $line['paths'][0];
            $this->assertEquals($ring[0], $ring[count($ring) - 1], 'closed');
            $this->assertGreaterThan(20, count($ring), 'smooth enough to look round');
            foreach ($ring as $point) {
                $distance = GeometryService::distanceMeters(55.0, 10.0, $point['lat'], $point['lng']) / 1000;
                $this->assertEqualsWithDelta($line['km'], $distance, 0.04 * $line['km'], 'on the circle');
            }
        }
    }

    public function testAnIslandBetweenPushesTheLineBackBehindIt(): void
    {
        // a block 2.2-3.3 km north of the start, 2.5 km wide: straight north the way goes round it
        $source = FakeShoreSource::island(55.02, 9.98, 55.03, 10.02);
        $result = self::reach($source)->reach(55.0, 10.0, 5.0);

        $this->assertSame('ok', $result['status']);
        $behind = [];
        foreach (self::mainLine($result) as $point) {
            $onIsland = $point['lat'] > 55.0201 && $point['lat'] < 55.0299 && $point['lng'] > 9.9801 && $point['lng'] < 10.0199;
            $this->assertFalse($onIsland, 'no line point on the land');
            if ($point['lat'] > 55.03 && abs($point['lng'] - 10.0) < 0.006) {
                $behind[] = GeometryService::distanceMeters(55.0, 10.0, $point['lat'], $point['lng']) / 1000;
            }
        }
        $this->assertNotEmpty($behind, 'the line goes on behind the island');
        $this->assertLessThan(4.6, max($behind), 'behind the island less is reached than straight 5 km');
        // beside the island (east) the full 5 km
        $east = array_filter(self::mainLine($result), static fn (array $p): bool => abs($p['lat'] - 55.0) < 0.003 && $p['lng'] > 10.0);
        $this->assertNotEmpty($east);
        foreach ($east as $point) {
            $this->assertEqualsWithDelta(5.0, GeometryService::distanceMeters(55.0, 10.0, $point['lat'], $point['lng']) / 1000, 0.2);
        }
    }

    public function testAStartOnLandOrOutsideTheIndexIsRefused(): void
    {
        $this->assertSame('on_land', self::reach(FakeShoreSource::island(55.0, 10.0, 55.05, 10.1))->reach(55.025, 10.05, 10.0)['status']);
        $this->assertSame('not_covered', self::reach(new FakeShoreSource([], false))->reach(55.0, 10.0, 10.0)['status']);
        $this->assertSame('on_land', self::reach(FakeShoreSource::island(55.0, 10.0, 55.05, 10.1))
            ->reachTimed(55.025, 10.05, 1_800_000_000, 3600, 6.0, self::conditions())['status']);
    }

    private const START = 1_800_000_000;

    /**
     * @param (\Closure(float, float): ?array)|null $tidal
     */
    private static function conditions(?WindField $wind = null, float $riverKmh = 0.0, float $tidalKmh = 0.0, ?\Closure $tidal = null): ReachConditions
    {
        return new ReachConditions(fn (): ?WindField => $wind, $tidal, 0.3, $riverKmh, $tidalKmh, 0, 150.0);
    }

    /** Distance (km) from the start to the farthest point of a line in a direction (0 north, 90 east, ...), within ±$spread degrees. */
    private static function reachTowards(array $line, float $bearing, float $spread = 4.0): float
    {
        $best = 0.0;
        foreach (array_merge(...$line['paths']) as $point) {
            $dy = ($point['lat'] - 55.0) * 111320.0;
            $dx = ($point['lng'] - 10.0) * 111320.0 * cos(deg2rad(55.0));
            $direction = fmod(rad2deg(atan2($dx, $dy)) + 360.0, 360.0);
            $off = abs(fmod($direction - $bearing + 540.0, 360.0) - 180.0);
            if ($off <= $spread) {
                $best = max($best, hypot($dx, $dy) / 1000);
            }
        }

        return $best;
    }

    /** A river along 55.0 N (downstream = east) in a channel between land north and south of it. */
    private static function riverChannel(): FakeShoreSource
    {
        return new FakeShoreSource(array_merge(
            [[1, 55.0, 9.7, 55.0, 10.3]],
            FakeShoreSource::ring(55.0012, 9.6, 55.1, 10.4),
            FakeShoreSource::ring(54.9, 9.6, 54.9988, 10.4),
        ));
    }

    public function testTimedWithoutWindOrCurrentGivesCirclesOfSpeedTimesHours(): void
    {
        $result = self::reach(new FakeShoreSource([]))->reachTimed(55.0, 10.0, self::START, 7200, 6.0, self::conditions());

        $this->assertSame('ok', $result['status']);
        $this->assertSame([1.0, 2.0], array_column($result['lines'], 'hours'));
        $this->assertSame([false, true], array_column($result['lines'], 'main'));
        $this->assertNull($result['wind']);
        foreach ($result['lines'] as $line) {
            $this->assertCount(1, $line['paths']);
            foreach ($line['paths'][0] as $point) {
                $distance = GeometryService::distanceMeters(55.0, 10.0, $point['lat'], $point['lng']) / 1000;
                // 32 directions: within ~2 % of the circle
                $this->assertEqualsWithDelta(6.0 * $line['hours'], $distance, 0.025 * 6.0 * $line['hours'], 'on the circle');
            }
        }
    }

    public function testATailwindHelpsAndAHeadwindSlowsDown(): void
    {
        // 5 m/s from the west (blowing east): 0.3 km/h per m/s -> 7.5 km/h east, 4.5 km/h west, 6 km/h north/south
        $wind = WindField::uniform(5.0, 0.0, self::START - 1800, 4);
        $result = self::reach(new FakeShoreSource([]))->reachTimed(55.0, 10.0, self::START, 3600, 6.0, self::conditions($wind));

        $this->assertSame('ok', $result['status']);
        $line = $result['lines'][0];
        $this->assertTrue($line['main']);
        $this->assertEqualsWithDelta(7.5, self::reachTowards($line, 90.0), 0.2);
        $this->assertEqualsWithDelta(4.5, self::reachTowards($line, 270.0), 0.2);
        $this->assertEqualsWithDelta(6.0, self::reachTowards($line, 0.0), 0.25);
        $this->assertSame(270, $result['wind']['direction']);
        $this->assertSame(5.0, $result['wind']['speed_ms']);
    }

    public function testTheSpeedThroughTheWaterStaysWithinItsLimits(): void
    {
        // 30 m/s from the west: east at most 1.5 x 6 = 9 km/h, west at least 0.3 x 6 = 1.8 km/h
        $wind = WindField::uniform(30.0, 0.0, self::START, 2);
        $line = self::reach(new FakeShoreSource([]))->reachTimed(55.0, 10.0, self::START, 3600, 6.0, self::conditions($wind))['lines'][0];

        $this->assertEqualsWithDelta(9.0, self::reachTowards($line, 90.0), 0.25);
        $this->assertEqualsWithDelta(1.8, self::reachTowards($line, 270.0), 0.15);
    }

    public function testARiverCurrentCarriesDownstreamAndBrakesUpstream(): void
    {
        // a river drawn west -> east (= downstream to the east) through the start, in a
        // channel ~260 m wide between two land blocks (on open water the paddler would
        // simply go round the current's strip)
        $source = self::riverChannel();
        $result = self::reach($source)->reachTimed(55.0, 10.0, self::START, 3600, 6.0, self::conditions(null, 3.0));

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['current']['river']);
        $line = $result['lines'][0];
        $this->assertEqualsWithDelta(9.0, self::reachTowards($line, 90.0, 1.0), 0.3, 'downstream: 6 + 3 km/h');
        $this->assertEqualsWithDelta(3.0, self::reachTowards($line, 270.0, 3.0), 0.3, 'upstream: 6 - 3 km/h');
    }

    public function testATidalRiverFlowsUpstreamDuringTheFlood(): void
    {
        // low water 1 h before the start, high water 5 h after: flood the whole hour = upstream (west)
        $tide = ['station' => 'Testpegel', 'source' => 'test', 'attribution' => '', 'lat' => 55.0, 'lng' => 10.0, 'distance_m' => 0, 'mean_range_m' => 3.0,
            'events' => [['time' => self::START - 3600, 'type' => 'NW', 'height_m' => null], ['time' => self::START + 5 * 3600, 'type' => 'HW', 'height_m' => null]]];
        $result = self::reach(self::riverChannel())->reachTimed(55.0, 10.0, self::START, 3600, 6.0,
            self::conditions(null, 2.0, 3.0, fn (): array => ['tide' => $tide]));

        $this->assertSame('ok', $result['status']);
        $this->assertFalse($result['current']['river'], 'the river is tidal, no river current');
        $this->assertTrue($result['current']['tidal']);
        $this->assertSame('Testpegel', $result['current']['tides'][0]['station']);
        $line = $result['lines'][0];
        $this->assertGreaterThan(self::reachTowards($line, 90.0, 1.0) + 2.0, self::reachTowards($line, 270.0, 1.0));
    }

    public function testTimedLinesGoRoundAnIsland(): void
    {
        $source = FakeShoreSource::island(55.02, 9.98, 55.03, 10.02);
        $result = self::reach($source)->reachTimed(55.0, 10.0, self::START, 3000, 6.0, self::conditions());

        $this->assertSame('ok', $result['status']);
        foreach (array_merge(...$result['lines'][0]['paths']) as $point) {
            $onIsland = $point['lat'] > 55.0201 && $point['lat'] < 55.0299 && $point['lng'] > 9.9801 && $point['lng'] < 10.0199;
            $this->assertFalse($onIsland, 'no line point on the land');
        }
        $this->assertLessThan(4.6, self::reachTowards($result['lines'][0], 0.0, 2.0), 'behind the island less than 5 km');
    }
}
