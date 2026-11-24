<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\GeometryService;
use Ytan\Service\Water\Autorouter;
use Ytan\Service\Water\ShoreField;
use Ytan\Tests\Fixtures\FakeShoreSource;

final class AutorouterTest extends TestCase
{
    // land block: 55.0-55.1 N, 10.0-10.1 E (about 11 km x 6.4 km), water all around
    private const SOUTH = 55.0;
    private const WEST = 10.0;
    private const NORTH = 55.1;
    private const EAST = 10.1;

    private static function island(): FakeShoreSource
    {
        return FakeShoreSource::island(self::SOUTH, self::WEST, self::NORTH, self::EAST);
    }

    /** Metres from a position to the land block (0 = on it). */
    private static function distanceToLand(float $lat, float $lng): float
    {
        $nearLat = max(self::SOUTH, min(self::NORTH, $lat));
        $nearLng = max(self::WEST, min(self::EAST, $lng));

        return GeometryService::distanceMeters($lat, $lng, $nearLat, $nearLng);
    }

    /** Positions of the polyline every ~20 m. */
    private static function walk(array $points): array
    {
        $out = [];
        for ($i = 1; $i < count($points); $i++) {
            $steps = max(1, (int) ceil(GeometryService::distanceMeters($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]) / 20));
            for ($s = 0; $s < $steps; $s++) {
                $t = $s / $steps;
                $out[] = [$points[$i - 1][0] + $t * ($points[$i][0] - $points[$i - 1][0]), $points[$i - 1][1] + $t * ($points[$i][1] - $points[$i - 1][1])];
            }
        }
        $out[] = $points[count($points) - 1];

