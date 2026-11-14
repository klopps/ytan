<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Tide\BshTideSource;
use Ytan\Tests\Fixtures\FakeJsonHttpClient;

/**
 * BSH tide predictions: the files' shape as gezeiten.bsh.de serves them
 * (checked 2026-11-14, shortened), gauge choice, cache and failures.
 */
final class BshTideSourceTest extends TestCase
{
    private string $cacheDir;
    private FakeJsonHttpClient $http;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ytan-tide-cache-' . bin2hex(random_bytes(4));
        $this->http = new FakeJsonHttpClient();
        $this->http->setResponseFor(BshTideSource::BASE_URL . '/tides_overview.json', [
            'copyright_note' => '©BSH',
            'gauges' => [
                ['station_name' => 'Hamburg, St. Pauli, Elbe', 'bshnr' => '508P', 'latitude' => 53.54556, 'longitude' => 9.97, 'gauge_group' => 1],
                ['station_name' => 'Cuxhaven, Steubenhöft, Elbe', 'bshnr' => '506P', 'latitude' => 53.8675, 'longitude' => 8.7178, 'gauge_group' => 1],
                ['station_name' => 'Kiel, Holtenau, Ostsee', 'bshnr' => '3020P', 'latitude' => 54.37222, 'longitude' => 10.15694, 'gauge_group' => 3],
                ['station_name' => 'kaputt', 'bshnr' => '../x', 'latitude' => 53.5, 'longitude' => 9.9],
            ],
        ]);
        $this->http->setResponseFor(BshTideSource::BASE_URL . '/DE__508P_tides.json', self::gaugeFile());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    /** Hamburg St. Pauli 2026, four events of 2 January. */
    private static function gaugeFile(): array
    {
        return [
            'station_name' => 'Hamburg, St. Pauli, Elbe',
            'bshnr' => '508P',
            'years' => [
                ['2026' => [
                    'MTH' => 383,
                    'MFD' => '7:06',
                    'hwnw_prediction' => ['level' => 'PNP', 'data' => [
                        ['timestamp' => '2026-01-02 02:35:00+01:00', 'height' => 720, 'type' => 'HW'],
                        ['timestamp' => '2026-01-02 09:57:00+01:00', 'height' => 350, 'type' => 'NW'],
                        ['timestamp' => '2026-01-02 15:11:00+01:00', 'height' => 729, 'type' => 'HW'],
                        ['timestamp' => '2026-01-02 22:27:00+01:00', 'height' => 354, 'type' => 'NW'],
                        ['timestamp' => 'kaputt', 'height' => 1, 'type' => 'HW'],
                    ]],
                ]],
                ['2027' => ['MTH' => 380, 'hwnw_prediction' => ['data' => []]]],
            ],
        ];
    }

    private function source(): BshTideSource
    {
        return new BshTideSource($this->cacheDir, $this->http);
    }

    public function testTheNearestGaugeWithItsEventsAroundTheWindow(): void
    {
        $from = strtotime('2026-01-02 10:00:00+01:00');
        $to = strtotime('2026-01-02 14:00:00+01:00');

        // Landungsbrücken, ~300 m from the gauge
        $tide = $this->source()->tide(53.5460, 9.9660, null, $from, $to, 15000);

        $this->assertSame('Hamburg, St. Pauli, Elbe', $tide['station']);
        $this->assertSame('bsh', $tide['source']);
        $this->assertSame('©BSH', $tide['attribution']);
        $this->assertEqualsWithDelta(260, $tide['distance_m'], 40);
        $this->assertSame(3.83, $tide['mean_range_m']);
        // the low water before the window and the first high water after it
        $this->assertSame(['NW', 'HW'], array_column($tide['events'], 'type'));
        $this->assertSame(strtotime('2026-01-02 09:57:00+01:00'), $tide['events'][0]['time']);
        $this->assertSame(7.29, $tide['events'][1]['height_m']);
    }

    public function testNoGaugeWithinReach(): void
    {
        $this->assertNull($this->source()->tide(52.13, 11.64, null, 0, 1, 15000), 'Magdeburg');
    }

    public function testFilesAreCachedAndAFailureIsRemembered(): void
    {
        $source = $this->source();
        $source->tide(53.5460, 9.9660, null, 0, 1, 15000);
        $calls = $this->http->callCount();
        $this->source()->tide(53.5460, 9.9660, null, 0, 1, 15000);
        $this->assertSame($calls, $this->http->callCount(), 'gauge list and gauge file come from the cache');

        // Cuxhaven's file fails: tried once, then remembered for an hour
        $log = ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'log'));
        try {
            $this->assertNull($this->source()->tide(53.87, 8.72, null, 0, 1, 15000));
            $after = $this->http->callCount();
            $this->assertNull($this->source()->tide(53.87, 8.72, null, 0, 1, 15000));
            $this->assertSame($after, $this->http->callCount());
        } finally {
            @unlink((string) ini_get('error_log'));
            ini_set('error_log', (string) $log);
        }
    }

    public function testCachedEventsThatEndBeforeTheWindowAreLoadedAgain(): void
    {
        $this->source()->tide(53.5460, 9.9660, null, 0, 1, 15000);
        $calls = $this->http->callCount();

        $this->source()->tide(53.5460, 9.9660, null, 0, strtotime('2027-06-01'), 15000);

        $this->assertSame($calls + 1, $this->http->callCount());
    }

    public function testWithoutMthTheRangeComesFromTheEvents(): void
    {
        // Kiel-Holtenau (Baltic): predictions, but no mean tidal range in the file
        $parsed = BshTideSource::parseGauge(['years' => [['2026' => ['hwnw_prediction' => ['data' => [
            ['timestamp' => '2026-11-15 03:22:00+01:00', 'height' => 495, 'type' => 'NW'],
            ['timestamp' => '2026-11-15 10:11:00+01:00', 'height' => 507, 'type' => 'HW'],
            ['timestamp' => '2026-11-15 14:41:00+01:00', 'height' => 499, 'type' => 'NW'],
        ]]]]]]);

        $this->assertSame(0.1, $parsed['mean_range_m']);
        $this->assertCount(3, $parsed['events']);
    }

    public function testParseGaugesSkipsUnusableIds(): void
    {
        $gauges = BshTideSource::parseGauges(['gauges' => [
            ['station_name' => 'A', 'bshnr' => '508P', 'latitude' => 1, 'longitude' => 2],
            ['station_name' => 'B', 'bshnr' => '../../etc', 'latitude' => 1, 'longitude' => 2],
            ['station_name' => 'C', 'latitude' => 1, 'longitude' => 2],
        ]]);

        $this->assertSame(['508P'], array_column($gauges, 'id'));
    }
}
