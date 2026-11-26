<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\GeometryService;
use Ytan\Service\Water\Autorouter;
use Ytan\Service\Water\WaterReach;
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
    }
}
