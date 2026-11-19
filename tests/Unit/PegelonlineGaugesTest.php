<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\River\PegelonlineGauges;
use Ytan\Service\Tide\TideCache;
use Ytan\Tests\Fixtures\FakeTideHttp;

final class PegelonlineGaugesTest extends TestCase
{
    private string $dir;
    private FakeTideHttp $http;
    private int $now;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-pegelonline-' . uniqid();
        $this->http = new FakeTideHttp();
        $this->now = strtotime('2026-11-19T05:00:00+01:00');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function gauges(): PegelonlineGauges
    {
        return new PegelonlineGauges($this->http, new TideCache($this->dir), fn () => $this->now);
    }

    /** A trimmed copy of a real answer (Elbe near Hitzacker, 2026-11-19). */
    private function answer(string $hitzackerTime = '2026-11-19T04:39:00+01:00', string $mhw = 'low', string $hsw = 'unknown'): string
    {
        return json_encode([
            ['uuid' => 'a', 'shortname' => 'HITZACKER', 'longname' => 'HITZACKER', 'latitude' => 53.154626, 'longitude' => 11.045488,
                'water' => ['shortname' => 'ELBE', 'longname' => 'ELBE'],
                'timeseries' => [['shortname' => 'W', 'unit' => 'cm', 'currentMeasurement' => ['timestamp' => $hitzackerTime, 'value' => 87.0, 'stateMnwMhw' => $mhw, 'stateNswHsw' => $hsw]]]],
            ['uuid' => 'b', 'shortname' => 'NEU DARCHAU', 'longname' => 'NEU DARCHAU', 'latitude' => 53.232258, 'longitude' => 10.888819,
                'water' => ['shortname' => 'ELBE', 'longname' => 'ELBE'],
                'timeseries' => [['shortname' => 'W', 'unit' => 'cm', 'currentMeasurement' => ['timestamp' => '2026-11-18T06:30:00+01:00', 'value' => 76.0, 'stateMnwMhw' => 'low', 'stateNswHsw' => 'unknown']]]],
            ['uuid' => 'c', 'shortname' => 'DAMNATZ', 'longname' => 'DAMNATZ', 'latitude' => 53.137769, 'longitude' => 11.179445,
                'water' => ['shortname' => 'ELBE', 'longname' => 'ELBE'],
                'timeseries' => [['shortname' => 'Q', 'unit' => 'm3/s', 'currentMeasurement' => ['timestamp' => '2026-11-19T04:39:00+01:00', 'value' => 300.0]]]],
        ]);
    }

    public function testTheNearestGaugeWithAFreshWaterLevel(): void
    {
        $this->http->on(PegelonlineGauges::BASE_URL, $this->answer());

        // right next to Neu Darchau, but its last measurement is a day old; Damnatz has no W series
        $gauge = $this->gauges()->nearest(53.23, 10.89, 20000);

        $this->assertSame('Hitzacker', $gauge['station']);
        $this->assertSame('Elbe', $gauge['water']);
        $this->assertSame(87.0, $gauge['value_cm']);
        $this->assertSame('low', $gauge['mhw']);
        $this->assertNull($gauge['hsw'], "'unknown' is no classification");
        $this->assertEqualsWithDelta(13300, $gauge['distance_m'], 500);
        $url = $this->http->requests[0]['url'];
        $this->assertStringContainsString('latitude=53.2500&longitude=10.9000&radius=25', $url);
        $this->assertStringContainsString('includeCurrentMeasurement=true&timeseries=W', $url);
    }

    public function testNothingBeyondTheDistanceOrOutsideGermany(): void
    {
        $this->http->on(PegelonlineGauges::BASE_URL, $this->answer());

        $this->assertNull($this->gauges()->nearest(53.23, 10.89, 5000));
        $this->assertNull($this->gauges()->nearest(55.6, 12.5, 20000), 'Copenhagen');
        $this->assertCount(1, $this->http->requests, 'no request outside the coverage');
    }

    public function testHighWaterStatesArePassedOn(): void
    {
        $this->http->on(PegelonlineGauges::BASE_URL, $this->answer(mhw: 'high', hsw: 'high'));

        $gauge = $this->gauges()->nearest(53.15, 11.04, 20000);

        $this->assertSame('high', $gauge['mhw']);
        $this->assertSame('high', $gauge['hsw']);
    }

    public function testTheAnswerIsCachedForAQuarterOfAnHourAndAFailureForTenMinutes(): void
    {
        $this->http->on(PegelonlineGauges::BASE_URL, $this->answer());
        $this->gauges()->nearest(53.15, 11.04, 20000);
        $this->gauges()->nearest(53.16, 11.05, 20000); // same cell
        $this->assertCount(1, $this->http->requests);

        $this->now += PegelonlineGauges::CACHE_SECONDS;
        $this->http->on(PegelonlineGauges::BASE_URL, null);
        $this->assertNull(@$this->gauges()->nearest(53.15, 11.04, 20000));
        $this->assertCount(2, $this->http->requests);

        $this->http->on(PegelonlineGauges::BASE_URL, $this->answer('2026-11-19T05:10:00+01:00'));
        $this->assertNull($this->gauges()->nearest(53.15, 11.04, 20000), 'failure remembered');
        $this->assertCount(2, $this->http->requests);

        $this->now += PegelonlineGauges::FAIL_SECONDS;
        $this->assertSame('Hitzacker', $this->gauges()->nearest(53.15, 11.04, 20000)['station']);
        $this->assertCount(3, $this->http->requests);
    }
}
