<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Tide\HarmonicTide;
use Ytan\Service\Tide\TiconTideSource;

/**
 * The own harmonic tide prediction (HarmonicTide) and the TICON-4 source
 * that feeds it - against the real resources/tide/ticon4.json and reference
 * times of the services (fetched 2026-11-16).
 */
final class HarmonicTideTest extends TestCase
{
    private const TICON = __DIR__ . '/../../resources/tide/ticon4.json';

    public function testSpeedsFollowFromTheDoodsonNumbers(): void
    {
        $this->assertEqualsWithDelta(28.9841042, HarmonicTide::speed('M2'), 1e-6);
        $this->assertEqualsWithDelta(30.0, HarmonicTide::speed('S2'), 1e-6);
        $this->assertEqualsWithDelta(15.0410686, HarmonicTide::speed('K1'), 1e-6);
        $this->assertEqualsWithDelta(13.9430356, HarmonicTide::speed('O1'), 1e-6);
        $this->assertEqualsWithDelta(57.9682085, HarmonicTide::speed('M4'), 1e-6);
        $this->assertNull(HarmonicTide::speed('XY9'));
    }

    public function testAPureS2TideIsHighAtItsPhaseLagInSolarHours(): void
    {
        // S2 has no nodal correction and V = 30 deg per hour of UT: g = 60 -> high water at 02:00 and 14:00 UTC
        $from = strtotime('2026-11-16 00:00:00 UTC');
        $events = HarmonicTide::extremes(['S2' => [1.0, 60.0]], $from, $from + 86400);

        $this->assertSame(['HW', 'NW', 'HW', 'NW'], array_column($events, 'type'));
        $this->assertEqualsWithDelta($from + 2 * 3600, $events[0]['time'], 60);
        $this->assertEqualsWithDelta($from + 8 * 3600, $events[1]['time'], 60);
        $this->assertEqualsWithDelta(1.0, $events[0]['height_m'], 0.01);
        $this->assertEqualsWithDelta(-1.0, $events[1]['height_m'], 0.01);
    }

    public function testAStandOfTheTideGivesOneHighWater(): void
    {
        // a strong M4 in the right phase flattens high water into two bumps with a small dip
        $from = strtotime('2026-11-16 00:00:00 UTC');
        $events = HarmonicTide::extremes(['M2' => [1.0, 0.0], 'M4' => [0.3, 180.0]], $from, $from + 2 * 86400);
        $types = array_column($events, 'type');

        for ($i = 1, $n = count($types); $i < $n; $i++) {
            $this->assertNotSame($types[$i - 1], $types[$i], 'high and low water alternate');
        }
        $this->assertCount(4, array_filter($types, static fn ($t) => $t === 'HW'), 'one high water per M2 cycle');
    }

    public function testBrestMatchesTidesAtlasWhichUsesTheSameConstants(): void
    {
        // TidesAtlas' own TICON prediction for Brest; the alternating +/-17 min of a wrong diurnal phase offset would fail
        $reference = [
            [1794793601, 'NW'], [1794814878, 'HW'], [1794839290, 'NW'], [1794860473, 'HW'],
            [1794883407, 'NW'], [1794905023, 'HW'], [1794929721, 'NW'], [1794951677, 'HW'],
        ];
        $this->assertCloseTo($reference, 48.383, -4.495, 12);
    }

    public function testHelgolandIsOnUtcAfterTheWsvCorrection(): void
    {
        // BSH predictions; the uncorrected WSV record (CET) came out ~66 min late
        $reference = [
            [1794840420, 'HW'], [1794863280, 'NW'], [1794884520, 'HW'], [1794909000, 'NW'],
            [1794929940, 'HW'], [1794952980, 'NW'], [1794974400, 'HW'], [1794999120, 'NW'],
        ];
        $this->assertCloseTo($reference, 54.178, 7.890, 25);
    }

    public function testTheSourceAnswersOnlyNearAGauge(): void
    {
        $source = new TiconTideSource(self::TICON);
        $from = 1794787200;

        $this->assertNull($source->tide(50.0, -30.0, null, $from, $from + 86400, 15000), 'open Atlantic');
        $nantes = $source->tide(47.205, -1.560, null, $from, $from + 86400, 15000);
        $this->assertNotNull($nantes);
        $this->assertSame('ticon', $nantes['source']);
        $this->assertSame('Nantes Salorges', $nantes['station']);
        $this->assertLessThan(1500, $nantes['distance_m']);
        $this->assertGreaterThan(2.0, $nantes['mean_range_m']);
        $this->assertNull($source->tide(47.205, -1.560, null, $from, $from + 86400, 500), 'gauge farther than the limit');
        $this->assertNull((new TiconTideSource(__DIR__ . '/missing.json'))->tide(47.205, -1.560, null, $from, $from + 86400, 15000));
    }

    /** @param list<array{0: int, 1: string}> $reference */
    private function assertCloseTo(array $reference, float $lat, float $lng, int $minutes): void
    {
        $tide = (new TiconTideSource(self::TICON))->tide($lat, $lng, null, $reference[0][0] - 3600, end($reference)[0] + 3600, 15000);
        $this->assertNotNull($tide);
        foreach ($reference as [$time, $type]) {
            $best = null;
            foreach ($tide['events'] as $event) {
                if ($event['type'] === $type && ($best === null || abs($event['time'] - $time) < abs($best - $time))) {
                    $best = $event['time'];
                }
            }
            $this->assertNotNull($best);
            $this->assertLessThanOrEqual($minutes * 60, abs($best - $time), sprintf('%s %s UTC off by %d min', $type, gmdate('m-d H:i', $time), ($best - $time) / 60));
        }
    }
}
