<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\ShelterImport\ShelterImportRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\ShelterImportController;
use Ytan\Service\OverpassThrottle;
use Ytan\Service\ShelterImportFile;
use Ytan\Service\ShelterImportService;
use Ytan\Service\ShelterList\GeoFaShelterSource;
use Ytan\Service\ShelterList\KartverketShelterSource;
use Ytan\Service\ShelterList\LipasShelterSource;
use Ytan\Service\ShelterList\NvvShelterSource;
use Ytan\Service\ShelterList\ShelterOsmMapper;
use Ytan\Service\ShelterOsmImportService;
use Ytan\Service\ShelterSourceImportService;
use Ytan\Service\Water\WaterDistanceService;
use Ytan\Tests\Fixtures\FakeOverpassClient;

/**
 * Shelter import: the four official lists (parsing of canned answers), the
 * OSM query, the distances to water computed while staging (from the sample
 * index tests/testdata/water-index-sample.bin: around 56.0 N / 9.0 E a coast
 * 100 m north, a river 300 m east, a lake ~32 km north), the possible-duplicate
 * hint (several shelters at one place are NOT duplicates), the import into
 * POIs of type 10 (one per shelter, booking link) and the transfer file.
 */
final class ShelterImportTest extends ControllerTestCase
{
    private ShelterImportRepository $staging;
    private FakeOverpassClient $client;
    private ShelterOsmImportService $osm;
    private WaterDistanceService $water;
    private ShelterImportController $controller;
    private string $throttleFile;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (10, 'Shelter')");
        $this->water = new WaterDistanceService(dirname(__DIR__) . '/testdata/water-index-sample.bin');
        $this->staging = new ShelterImportRepository($this->pdo, $this->water);
        $this->client = new FakeOverpassClient($this->osmAnswer());
        $this->throttleFile = sys_get_temp_dir() . '/ytan-shelter-test-' . bin2hex(random_bytes(4));
        $throttle = new OverpassThrottle($this->throttleFile, fn () => $this->now);
        $this->osm = new ShelterOsmImportService($this->client, $this->staging, new ShelterOsmMapper(), $throttle);
        $this->controller = new ShelterImportController(
            $this->staging,
            new ShelterImportService($this->staging, new PoiRepository($this->pdo)),
            $this->osm,
            new ShelterSourceImportService($this->staging, [new GeoFaShelterSource(fn () => $this->geoFaAnswer())]),
            $this->water
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->throttleFile);
        parent::tearDown();
    }

    /** Overpass answer: every shelter once (the same element can come twice, e.g. node and way). */
    private function osmAnswer(): array
    {
        $hut = ['type' => 'node', 'id' => 1, 'lat' => 55.1, 'lon' => 14.7, 'tags' => [
            'amenity' => 'shelter', 'shelter_type' => 'lean_to', 'name' => 'Kystshelter', 'fee' => 'no',
            'reservation' => 'required', 'website' => 'book.example.dk/kyst',
        ]];

        return ['elements' => [
            $hut,
            ['type' => 'way', 'id' => 2, 'center' => ['lat' => 55.2, 'lon' => 14.8], 'tags' => ['amenity' => 'shelter']],
            $hut,
            ['type' => 'node', 'id' => 3, 'lat' => 55.3, 'lon' => 14.9, 'tags' => ['tourism' => 'wilderness_hut']],
        ]];
    }

    private function geoFaAnswer(): string
    {
        $feature = fn (string $id, string $name, float $lat, float $lng, array $more = []) => ['type' => 'Feature', 'properties' => $more + [
            'objekt_id' => $id, 'facil_ty_k' => 3012, 'navn' => $name, 'beskrivels' => null, 'link' => null,
            'bookinglink' => null, 'antal_pl' => null, 'betaling' => null, 'book' => null, 'cvr_navn' => 'Naturstyrelsen',
            'lat' => $lat, 'lng' => $lng,
        ]];

        return json_encode(['success' => true, 'type' => 'FeatureCollection', 'features' => [
            // Two shelters of one place, 10 m apart, each with its own booking page.
            $feature('a', 'Egernbo', 56.0, 9.0, ['bookinglink' => 'https://bookenshelter.dk/egernbo', 'book' => 'Ja', 'antal_pl' => 6]),
            $feature('b', 'Mosebo', 56.00009, 9.0, ['bookinglink' => 'https://bookenshelter.dk/mosebo', 'link' => 'https://naturstyrelsen.dk/x   ']),
            $feature('c', '', 56.5, 9.5, ['link' => 'http://   ', 'betaling' => 'Nej']),
            $feature('d', 'Grillhytte', 56.6, 9.6, ['facil_ty_k' => 9999]),
        ]]);
    }

    private function admin(): array
    {
        return $this->authPayload($this->createUser(['is_admin' => 1]), ['is_admin' => true]);
    }

    private function geoFaEntries(): array
    {
        $rows = [];
        foreach (GeoFaShelterSource::parse($this->geoFaAnswer()) as $row) {
            $rows[$row['source_ref']] = $row;
        }

        return $rows;
    }

    public function testGeoFaKeepsEveryShelterAndPrefersTheBookingLink(): void
    {
        $rows = $this->geoFaEntries();

        $this->assertSame(['a', 'b', 'c'], array_keys($rows), 'unknown facility types are dropped');
        $this->assertSame('https://bookenshelter.dk/egernbo', $rows['a']['website']);
        $this->assertSame('https://bookenshelter.dk/mosebo', $rows['b']['website'], 'bookinglink before link');
        $this->assertSame('', $rows['c']['website'], 'a bare "http://" is no link');
        $this->assertStringContainsString('Buchung erforderlich', $rows['a']['description']);
        $this->assertStringContainsString('Plätze: 6', $rows['a']['description']);
        $this->assertStringContainsString('Kostenlos', $rows['c']['description']);
        $this->assertSame('DK', $rows['a']['country']);
        $this->assertArrayNotHasKey('sea_m', $rows['a'], 'the list does not know the water');
        $this->assertStringContainsString('facil_ty_k in (3012,3022,3091)', urldecode(GeoFaShelterSource::requestUrl()));
    }

    public function testGeoFaRejectsAnErrorAnswer(): void
    {
        $this->expectException(ApiException::class);
        GeoFaShelterSource::parse('{"success":false,"message":"SQLSTATE"}');
    }

    public function testNaturvardsverketGmlIsParsed(): void
    {
        $gml = '<?xml version="1.0" encoding="utf-8"?><wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0" xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:A="https://geodata.naturvardsverket.se/anordningar_friluftsliv/wfs">'
            . '<wfs:member><A:ANORDNINGAR gml:id="x.1"><A:Anordning_ID>301</A:Anordning_ID><A:Anordningsnamn>Vindskydd Ångsågen</A:Anordningsnamn><A:Typ>Vindskydd</A:Typ><A:Undertyp>Vindskydd</A:Undertyp>'
            . '<A:Beskrivning>Vid sjön</A:Beskrivning><A:Skyddat_område>Hökensås (2000001)</A:Skyddat_område><A:SHAPE><gml:MultiPoint><gml:pointMember><gml:Point><gml:pos>58.0655953 14.0913748</gml:pos></gml:Point></gml:pointMember></gml:MultiPoint></A:SHAPE></A:ANORDNINGAR></wfs:member>'
            . '<wfs:member><A:ANORDNINGAR gml:id="x.2"><A:Anordning_ID>302</A:Anordning_ID><A:Typ>Övernattningsstuga</A:Typ><A:SHAPE><gml:Point><gml:pos>59.1 15.2</gml:pos></gml:Point></A:SHAPE></A:ANORDNINGAR></wfs:member>'
            . '<wfs:member><A:ANORDNINGAR gml:id="x.3"><A:Anordning_ID>303</A:Anordning_ID><A:Typ>Parkering</A:Typ><A:SHAPE><gml:Point><gml:pos>59.1 15.2</gml:pos></gml:Point></A:SHAPE></A:ANORDNINGAR></wfs:member>'
            . '</wfs:FeatureCollection>';

        $rows = NvvShelterSource::parse($gml);

        $this->assertCount(2, $rows, 'Parkering is not a shelter');
        $this->assertSame('Vindskydd Ångsågen', $rows[0]['name']);
        $this->assertSame(58.0655953, $rows[0]['latitude'], 'gml:pos is "lat lon"');
        $this->assertSame(14.0913748, $rows[0]['longitude']);
        $this->assertStringContainsString('Gebiet: Hökensås', $rows[0]['description']);
        $this->assertStringNotContainsString('2000001', $rows[0]['description']);
        $this->assertSame('hut', $rows[1]['kind']);
        $this->assertSame('SE', $rows[1]['country']);
        $this->assertSame('Övernattningsstuga', $rows[1]['name'], 'no name, no area, no municipality: the type');
    }

    public function testNaturvardsverketNamesWithoutOwnName(): void
    {
        $this->assertSame('Rastplats med vindskydd', NvvShelterSource::name('Rastplats med vindskydd', 'Vindskydd', 'Hökensås (2000001)', 'Tidaholm'), 'an own name stays');
        $this->assertSame('Vindskydd Färnebofjärden', NvvShelterSource::name('', 'Vindskydd', 'Färnebofjärden (2000932)', 'Sala'), 'type + protected area');
        $this->assertSame('Vindskydd Långfjället', NvvShelterSource::name('vindskydd', 'Vindskydd', 'Långfjället (2002448), Långfjället-Städjan-Nipfjället (SE0620001)', 'Älvdalen'), 'only the type as name counts as none; first area');
        $this->assertSame('Raststuga Älvdalen', NvvShelterSource::name('', 'Raststuga', '', 'Älvdalen'), 'no area: the municipality');
    }

    public function testKartverketGmlIsParsed(): void
    {
        $point = fn (string $id, string $code, string $origin) => '<wfs:member><app:RuteInfoPunkt gml:id="r.' . $id . '"><app:identifikasjon><app:Identifikasjon><app:lokalId>' . $id . '</app:lokalId></app:Identifikasjon></app:identifikasjon>'
            . '<app:opphav>' . $origin . '</app:opphav><app:posisjon><gml:Point><gml:pos>70.63475 23.789304</gml:pos></gml:Point></app:posisjon>'
            . '<app:tilrettelegging>' . $code . '</app:tilrettelegging><app:vedlikeholdsansvarlig>DNT</app:vedlikeholdsansvarlig></app:RuteInfoPunkt></wfs:member>';
        $gml = '<wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0" xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:app="http://skjema.geonorge.no/SOSI/produktspesifikasjon/TurOgFriluftsruter/20171210">'
            . $point('u1', '44', 'Rundvannshytta') . $point('u2', '45', '') . $point('u3', '22', 'Parkering') . '</wfs:FeatureCollection>';

        $rows = KartverketShelterSource::parse($gml);

        $this->assertCount(2, $rows, 'code 22 (parking) is dropped');
        $this->assertSame('Rundvannshytta', $rows[0]['name']);
        $this->assertSame('hut', $rows[0]['kind']);
        $this->assertStringContainsString('Hytte ubetjent', $rows[0]['description']);
        $this->assertStringContainsString('Zuständig: DNT', $rows[0]['description']);
        $this->assertSame('shelter', $rows[1]['kind']);
        $this->assertStringContainsString('Gapahuk', $rows[1]['description']);
        $this->assertSame('NO', $rows[1]['country']);
    }

    public function testLipasPagesThroughTheList(): void
    {
        $item = fn (int $id, int $type, array $more = []) => $more + [
            'lipas-id' => $id, 'name' => 'Laavu ' . $id, 'status' => 'active', 'type' => ['type-code' => $type],
            'properties' => ['free-use?' => true],
            'location' => ['geometries' => ['features' => [['geometry' => ['type' => 'Point', 'coordinates' => [24.9, 63.2]]]]]],
        ];
        $pages = [
            1 => ['items' => [$item(1, 301, ['www' => 'https://www.luontoon.fi/x']), $item(2, 9999)], 'pagination' => ['total-pages' => 2]],
            2 => ['items' => [$item(3, 302)], 'pagination' => ['total-pages' => 2]],
        ];
        $urls = [];
        $source = new LipasShelterSource(function (string $url) use ($pages, &$urls): string {
            $urls[] = $url;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return json_encode($pages[(int) $query['page']]);
        });

        $rows = $source->fetch();

        $this->assertCount(2, $urls);
        $this->assertSame(['1', '3'], array_column($rows, 'source_ref'));
        $this->assertSame(63.2, $rows[0]['latitude'], 'GeoJSON is lng, lat');
        $this->assertSame(24.9, $rows[0]['longitude']);
        $this->assertSame('https://www.luontoon.fi/x', $rows[0]['website']);
        $this->assertSame('hut', $rows[1]['kind']);
        $this->assertStringContainsString('Frei zugänglich', $rows[0]['description']);
    }

    public function testOsmLoadStagesEveryShelterWithoutADistanceTest(): void
    {
        $result = $this->osm->import('dk');

        $this->assertSame(3, $result['inserted']);
        $rows = [];
        foreach ($this->staging->search([], null) as $row) {
            $rows[$row['source_ref']] = $row;
        }
        $this->assertCount(3, $rows, 'a shelter in two sections is one entry');
        $this->assertNotNull($rows['node/1']['water_checked_at'], 'distances computed while staging');
        $this->assertNull($rows['node/1']['sea_m'], 'Bornholm is far from the sample index lines');
        $this->assertSame('hut', $rows['node/3']['kind']);
        $this->assertSame('https://book.example.dk/kyst', $rows['node/1']['website']);
        $this->assertStringContainsString('Buchung erforderlich', $rows['node/1']['description']);

        $query = $this->client->queries[0];
        $this->assertStringContainsString('area["ISO3166-1"="DK"]', $query);
        $this->assertStringNotContainsString('around', $query, 'no distance filter in Overpass');
        $this->assertStringContainsString('picnic_shelter', $query);
    }

    public function testOsmRejectsABadCountryAndSpacesRequests(): void
    {
        try {
            $this->osm->import('Denmark');
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
        }
        $this->osm->import('DK');
        $this->now += 10;
        try {
            $this->osm->import('SE');
            $this->fail('Expected the second request to be refused.');
        } catch (ApiException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
        $this->assertCount(1, $this->client->queries);
    }

    private function geofaRows(): array
    {
        $rows = [];
        foreach ($this->staging->search(['source' => 'geofa'], null) as $row) {
            $rows[$row['source_ref']] = $row;
        }

        return $rows;
    }

    public function testDistancesAreComputedWhileLoading(): void
    {
        (new ShelterSourceImportService($this->staging, [new GeoFaShelterSource(fn () => $this->geoFaAnswer())]))->import('geofa');

        $a = $this->geofaRows()['a'];
        $this->assertEqualsWithDelta(100, (int) $a['sea_m'], 2);
        $this->assertEqualsWithDelta(300, (int) $a['river_m'], 2);
        $this->assertEqualsWithDelta(32250, (int) $a['lake_m'], 200);
        $this->assertNotNull($a['water_checked_at']);
        $this->assertSame(0, $this->staging->countUnchecked());
    }

    public function testFiltersPerKindOfWater(): void
    {
        (new ShelterSourceImportService($this->staging, [new GeoFaShelterSource(fn () => $this->geoFaAnswer())]))->import('geofa');

        // a (sea 100 m) and b (10 m north of a: sea 90 m); c is ~55 km away.
        $this->assertSame(2, $this->staging->count(['max_sea' => 150]));
        $this->assertSame(1, $this->staging->count(['max_sea' => 95]));
        $this->assertSame(2, $this->staging->count(['max_river' => 400]));
        $this->assertSame(0, $this->staging->count(['max_lake' => 1000]));
        $this->assertSame(2, $this->staging->count(['max_any' => 150]));
        $this->assertSame(2, $this->staging->count(['max_any' => 150, 'max_river' => 400]), 'filters combine');
        $this->assertSame(3, $this->staging->count(['water' => 'checked']));
        $this->assertSame(0, $this->staging->count(['water' => 'unchecked']));
    }

    public function testRefreshKeepsTheDistancesUnlessTheShelterMoved(): void
    {
        $source = new ShelterSourceImportService($this->staging, [new GeoFaShelterSource(fn () => $this->geoFaAnswer())]);
        $source->import('geofa');
        $this->pdo->exec("UPDATE shelter_import SET sea_m = 7 WHERE source = 'geofa'");

        $source->import('geofa');
        $this->assertSame(3, $this->staging->count(['max_sea' => 7]), 'same positions: stored distances kept');

        $moved = new ShelterSourceImportService($this->staging, [new GeoFaShelterSource(
            fn () => str_replace('"lat":56,', '"lat":56.01,', $this->geoFaAnswer())
        )]);
        $moved->import('geofa');
        $this->assertSame(2, $this->staging->count(['max_sea' => 7]), 'a moved shelter is computed again');
        $this->assertEqualsWithDelta(1012, (int) $this->geofaRows()['a']['sea_m'], 3, '1.1 km north of the start, 1 km beyond the coast line');
    }

    public function testWithoutAnIndexDistancesStayEmptyAndAreComputedLater(): void
    {
        $log = ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'log'));
        try {
            $noIndex = new ShelterImportRepository($this->pdo, new WaterDistanceService(sys_get_temp_dir() . '/no-such-water-index.bin'));
            (new ShelterSourceImportService($noIndex, [new GeoFaShelterSource(fn () => $this->geoFaAnswer())]))->import('geofa');
            $this->assertSame(3, $this->staging->countUnchecked());
            $this->assertSame(0, $noIndex->recomputeDistances(false), 'still no index');
        } finally {
            @unlink((string) ini_get('error_log'));
            ini_set('error_log', (string) $log);
        }

        $this->assertSame(3, $this->staging->recomputeDistances(false));
        $this->assertSame(0, $this->staging->countUnchecked());
        $this->assertSame(0, $this->staging->recomputeDistances(false), 'nothing left');
        $this->assertSame(3, $this->staging->recomputeDistances(true), '--all');
        $this->assertEqualsWithDelta(100, (int) $this->geofaRows()['a']['sea_m'], 2);
    }

    public function testOnlyAShelterPoiNearbyMakesAPossibleDuplicate(): void
    {
        (new ShelterSourceImportService($this->staging, [new GeoFaShelterSource(fn () => $this->geoFaAnswer())]))->import('geofa');
        // OSM knows shelter "a" 5 m off - not imported, so no duplicate yet (first import of a list).
        $this->staging->upsertMany([[
            'source' => 'osm', 'source_ref' => 'node/9', 'name' => 'Egernbo', 'kind' => 'shelter',
            'country' => 'DK', 'description' => '', 'website' => '', 'latitude' => 56.00004, 'longitude' => 9.0,
        ]]);
        $this->assertSame(0, $this->staging->count(['duplicate' => 'yes']), 'other sources\' staged entries do not count');
        $this->assertSame(4, $this->staging->count(['duplicate' => 'no']));

        // A hand-drawn shelter POI next to "c", and "a" imported (so it becomes a POI next to node/9).
        $pois = new PoiRepository($this->pdo);
        $pois->create($this->createUser(), ['poitype_id' => 10, 'name' => 'Mein Shelter', 'latitude' => 56.5001, 'longitude' => 9.5001, 'public' => 1]);
        $a = $this->geofaRows()['a'];
        (new ShelterImportService($this->staging, $pois))->import([(int) $a['id']], $this->createUser(), true);

        $duplicates = array_column($this->staging->search(['duplicate' => 'yes'], null), 'source_ref');
        sort($duplicates);
        $this->assertSame(['b', 'c', 'node/9'], $duplicates, 'b and node/9 lie next to the imported a, c next to the hand-drawn POI; a not next to its own POI');

        $rows = $this->staging->search(['source' => 'geofa'], null);
        $neighbours = $this->staging->neighbours($rows);
        $byRef = array_combine(array_column($rows, 'source_ref'), array_column($rows, 'id'));
        $this->assertSame(['staged'], array_column($neighbours[(int) $byRef['a']], 'kind'), 'a: only the OSM entry as a hint');
        $this->assertSame('osm', $neighbours[(int) $byRef['a']][0]['source']);
        $this->assertFalse($neighbours[(int) $byRef['a']][0]['imported']);
        $this->assertSame('poi', $neighbours[(int) $byRef['c']][0]['kind']);
        $this->assertSame('Mein Shelter', $neighbours[(int) $byRef['c']][0]['name']);
    }

    public function testImportCreatesOnePoiPerShelterWithItsBookingLink(): void
    {
        $admin = $this->admin();
        $this->controller->loadSource($this->request('POST', '/x', $admin, ['source' => 'geofa']), $this->response());
        // The admin filters "sea at most 150 m" and selects all matches: a and b, not c.
        $ids = $this->decode($this->controller->ids($this->request('GET', '/x', $admin, null, ['max_sea' => '150', 'status' => 'open']), $this->response()))['data'];
        $this->assertCount(2, $ids);

        $result = $this->decode($this->controller->import(
            $this->request('POST', '/x', $admin, ['ids' => $ids, 'public' => true]),
            $this->response()
        ))['data'];

        $this->assertSame(['imported' => 2, 'skipped' => 0], $result);
        $pois = $this->pdo->query('SELECT * FROM poi WHERE poitype_id = 10 ORDER BY name')->fetchAll();
        $this->assertSame(['Egernbo', 'Mosebo'], array_column($pois, 'name'), 'two shelters at one place stay two POIs');
        $this->assertSame('https://bookenshelter.dk/egernbo', $pois[0]['url']);
        $this->assertSame('https://bookenshelter.dk/mosebo', $pois[1]['url']);
        $this->assertMatchesRegularExpression('/Abstand zu Gewässern: Meer \d+ m, Fluss \d+ m, See \d+ m/', $pois[0]['description']);
        $this->assertStringContainsString('GeoFA', $pois[0]['description']);
        $this->assertSame('1', (string) $pois[0]['public']);

        $again = $this->decode($this->controller->import(
            $this->request('POST', '/x', $admin, ['ids' => $ids]),
            $this->response()
        ))['data'];
        $this->assertSame(['imported' => 0, 'skipped' => 2], $again);
    }

    public function testImportStoresTheDistancesForThePoi(): void
    {
        $settings = new \Ytan\Domain\Settings\SettingsRepository($this->pdo);
        $distances = new \Ytan\Domain\Poi\PoiWaterDistanceRepository($this->pdo);
        $import = new ShelterImportService(
            $this->staging,
            new PoiRepository($this->pdo),
            new \Ytan\Service\Water\PoiWaterDistanceService($this->water, $distances, $settings)
        );
        (new ShelterSourceImportService($this->staging, [new GeoFaShelterSource(fn () => $this->geoFaAnswer())]))->import('geofa');
        $entry = $this->geofaRows()['a'];

        $import->import([(int) $entry['id']], $this->createUser(), true);

        $poiId = (int) $this->pdo->query("SELECT poi_id FROM shelter_import WHERE id = {$entry['id']}")->fetchColumn();
        $stored = $distances->find($poiId);
        $this->assertSame((int) $entry['sea_m'], $stored['sea_m']);
        $this->assertSame((int) $entry['river_m'], $stored['river_m']);
        $this->assertSame((int) $entry['lake_m'], $stored['lake_m']);
    }

    public function testUnnamedSheltersGetAGenericName(): void
    {
        $this->assertSame('Unterstand', ShelterImportService::name(['name' => ' ', 'kind' => 'shelter']));
        $this->assertSame('Hütte', ShelterImportService::name(['name' => '', 'kind' => 'hut']));
        $this->assertSame('Gapahuk Femundsmarka', ShelterImportService::name(['name' => '', 'kind' => 'shelter', 'type_label' => 'Gapahuk', 'place' => 'Femundsmarka']));
        $this->assertSame('Unterstand', ShelterImportService::name(['name' => '', 'kind' => 'shelter', 'type_label' => 'Gapahuk', 'place' => '']), 'no place: generic');
        $this->assertSame('Egernbo', ShelterImportService::name(['name' => 'Egernbo', 'kind' => 'shelter', 'type_label' => 'Shelter', 'place' => 'Mols']), 'an own name wins');
    }

    public function testPlacesForUnnamedSheltersAreKeptUntilTheyMove(): void
    {
        $source = new ShelterSourceImportService($this->staging, [new GeoFaShelterSource(fn () => $this->geoFaAnswer())]);
        $source->import('geofa');
        $unnamed = $this->staging->findWithoutName(false);
        $this->assertCount(1, $unnamed, 'only c has no name');
        $this->staging->setPlaces([(int) $unnamed[0]['id'] => 'Mols Bjerge']);

        $rows = array_column($this->staging->search(['source' => 'geofa'], null), null, 'source_ref');
        $this->assertSame('Shelter Mols Bjerge', $rows['c']['display_name']);
        $this->assertSame('Egernbo', $rows['a']['display_name']);
        $this->assertSame(1, $this->staging->count(['name' => 'Mols']), 'the name filter sees the generated name');
        $this->assertCount(0, $this->staging->findWithoutName(false), 'nothing left without a place');
        $this->assertCount(1, $this->staging->findWithoutName(true), '--all');

        $source->import('geofa');
        $this->assertSame('Shelter Mols Bjerge', array_column($this->staging->search(['source' => 'geofa'], null), 'display_name', 'source_ref')['c'], 'kept on reload');

        $moved = new ShelterSourceImportService($this->staging, [new GeoFaShelterSource(
            fn () => str_replace('"lat":56.5', '"lat":56.51', $this->geoFaAnswer())
        )]);
        $moved->import('geofa');
        $this->assertSame('', array_column($this->staging->search(['source' => 'geofa'], null), 'place', 'source_ref')['c'], 'moved: looked up again');
    }

    public function testUnknownSourceAndAdminOnly(): void
    {
        try {
            $this->controller->loadSource($this->request('POST', '/x', $this->admin(), ['source' => 'naturkartan']), $this->response());
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
        }

        $this->expectException(ForbiddenException::class);
        $this->controller->index($this->request('GET', '/x', $this->authPayload($this->createUser())), $this->response());
    }

    public function testIndexListsNeighbours(): void
    {
        $admin = $this->admin();
        $this->controller->loadSource($this->request('POST', '/x', $admin, ['source' => 'geofa']), $this->response());
        $this->osm->import('DK');

        $answer = $this->decode($this->controller->index($this->request('GET', '/x', $admin, null, ['source' => 'geofa']), $this->response()));

        $this->assertSame(3, $answer['meta']['total']);
        $this->assertSame(0, $answer['meta']['unchecked'], 'everything computed while loading');
        $this->assertSame('', $answer['meta']['water_index'], 'an index without a meta file has no version');
        $this->assertSame([], $answer['data'][0]['neighbours']);
    }

    public function testTransferFileRoundTripCarriesTheDistances(): void
    {
        $this->osm->import('DK');
        (new ShelterSourceImportService($this->staging, [new GeoFaShelterSource(fn () => $this->geoFaAnswer())]))->import('geofa');
        $this->pdo->exec("UPDATE shelter_import SET sea_m = 42 WHERE source_ref = 'a'");
        $file = json_decode(json_encode(ShelterImportFile::build($this->staging->all())), true);
        $this->assertSame(2, $file['version']);
        $this->pdo->exec('DELETE FROM shelter_import');

        $this->assertSame(['inserted' => 6, 'updated' => 0], $this->staging->upsertMany(ShelterImportFile::parse($file)));
        $this->assertSame(1, $this->staging->count(['max_sea' => 42]), 'taken from the file, not computed again');
        $this->assertSame(0, $this->staging->countUnchecked());
    }

    public function testVersionOneFilesAreReadWithoutTheirWaterFields(): void
    {
        $file = ['format' => ShelterImportFile::FORMAT, 'version' => 1, 'entries' => [[
            'source' => 'geofa', 'source_ref' => 'a', 'name' => 'Egernbo', 'kind' => 'shelter', 'country' => 'DK',
            'water' => 'sea', 'water_distance' => 9, 'description' => '', 'website' => '', 'latitude' => 56.0, 'longitude' => 9.0,
        ]]];

        $this->staging->upsertMany(ShelterImportFile::parse($file));

        $this->assertEqualsWithDelta(100, (int) $this->geofaRows()['a']['sea_m'], 2, 'computed here instead');
    }

    public function testTransferFileRejectsBadEntries(): void
    {
        $file = ['format' => ShelterImportFile::FORMAT, 'version' => 2, 'entries' => [[
            'source' => 'osm', 'source_ref' => 'node/1', 'name' => '', 'kind' => 'shelter', 'country' => 'DK',
            'description' => '', 'website' => '', 'latitude' => 55, 'longitude' => 10,
            'water_checked' => true, 'sea_m' => 'near', 'river_m' => null, 'lake_m' => null,
        ]]];
        $this->expectException(ValidationException::class);
        ShelterImportFile::parse($file);
    }
}
