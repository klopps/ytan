<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Water\WaterDistanceService;

/**
 * Reads tests/testdata/water-index-sample.bin - written by the real Python
 * writer (tools/water/make_test_fixture.py), so this also pins that PHP and
 * Python agree on the index format. Around 56.0 N / 9.0 E: a coast line 100 m
 * north, a river 300 m east (drawn south to north), a square lake shore around
 * 56.3 N / 9.0 E, a canal at 56.15 N.
 */
final class WaterDistanceServiceTest extends TestCase
{
    private function service(): WaterDistanceService
    {
        return new WaterDistanceService(dirname(__DIR__) . '/testdata/water-index-sample.bin');
    }

    public function testDistancesToTheThreeKinds(): void
    {
        $d = $this->service()->distances(56.0, 9.0);

        $this->assertEqualsWithDelta(100, $d['sea'], 1);
        $this->assertEqualsWithDelta(300, $d['river'], 2);
        // To the lake's southern shore at 56.29 N, many cells away (ring search).
        $this->assertEqualsWithDelta((56.29 - 56.0) * 111195, $d['lake'], 150);
    }

    public function testInsideALakeTheShoreCounts(): void
    {
        // Lake centre: 0.01 degrees to the east/west shore = ~622 m at 56.3 N, 1112 m north/south.
        $this->assertEqualsWithDelta(0.01 * 111195 * cos(deg2rad(56.3)), $this->service()->distances(56.3, 9.0)['lake'], 5);
    }

    public function testTheNearestPointOfALongSegmentNotItsEnds(): void
    {
        // 100 m north of the coast line's middle part, far from its end points (8.99 and 9.01).
        $this->assertEqualsWithDelta(100, $this->service()->distances(56.000898 + 0.000899, 9.005)['sea'], 1);
    }

    public function testFartherThanTheLimitIsNull(): void
    {
        $this->assertSame(['sea' => null, 'river' => null, 'lake' => null], $this->service()->distances(60.0, 20.0));
        // ~150 km north of everything: outside MAX_METERS (100 km) for every kind.
        $this->assertSame(['sea' => null, 'river' => null, 'lake' => null], $this->service()->distances(57.7, 9.0));
    }

    public function testWithoutAnIndexEverythingIsNull(): void
    {
        $service = new WaterDistanceService(sys_get_temp_dir() . '/no-such-water-index.bin');
        $log = ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'log'));
        try {
            $this->assertFalse($service->available());
            $this->assertSame(['sea' => null, 'river' => null, 'lake' => null], $service->distances(56.0, 9.0));
        } finally {
            @unlink((string) ini_get('error_log'));
            ini_set('error_log', (string) $log);
        }
    }

    public function testOutsideTheCoveredCountriesThereAreNoDistances(): void
    {
        $meta = tempnam(sys_get_temp_dir(), 'water-meta');
        // A square 55.5..56.5 N / 8.5..9.5 E as the only covered area.
        file_put_contents($meta, json_encode(['version' => '1', 'coverage' => [[[55.5, 8.5], [55.5, 9.5], [56.5, 9.5], [56.5, 8.5]]]]));
        try {
            $service = new WaterDistanceService(dirname(__DIR__) . '/testdata/water-index-sample.bin', $meta);
            $this->assertTrue($service->covers(56.0, 9.0));
            $this->assertEqualsWithDelta(100, $service->distances(56.0, 9.0)['sea'], 1);
            // 10 km west of the square: the coast is near, but outside the index's countries.
            $this->assertFalse($service->covers(56.0, 8.35));
            $this->assertSame(['sea' => null, 'river' => null, 'lake' => null], $service->distances(56.0, 8.35));
            $this->assertSame('1', $service->version());
        } finally {
            unlink($meta);
        }
    }

    public function testWithoutCoverageEverythingCounts(): void
    {
        $this->assertTrue($this->service()->covers(10.0, 10.0));
    }

    public function testVersionComesFromTheMetaFile(): void
    {
        $meta = tempnam(sys_get_temp_dir(), 'water-meta');
        file_put_contents($meta, json_encode(['version' => '202610211200']));
        try {
            $service = new WaterDistanceService(dirname(__DIR__) . '/testdata/water-index-sample.bin', $meta);
            $this->assertSame('202610211200', $service->version());
        } finally {
            unlink($meta);
        }
    }

    public function testOpenWaterAlongALineEndsAtTheFirstShore(): void
    {
        $service = $this->service();
        // The coast line runs east-west 100 m north of 56.0 N / 9.0 E.
        $this->assertEqualsWithDelta(100, $service->openWaterAlong(56.0, 9.0, 0.0), 1);
        // Towards the south there is nothing within reach; the river (centre line only) doesn't count.
        $this->assertSame(30000, $service->openWaterAlong(56.0, 9.0, 180.0));
        $this->assertSame(30000, $service->openWaterAlong(56.0, 9.0, 90.0));
        // A shorter reach caps the result.
        $this->assertSame(50, $service->openWaterAlong(56.0, 9.0, 0.0, 50));
    }

    public function testOpenWaterInsideALakeEndsAtItsShore(): void
    {
        $service = $this->service();
        $this->assertEqualsWithDelta(0.01 * 111320 * cos(deg2rad(56.3)), $service->openWaterAlong(56.3, 9.0, 90.0), 10);
        $this->assertEqualsWithDelta(0.01 * 111320, $service->openWaterAlong(56.3, 9.0, 0.0), 10);
    }

    public function testOnARiverTheFlowFollowsTheDirectionTheLineWasDrawnIn(): void
    {
        $flow = $this->service()->flowAt(56.005, 9.0045);

        $this->assertSame('river', $flow['kind']);
        $this->assertEqualsWithDelta(0.0, $flow['bearing'], 0.5, 'drawn south to north = flows north');
        $this->assertEqualsWithDelta(20, $flow['distance'], 3);
    }

    public function testACanalIsItsOwnKindAndStillCountsAsRiverDistance(): void
    {
        $service = $this->service();
        $this->assertSame('canal', $service->flowAt(56.1505, 9.01)['kind']);
        $this->assertEqualsWithDelta(56, $service->distances(56.1505, 9.01)['river'], 2);
    }

    public function testNoFlowAwayFromRiversOrWhereTheSeaIsNearer(): void
    {
        $service = $this->service();
        $this->assertNull($service->flowAt(56.0, 9.02), 'river ~950 m away');
        $this->assertNull($service->flowAt(56.0009, 8.995), 'on the coast, river 600 m away');
        $this->assertNull($service->flowAt(56.0012, 9.0040), 'river 51 m, coast 34 m: at the mouth = sea');
    }

    public function testAnIndexWithoutTheCanalKindGivesNoFlowButTheSameDistances(): void
    {
        $service = new WaterDistanceService(dirname(__DIR__) . '/testdata/water-index-sample-v3kinds.bin');

        $this->assertTrue($service->available());
        $this->assertNull($service->flowAt(56.005, 9.0045), 'canals would be taken for rivers there');
        $this->assertEqualsWithDelta(300, $service->distances(56.0, 9.0)['river'], 2);
    }

    public function testOpenWaterWithoutAnIndexIsNull(): void
    {
        $service = new WaterDistanceService(sys_get_temp_dir() . '/no-such-water-index.bin');
        $log = ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'log'));
        try {
            $this->assertNull($service->openWaterAlong(56.0, 9.0, 0.0));
        } finally {
            @unlink((string) ini_get('error_log'));
            ini_set('error_log', (string) $log);
        }
    }
}