        return $out;
    }

    public function testWaterSideIsRightOfTheShoreLine(): void
    {
        $field = new ShoreField(FakeShoreSource::ring(self::SOUTH, self::WEST, self::NORTH, self::EAST), 55.05, 10.05, 1000.0);

        $this->assertTrue($field->probe(54.9995, 10.05, 500.0)['water'], 'south of the south shore');
        $this->assertFalse($field->probe(55.0005, 10.05, 500.0)['water'], 'inside the land');
        $this->assertTrue($field->probe(54.9995, 9.9995, 500.0)['water'], 'beside the convex corner (south-west)');
        $this->assertTrue($field->probe(54.9995, 10.1005, 500.0)['water'], 'beside the convex corner (south-east)');
        $this->assertNull($field->probe(55.05, 10.05, 100.0), 'no shore within the radius');
    }

    public function testCheckAcceptsWaterAndALaunchBeside(): void
    {
        $router = new Autorouter(self::island());

        $this->assertSame([], $router->check([[54.99, 9.95], [54.99, 10.15]]));
        // 30 m inside the land: a beach/slipway the index draws as land
        $this->assertSame([], $router->check([[55.00027, 10.05], [54.99, 10.15]]));
    }

    public function testLandMetersCountsOnlyTheStretchOverLand(): void
    {
        $router = new Autorouter(self::island());

        $this->assertSame(0.0, $router->landMeters([[54.99, 9.95], [54.99, 10.15]]), 'along the south coast: water only');
        // across the block at 55.05 N: 0.1 degrees of longitude = ~6.4 km
        $over = $router->landMeters([[55.05, 9.95], [55.05, 10.15]]);
        $this->assertGreaterThan(5800.0, $over);
        $this->assertLessThan(7000.0, $over);
        // a start 30 m inside the land (launch beach) does not count
        $this->assertSame(0.0, $router->landMeters([[55.00027, 10.05], [54.99, 10.06]]));
    }

    public function testCheckRefusesStartOrEndOnLand(): void
    {
        $router = new Autorouter(self::island());

        $this->assertSame(['start_on_land'], $router->check([[55.05, 10.05], [54.99, 10.15]]));
        $this->assertSame(['end_on_land'], $router->check([[54.99, 9.95], [55.05, 10.05]]));
        $this->assertSame(['start_on_land', 'end_on_land'], $router->check([[55.05, 10.04], [55.05, 10.06]]));
    }

    public function testOpenSeaFarFromAnyShoreIsWater(): void
    {
        $router = new Autorouter(self::island());

        // 2 degrees (~220 km) from the only shore: no side to tell, but it is open water
        $this->assertSame([], $router->check([[57.0, 12.0], [57.01, 12.01]]));
        $this->assertSame(0.0, $router->landMeters([[57.0, 12.0], [57.1, 12.1]]));
    }

    public function testCheckRefusesWhereTheIndexHasNothing(): void
    {
        $this->assertSame(['start_not_covered', 'end_not_covered'], (new Autorouter(new FakeShoreSource([], false)))->check([[54.9, 9.9], [54.9, 10.0]]));
        $this->assertSame(['start_not_covered', 'end_not_covered'], (new Autorouter(new FakeShoreSource([], true, false)))->check([[54.9, 9.9], [54.9, 10.0]]));
    }

    public function testRouteOverLandGoesAround(): void
    {
        $router = new Autorouter(self::island());
        $result = $router->route([[55.05, 9.95], [55.05, 10.15]], null);

        $this->assertSame(1, $result['changed_sections']);
        $this->assertSame([], $result['warnings']);
        foreach (self::walk($result['points']) as [$lat, $lng]) {
            $this->assertGreaterThan(0.0, self::distanceToLand($lat, $lng), "($lat, $lng) is on land");
        }
        for ($i = 1; $i < count($result['points']) - 1; $i++) {
            [$a, $b, $c] = [$result['points'][$i - 1], $result['points'][$i], $result['points'][$i + 1]];
            $dot = ($b[0] - $a[0]) * ($c[0] - $b[0]) + ($b[1] - $a[1]) * ($c[1] - $b[1]) * cos(deg2rad($b[0])) ** 2;
            $this->assertGreaterThan(0.0, $dot, "the way turns back on itself at point $i");
        }
        $this->assertSame([55.05, 9.95], $result['points'][0]);
        $this->assertSame([55.05, 10.15], $result['points'][count($result['points']) - 1]);
        $this->assertGreaterThan($result['length_before'], $result['length_after']);
        // around the north or south end: ~ 2 x 5.5 km extra at the most, with the straightening not much more
        $this->assertLessThan($result['length_before'] + 16000, $result['length_after']);
    }

    public function testRouteOnWaterStaysAsDrawn(): void
    {
        $route = [[54.9, 9.9], [54.9, 10.0], [54.95, 10.2]];
        $result = (new Autorouter(self::island()))->route($route, null);

        $this->assertSame($route, $result['points']);
        $this->assertSame(0, $result['changed_sections']);
    }

    public function testShoreDistanceMovesARouteAwayFromTheCoast(): void
    {
        $route = [[54.9997, 9.9], [54.9997, 10.2]]; // ~33 m south of the south shore
        $router = new Autorouter(self::island());

        $kept = $router->route($route, null);
        $this->assertSame(0, $kept['changed_sections'], 'without the distance rule water is water');

        $result = $router->route($route, 100.0);
        $this->assertGreaterThanOrEqual(1, $result['changed_sections']);
        foreach (self::walk($result['points']) as [$lat, $lng]) {
            $fromEnd = min(
                GeometryService::distanceMeters($lat, $lng, $route[0][0], $route[0][1]),
                GeometryService::distanceMeters($lat, $lng, $route[1][0], $route[1][1])
            );
            if ($fromEnd > 300.0) {
                $this->assertGreaterThan(85.0, self::distanceToLand($lat, $lng), "($lat, $lng) is too near the shore");
            }
        }
    }

    public function testMaximumDistanceBringsAFarRouteBackToTheCoast(): void
    {
        $route = [[54.97, 9.9], [54.97, 10.2]]; // ~3.3 km south of the south shore all the way
        $router = new Autorouter(self::island());

        $this->assertSame(0, $router->route($route, 100.0)['changed_sections'], 'no maximum, nothing to do');

        $result = $router->route($route, 100.0, 500.0);
        $this->assertGreaterThanOrEqual(1, $result['changed_sections']);
        $near = 0;
        foreach (self::walk($result['points']) as [$lat, $lng]) {
            $distance = self::distanceToLand($lat, $lng);
            $this->assertGreaterThan(85.0, $distance, "($lat, $lng) is too near the shore");
            if ($lng > 10.03 && $lng < 10.07) {
                $this->assertLessThan(600.0, $distance, "($lat, $lng) is too far from the shore");
                $near++;
            }
        }
        $this->assertGreaterThan(100, $near);
        // not a detour for its own sake: well below going out and back twice
        $this->assertLessThan($result['length_before'] * 1.4, $result['length_after']);
    }

    public function testMaximumDistanceIsSoftOverOpenWater(): void
    {
        // a crossing far from any shore is kept: following the coast would be several times longer
        $route = [[54.9, 9.5], [54.9, 10.6]];
        $result = (new Autorouter(self::island()))->route($route, 100.0, 300.0);

        $this->assertLessThan($result['length_before'] * 1.3, $result['length_after']);
    }

    public function testTwoNeighbouringDetoursDoNotRunBackwardsWhereTheyMeet(): void
    {
        // two blocks ~450 m apart: two bad runs whose detours share the water between them
        $segments = array_merge(
            FakeShoreSource::ring(55.0, 10.0, 55.05, 10.04),
            FakeShoreSource::ring(55.0, 10.047, 55.05, 10.1)
        );
        $result = (new Autorouter(new FakeShoreSource($segments)))->route([[55.025, 9.95], [55.025, 10.15]], null);

        $this->assertGreaterThanOrEqual(2, $result['changed_sections']);
        $points = $result['points'];
        for ($i = 1; $i < count($points) - 1; $i++) {
            $dot = ($points[$i][0] - $points[$i - 1][0]) * ($points[$i + 1][0] - $points[$i][0])
                + ($points[$i][1] - $points[$i - 1][1]) * ($points[$i + 1][1] - $points[$i][1]) * cos(deg2rad(55.0)) ** 2;
            $this->assertGreaterThan(0.0, $dot, "the way turns back on itself at point $i");
        }
    }

    public function testRouteThroughANarrowStraitNeverCrossesLand(): void
    {
        // two blocks with a ~280 m wide strait between them (55.0-55.05 and 55.0525-55.1), 400 m wanted
        $segments = array_merge(
            FakeShoreSource::ring(55.0, 10.0, 55.05, 10.1),
            FakeShoreSource::ring(55.0525, 10.0, 55.1, 10.1)
        );
        $route = [[55.05125, 9.95], [55.05125, 10.15]];
        $result = (new Autorouter(new FakeShoreSource($segments)))->route($route, 400.0);

        $this->assertGreaterThanOrEqual(1, $result['changed_sections']);
        foreach (self::walk($result['points']) as [$lat, $lng]) {
            $inside = ($lat > 55.0 && $lat < 55.05 || $lat > 55.0525 && $lat < 55.1) && $lng > 10.0 && $lng < 10.1;
            $this->assertFalse($inside, "($lat, $lng) is on land");
        }
    }

    public function testGivesUpOnAnEnclosedStart(): void
    {
        // a closed lake-like ring (water inside = clockwise): the route inside cannot get out and a stretch crosses the ring
        $ring = [
            [0, 55.0, 10.0, 55.1, 10.0],
            [0, 55.1, 10.0, 55.1, 10.1],
            [0, 55.1, 10.1, 55.0, 10.1],
            [0, 55.0, 10.1, 55.0, 10.0],
        ];
        $result = (new Autorouter(new FakeShoreSource($ring)))->route([[55.05, 10.05], [55.05, 10.2]], null);

        $this->assertSame(0, $result['changed_sections']);
        $this->assertSame('no_path', $result['warnings'][0]['code']);
    }

    public function testLimitsThePointCount(): void
    {
        $points = [];
        for ($i = 0; $i < 700; $i++) {
            $points[] = [54.9 + ($i % 2) * 0.0001, 9.0 + $i * 0.0001];
        }
        // one land crossing at the end forces the output through limitPoints()
        $points[] = [55.05, 9.95];
        $points[] = [55.05, 10.15];
        $result = (new Autorouter(self::island()))->route($points, null);

        $this->assertLessThanOrEqual(Autorouter::MAX_POINTS, count($result['points']));
    }
}
