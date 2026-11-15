<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Tide\DmiTideSource;
use Ytan\Service\Tide\GaugeTideSource;
use Ytan\Service\Tide\IhTideSource;
use Ytan\Service\Tide\KartverketTideSource;
use Ytan\Service\Tide\MarineInstituteTideSource;
use Ytan\Service\Tide\RwsTideSource;
use Ytan\Service\Tide\TideCache;
use Ytan\Service\Tide\UkhoTideSource;
use Ytan\Tests\Fixtures\FakeTideHttp;

/**
 * DMI (DK), Rijkswaterstaat (NL), Marine Institute (IE) and Kartverket (NO):
 * answers in the shape their services gave on 2026-11-14 (shortened), gauge
 * choice, coverage box, cache and failures.
 */
final class NationalTideSourcesTest extends TestCase
{
    private string $cacheDir;
    private FakeTideHttp $http;
    private int $from;
    private int $to;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/ytan-tide-national-' . bin2hex(random_bytes(4));
        $this->http = new FakeTideHttp();
        $this->from = strtotime('2026-11-15 00:00:00 UTC');
        $this->to = $this->from + 2 * 86400;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    private function cache(): TideCache
    {
        return new TideCache($this->cacheDir);
    }

    private function quietly(callable $fn): mixed
    {
        $log = ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'log'));
        try {
            return $fn();
        } finally {
            @unlink((string) ini_get('error_log'));
            ini_set('error_log', (string) $log);
        }
    }

    private function dmiFeatures(string $type, array $rows): string
    {
        return json_encode(['type' => 'FeatureCollection', 'features' => array_map(static fn ($r) => [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [8.4397, 55.4602]],
            'properties' => ['predictionType' => $type, 'predictionTime' => $r[0], 'value' => $r[1], 'stationId' => '25149'],
        ], $rows)]);
    }

    private function dmiSetup(): void
    {
        $this->http->on(DmiTideSource::BASE_URL . '/tidewaterstation/items', json_encode(['features' => [
            ['geometry' => ['coordinates' => [8.4397, 55.4602]], 'properties' => ['name' => 'Esbjerg Havn', 'stationId' => '25149']],
            ['geometry' => ['coordinates' => [12.6, 55.69]], 'properties' => ['name' => 'Københavns Havn', 'stationId' => '30336']],
        ]]));
        $this->http->on(DmiTideSource::BASE_URL . '/tidewater/items?stationId=25149', null);
    }

    public function testDmiHighAndLowWaterFromTwoRequests(): void
    {
        $this->dmiSetup();
        $max = $this->dmiFeatures('maximum', [['2026-11-15T04:33:00Z', 81.2], ['2026-11-15T16:59:00Z', 52]]);
        $min = $this->dmiFeatures('minimum', [['2026-11-14T22:29:00Z', -70], ['2026-11-15T11:04:00Z', -78.8], ['2026-11-15T23:05:00Z', -71]]);
        $this->http->on(DmiTideSource::BASE_URL . '/tidewater/items?stationId=25149', null);
        $source = new DmiTideSource($this->cache(), new class ($max, $min) implements \Ytan\Service\Tide\TideHttp {
            public function __construct(private string $max, private string $min)
            {
            }

            public function get(string $url, array $headers = []): ?string
            {
                if (str_contains($url, 'tidewaterstation')) {
                    return json_encode(['features' => [['geometry' => ['coordinates' => [8.4397, 55.4602]], 'properties' => ['name' => 'Esbjerg Havn', 'stationId' => '25149']]]]);
                }

                return str_contains($url, 'predictionType=maximum') ? $this->max : $this->min;
            }

            public function postJson(string $url, array $body): ?string
            {
                return null;
            }
        });

        $tide = $source->tide(55.46, 8.44, null, $this->from, $this->to, 15000);

        $this->assertSame('Esbjerg Havn', $tide['station']);
        $this->assertSame('dmi', $tide['source']);
        $this->assertSame('©DMI', $tide['attribution']);
        $this->assertSame(['NW', 'HW', 'NW', 'HW', 'NW'], array_column($tide['events'], 'type'), 'both kinds merged in time order');
        $this->assertSame(0.81, $tide['events'][1]['height_m']);
        $this->assertEqualsWithDelta(1.41, $tide['mean_range_m'], 0.01);
    }

    public function testOutsideTheCoverageBoxNothingIsAsked(): void
    {
        $this->dmiSetup();

        $this->assertNull((new DmiTideSource($this->cache(), $this->http))->tide(53.55, 9.97, null, $this->from, $this->to, 15000), 'Hamburg');
        $this->assertSame([], $this->http->requests);
    }

    public function testAFailingGaugeIsRememberedAndTheListIsCached(): void
    {
        $this->dmiSetup();
        $source = new DmiTideSource($this->cache(), $this->http);

        $this->assertNull($this->quietly(fn () => $source->tide(55.46, 8.44, null, $this->from, $this->to, 15000)));
        $count = count($this->http->requests);
        $this->assertNull((new DmiTideSource($this->cache(), $this->http))->tide(55.46, 8.44, null, $this->from, $this->to, 15000));
        $this->assertCount($count, $this->http->requests, 'gauge list cached, failed gauge remembered');
    }

    public function testRwsGaugesFromTheCatalogueAndTypedExtremes(): void
    {
        $this->http->on(RwsTideSource::CATALOGUE_URL, json_encode([
            'Succesvol' => true,
            'AquoMetadataLijst' => [
                ['AquoMetadata_MessageID' => 187, 'Grootheid' => ['Code' => 'WATHTE'], 'Groepering' => ['Code' => ''], 'Compartiment' => ['Code' => 'OW']],
                ['AquoMetadata_MessageID' => 189, 'Grootheid' => ['Code' => 'WATHTE'], 'Groepering' => ['Code' => 'GETETBRKD2'], 'Compartiment' => ['Code' => 'OW']],
            ],
            'AquoMetadataLocatieLijst' => [
                ['AquoMetaData_MessageID' => 189, 'Locatie_MessageID' => 1],
                ['AquoMetaData_MessageID' => 187, 'Locatie_MessageID' => 2],
            ],
            'LocatieLijst' => [
                ['Locatie_MessageID' => 1, 'Code' => 'hoekvanholland', 'Lat' => 51.976899, 'Lon' => 4.119827, 'Naam' => 'Hoek van Holland'],
                ['Locatie_MessageID' => 2, 'Code' => 'nurmessung', 'Lat' => 51.977, 'Lon' => 4.12, 'Naam' => 'Nur Messung'],
            ],
        ]));
        $this->http->on(RwsTideSource::OBSERVATIONS_URL . '#hoekvanholland', json_encode(['Succesvol' => true, 'WaarnemingenLijst' => [['MetingenLijst' => [
            ['Meetwaarde' => ['Waarde_Numeriek' => -30], 'Tijdstip' => '2026-11-15T01:19:00.000+01:00'],
            ['Meetwaarde' => ['Waarde_Numeriek' => 104], 'Tijdstip' => '2026-11-15T05:52:00.000+01:00'],
            ['Meetwaarde' => ['Waarde_Numeriek' => -59], 'Tijdstip' => '2026-11-15T11:34:00.000+01:00'],
            ['Meetwaarde' => ['Waarde_Numeriek' => 999999999], 'Tijdstip' => '2026-11-15T15:00:00.000+01:00'],
            ['Meetwaarde' => ['Waarde_Numeriek' => 116], 'Tijdstip' => '2026-11-15T18:16:00.000+01:00'],
        ]]]]));

        $tide = (new RwsTideSource($this->cache(), $this->http))->tide(51.978, 4.125, null, $this->from, $this->to, 15000);

        $this->assertSame('Hoek van Holland', $tide['station'], 'only the gauge with computed extremes');
        $this->assertSame(['NW', 'HW', 'NW', 'HW'], array_column($tide['events'], 'type'));
        $this->assertSame(1.04, $tide['events'][1]['height_m']);
        $observation = $this->http->requests[1];
        $this->assertSame('POST', $observation['method']);
        $this->assertSame('GETETBRKD2', $observation['body']['AquoPlusWaarnemingMetadata']['AquoMetadata']['Groepering']['Code']);
        $this->assertSame('2026-11-14T00:00:00.000+00:00', $observation['body']['Periode']['Begindatumtijd'], 'from the day before');
    }

    public function testMarineInstituteErddapRows(): void
    {
        $this->http->on(MarineInstituteTideSource::BASE_URL . '?stationID,longitude,latitude', json_encode(['table' => [
            'columnNames' => ['stationID', 'longitude', 'latitude'],
            'rows' => [['Dublin_Port', -6.2217, 53.3457], ['Galway', -9.0479, 53.269]],
        ]]));
        $this->http->on(MarineInstituteTideSource::BASE_URL . '?time,tide_time_category', json_encode(['table' => [
            'columnNames' => ['time', 'tide_time_category', 'Water_Level_ODMalin'],
            'rows' => [
                ['2026-11-14T20:15:00Z', 'LOW', -1.4],
                ['2026-11-15T02:50:00Z', 'HIGH', 1.3],
                ['2026-11-15T08:25:00Z', 'LOW', -1.2],
                ['2026-11-15T15:00:00Z', 'HIGH', 1.5],
            ],
        ]]));

        $tide = (new MarineInstituteTideSource($this->cache(), $this->http))->tide(53.346, -6.22, null, $this->from, $this->to, 15000);

        $this->assertSame('Dublin Port', $tide['station']);
        $this->assertSame('©Marine Institute', $tide['attribution']);
        $this->assertSame(['NW', 'HW', 'NW', 'HW'], array_column($tide['events'], 'type'));
        $url = $this->http->requests[1]['url'];
        $this->assertStringContainsString('stationID=%22Dublin_Port%22', $url);
        $this->assertStringContainsString('time%3E=2026-11-14T00:00:00Z', $url, '< and > percent-encoded for ERDDAP');
    }

    public function testKartverketAnyPointAndNoDataOutsideNorway(): void
    {
        $this->http->on(KartverketTideSource::BASE_URL . '?tide_request=locationdata&lat=60.39', <<<'XML'
            <tide><locationdata>
            <location name="Bergen" code="BGO" latitude="60.390000" longitude="5.320000"/>
            <data type="prediction" unit="cm">
            <waterlevel value="142.1" time="2026-11-15T01:07:00+00:00" flag="high"/>
            <waterlevel value="77.7" time="2026-11-15T06:42:00+00:00" flag="low"/>
            <waterlevel value="146.9" time="2026-11-15T13:16:00+00:00" flag="high"/>
            </data></locationdata></tide>
            XML);
        $this->http->on(KartverketTideSource::BASE_URL . '?tide_request=locationdata&lat=58.1', '<tide><locationdata><nodata info="Sorry."/></locationdata></tide>');
        $source = new KartverketTideSource($this->cache(), $this->http);

        $tide = $source->tide(60.3912, 5.3204, null, $this->from, $this->to, 15000);
        $this->assertSame('Bergen', $tide['station']);
        $this->assertSame('kartverket', $tide['source']);
        $this->assertSame(['HW', 'NW', 'HW'], array_column($tide['events'], 'type'));
        $this->assertEqualsWithDelta(0.67, $tide['mean_range_m'], 0.02);

        $this->assertNull($source->tide(58.1, 11.5, null, $this->from, $this->to, 15000), 'Swedish coast: nodata');
        $count = count($this->http->requests);
        $this->assertNull($source->tide(58.1, 11.5, null, $this->from, $this->to, 15000));
        $this->assertCount($count, $this->http->requests, 'nodata is remembered');
        $this->assertNull($source->tide(53.55, 9.97, null, $this->from, $this->to, 15000), 'outside the box, not asked');
        $this->assertCount($count, $this->http->requests);
    }

    public function testCachedEventsAreReusedWhileTheyCoverTheWindow(): void
    {
        $this->http->on(KartverketTideSource::BASE_URL, '<tide><locationdata><location name="Bergen"/><data><waterlevel value="140" time="2026-11-15T01:07:00+00:00" flag="high"/><waterlevel value="70" time="2026-11-15T07:07:00+00:00" flag="low"/></data></locationdata></tide>');
        (new KartverketTideSource($this->cache(), $this->http))->tide(60.39, 5.32, null, $this->from, $this->to, 15000);
        (new KartverketTideSource($this->cache(), $this->http))->tide(60.39, 5.32, null, $this->from + 86400, $this->to + 86400, 15000);
        $this->assertCount(1, $this->http->requests, 'one fetch covers 35 days');

        (new KartverketTideSource($this->cache(), $this->http))->tide(60.39, 5.32, null, $this->from + 60 * 86400, $this->to + 60 * 86400, 15000);
        $this->assertCount(2, $this->http->requests, 'a later window is fetched again');
    }

    public function testUkhoWithKeyHeaderAndNothingCached(): void
    {
        $this->http->on(UkhoTideSource::BASE_URL . '/0113/TidalEvents', json_encode([
            ['EventType' => 'HighWater', 'DateTime' => '2026-11-15T04:02:00', 'Height' => 6.46],
            ['EventType' => 'LowWater', 'DateTime' => '2026-11-15T10:30:00', 'Height' => 1.03],
            ['EventType' => 'HighWater', 'DateTime' => '2026-11-15T16:29:00', 'Height' => 6.48],
        ]));
        $this->http->on(UkhoTideSource::BASE_URL, json_encode(['type' => 'FeatureCollection', 'features' => [
            ['geometry' => ['coordinates' => [-0.0883, 51.5072]], 'properties' => ['Id' => '0113', 'Name' => 'London Bridge (Tower Pier)']],
            ['geometry' => ['coordinates' => [-0.3167, 51.4667]], 'properties' => ['Id' => '0116', 'Name' => 'Richmond Lock']],
        ]]));
        $source = new UkhoTideSource('test-key', $this->http);

        $tide = $source->tide(51.505, -0.09, null, $this->from, $this->to, 15000);

        $this->assertSame('London Bridge (Tower Pier)', $tide['station']);
        $this->assertSame('ukho', $tide['source']);
        $this->assertSame(['HW', 'NW', 'HW'], array_column($tide['events'], 'type'));
        $this->assertSame(strtotime('2026-11-15 04:02:00 UTC'), $tide['events'][0]['time'], 'times are UTC');
        $this->assertContains('Ocp-Apim-Subscription-Key: test-key', $this->http->requests[0]['headers']);
        $this->assertSame([], glob($this->cacheDir . '/*') ?: [], 'the Discovery tier forbids caching');

        $source->tide(51.505, -0.09, null, $this->from, $this->to, 15000);
        $this->assertCount(3, $this->http->requests, 'station list kept in memory, events fetched again');
        $this->assertNull((new UkhoTideSource('', $this->http))->tide(51.505, -0.09, null, $this->from, $this->to, 15000), 'no key, no source');
        $this->assertNull($source->tide(53.55, 9.97, null, $this->from, $this->to, 15000), 'outside the UK');
        $this->assertCount(3, $this->http->requests);
    }

    public function testUkhoSkipsAStationWithHighWatersOnly(): void
    {
        $this->http->on(UkhoTideSource::BASE_URL . '/0516/TidalEvents', json_encode([
            ['EventType' => 'HighWater', 'DateTime' => '2026-11-15T10:18:00', 'Height' => 9.1],
            ['EventType' => 'HighWater', 'DateTime' => '2026-11-15T22:38:00', 'Height' => 9.3],
        ]));
        $this->http->on(UkhoTideSource::BASE_URL . '/0517/TidalEvents', json_encode([
            ['EventType' => 'HighWater', 'DateTime' => '2026-11-15T10:00:00', 'Height' => 9.0],
            ['EventType' => 'LowWater', 'DateTime' => '2026-11-15T16:20:00', 'Height' => 1.0],
        ]));
        $this->http->on(UkhoTideSource::BASE_URL, json_encode(['features' => [
            ['geometry' => ['coordinates' => [-2.6667, 51.65]], 'properties' => ['Id' => '0516', 'Name' => 'Chepstow']],
            ['geometry' => ['coordinates' => [-2.70, 51.60]], 'properties' => ['Id' => '0517', 'Name' => 'Beachley']],
        ]]));

        $tide = (new UkhoTideSource('k', $this->http))->tide(51.645, -2.67, null, $this->from, $this->to, 15000);

        $this->assertSame('Beachley', $tide['station']);
    }

    public function testInstitutoHidrograficoAndTheReallyCoveredWindow(): void
    {
        $this->http->on(IhTideSource::BASE_URL . '/tides/', json_encode(['type' => 'FeatureCollection', 'features' => [
            ['geometry' => ['coordinates' => [-9.1632, 38.7001]], 'properties' => ['CODP' => 152, 'PORTO' => 'Lisboa - Alcântara']],
            ['geometry' => ['coordinates' => [113.5267, 22.1883]], 'properties' => ['CODP' => 814, 'PORTO' => 'Macau (Porto Interior)']],
        ]]));
        $this->http->on(IhTideSource::BASE_URL . '/tidestation/', json_encode([
            ['date' => '2026-11-09 07:02:00', 'height' => null, 'tide' => null, 'event' => 'Lua Nova'],
            ['date' => '2026-11-14 23:39:00', 'height' => 1.45, 'tide' => 'BM'],
            ['date' => '2026-11-15 06:10:00', 'height' => 3.13, 'tide' => 'PM'],
            ['date' => '2026-11-15 12:22:00', 'height' => 1.43, 'tide' => 'BM'],
            ['date' => '2026-11-15 18:39:00', 'height' => 2.81, 'tide' => 'PM'],
        ]));
        $source = new IhTideSource($this->cache(), $this->http);

        $tide = $source->tide(38.70, -9.17, null, $this->from, $this->from + 86400, 15000);

        $this->assertSame('Lisboa - Alcântara', $tide['station']);
        $this->assertSame('ih', $tide['source']);
        $this->assertSame(['NW', 'HW', 'NW', 'HW'], array_column($tide['events'], 'type'), 'moon phase rows skipped');
        $this->assertSame(strtotime('2026-11-15 06:10:00 UTC'), $tide['events'][1]['time'], 'times are UTC');
        $this->assertStringContainsString('portID=152', $this->http->requests[1]['url']);

        // the cache only covers up to the last event, so a later day is asked again
        $count = count($this->http->requests);
        (new IhTideSource($this->cache(), $this->http))->tide(38.70, -9.17, null, $this->from, $this->from + 3600, 15000);
        $this->assertCount($count, $this->http->requests, 'inside the covered window: from the cache');
        (new IhTideSource($this->cache(), $this->http))->tide(38.70, -9.17, null, $this->from + 3 * 86400, $this->from + 4 * 86400, 15000);
        $this->assertCount($count + 1, $this->http->requests, 'beyond the last event: asked again');
    }

    public function testTypedAndMeanRange(): void
    {
        $events = GaugeTideSource::typed([[0, -0.3], [100, 1.04], [200, -0.59], [300, 1.16]]);

        $this->assertSame(['NW', 'HW', 'NW', 'HW'], array_column($events, 'type'));
        $this->assertEqualsWithDelta(1.57, GaugeTideSource::meanRange($events), 0.01);
    }
}
