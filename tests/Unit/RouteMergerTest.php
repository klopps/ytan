<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\RouteMerger;

final class RouteMergerTest extends TestCase
{
    /** A line along a meridian at lng 10, latitudes as given. */
    private static function line(float ...$lats): array
    {
        return array_map(static fn (float $lat) => ['lat' => $lat, 'lng' => 10.0], $lats);
    }

    private static function lats(array $result): array
    {
        return array_column($result['points'], 'lat');
    }

    public function testKeptEndMeetsOtherStartSharedPoint(): void
    {
        $r = RouteMerger::merge(self::line(54.0, 54.1), self::line(54.1, 54.2), 10);

        $this->assertSame([54.0, 54.1, 54.2], self::lats($r));
        $this->assertSame('shared', $r['join']);
        $this->assertSame(0.0, $r['gap_m']);
        $this->assertFalse($r['other_first']);
        $this->assertFalse($r['other_reversed']);
    }

    public function testKeptEndMeetsOtherEndReversesOther(): void
    {
        $r = RouteMerger::merge(self::line(54.0, 54.1), self::line(54.2, 54.1), 10);

        $this->assertSame([54.0, 54.1, 54.2], self::lats($r));
        $this->assertFalse($r['other_first']);
        $this->assertTrue($r['other_reversed']);
    }

    public function testKeptStartMeetsOtherEndOtherComesFirst(): void
    {
        $r = RouteMerger::merge(self::line(54.1, 54.2), self::line(54.0, 54.1), 10);

        $this->assertSame([54.0, 54.1, 54.2], self::lats($r));
        $this->assertTrue($r['other_first']);
        $this->assertFalse($r['other_reversed']);
    }

    public function testKeptStartMeetsOtherStartReversesOtherKeepsKeptDirection(): void
    {
        $r = RouteMerger::merge(self::line(54.1, 54.2), self::line(54.1, 54.0), 10);

        // The kept route still runs 54.1 -> 54.2 at the end of the line.
        $this->assertSame([54.0, 54.1, 54.2], self::lats($r));
        $this->assertTrue($r['other_first']);
        $this->assertTrue($r['other_reversed']);
    }

    public function testCloseEndsBecomeTheirMidpoint(): void
    {
        // ~5.6 m apart (0.00005 degrees latitude).
        $r = RouteMerger::merge(self::line(54.0, 54.1), self::line(54.10005, 54.2), 10);

        $this->assertSame('midpoint', $r['join']);
        $this->assertCount(3, $r['points']);
        $this->assertEqualsWithDelta(54.100025, $r['points'][1]['lat'], 1e-9);
        $this->assertEqualsWithDelta(5.6, $r['gap_m'], 0.2);
    }

    public function testFartherEndsGetAConnectingSegment(): void
    {
        // ~111 m apart.
        $r = RouteMerger::merge(self::line(54.0, 54.1), self::line(54.101, 54.2), 10);

        $this->assertSame('segment', $r['join']);
        $this->assertSame([54.0, 54.1, 54.101, 54.2], self::lats($r));
        $this->assertEqualsWithDelta(111, $r['gap_m'], 1);
    }

    public function testThresholdComesFromTheParameter(): void
    {
        $r = RouteMerger::merge(self::line(54.0, 54.1), self::line(54.101, 54.2), 200);
        $this->assertSame('midpoint', $r['join']);

        $r = RouteMerger::merge(self::line(54.0, 54.1), self::line(54.10005, 54.2), 0);
        $this->assertSame('segment', $r['join'], '0 = only identical points are combined');
    }

    public function testIdenticalPairWinsOverCloserLookingCandidates(): void
    {
        // Kept start (54.0) and other end (54.0) are identical; kept end
        // (54.1) is 1 mm-ish away from nothing - the identical pair wins.
        $r = RouteMerger::merge(self::line(54.0, 54.1), self::line(53.9, 54.0), 10);

        $this->assertSame('shared', $r['join']);
        $this->assertSame([53.9, 54.0, 54.1], self::lats($r));
        $this->assertTrue($r['other_first']);
    }

    public function testComparesIdentityAtStoredPrecision(): void
    {
        $r = RouteMerger::merge(self::line(54.0, 54.1000001), self::line(54.1, 54.2), 10);

        $this->assertSame('shared', $r['join']);
        $this->assertCount(3, $r['points']);
    }

    public function testInnerPointsAreNeverJoinPoints(): void
    {
        // The other route passes right through the kept route's middle, but
        // only its ends count: kept end 54.2 / other start 54.25 are closest.
        $kept = self::line(54.0, 54.1, 54.2);
        $other = [['lat' => 54.25, 'lng' => 10.0], ['lat' => 54.1, 'lng' => 10.0], ['lat' => 54.1, 'lng' => 11.0]];

        $r = RouteMerger::merge($kept, $other, 10);

        $this->assertSame([54.0, 54.1, 54.2, 54.25, 54.1, 54.1], self::lats($r));
        $this->assertSame('segment', $r['join']);
    }

    public function testSinglePointRoutes(): void
    {
        $r = RouteMerger::merge(self::line(54.0), self::line(54.1, 54.2), 10);
        $this->assertSame([54.0, 54.1, 54.2], self::lats($r));

        $r = RouteMerger::merge(self::line(54.0), self::line(54.0), 10);
        $this->assertSame([54.0], self::lats($r));
    }

    public function testEmptyRouteIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RouteMerger::merge([], self::line(54.0), 10);
    }
}
