<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\CampsiteImport\CampsiteImportRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\CampsiteImportController;
use Ytan\Service\Campsite\CampsiteOsmMapper;
use Ytan\Service\CampsiteImportFile;
use Ytan\Service\CampsiteImportService;
use Ytan\Service\CampsiteOsmImportService;
use Ytan\Service\OverpassThrottle;
use Ytan\Service\Water\WaterDistanceService;
use Ytan\Tests\Fixtures\FakeOverpassClient;

/**
 * Campsite import: the OSM mapping (category, name, facts, flags), the
 * distances to water computed while staging (from the sample index
 * tests/testdata/water-index-sample.bin: around 56.0 N / 9.0 E a coast 100 m
 * north, a river 300 m east), the filters, the possible-duplicate hint, the
 * import into POIs of type 11 / 1 and the transfer file.
 */
final class CampsiteImportTest extends ControllerTestCase
{
    private CampsiteImportRepository $staging;
    private FakeOverpassClient $client;
    private CampsiteOsmImportService $osm;
    private WaterDistanceService $water;
    private CampsiteImportController $controller;
    private string $throttleFile;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (1, 'Zeltplatz'), (11, 'Campingplatz')");
        $this->water = new WaterDistanceService(dirname(__DIR__) . '/testdata/water-index-sample.bin');
        $this->staging = new CampsiteImportRepository($this->pdo, $this->water);
        $this->client = new FakeOverpassClient($this->osmAnswer());
        $this->throttleFile = sys_get_temp_dir() . '/ytan-campsite-test-' . bin2hex(random_bytes(4));
        $throttle = new OverpassThrottle($this->throttleFile, fn () => $this->now);
        $this->osm = new CampsiteOsmImportService($this->client, $this->staging, new CampsiteOsmMapper(), $throttle);
        $this->controller = new CampsiteImportController(
            $this->staging,
            new CampsiteImportService($this->staging, new PoiRepository($this->pdo)),
            $this->water
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->throttleFile);
        parent::tearDown();
    }

    /** Overpass answer: two campsites near the sample water, a basic site, one without name but with a town. */
    private function osmAnswer(): array
    {
        $beach = ['type' => 'way', 'id' => 1, 'center' => ['lat' => 56.0, 'lon' => 9.0], 'tags' => [
            'tourism' => 'camp_site', 'name' => 'Strand Camping', 'camp_site' => 'serviced', 'stars' => '4',
            'tents' => 'yes', 'caravans' => 'no', 'motorhome' => 'yes', 'cabins' => 'yes', 'toilets' => 'yes',
            'shower' => 'hot', 'power_supply' => 'no', 'capacity' => '120', 'fee' => 'yes',
            'phone' => '+45 1234 5678;+45 9999', 'email' => 'info@strand.dk', 'website' => 'strand-camping.dk',
            'addr:street' => 'Strandvej', 'addr:housenumber' => '3', 'addr:postcode' => '8000', 'addr:city' => 'Aarhus',
        ]];

        return ['elements' => [
            $beach,
            ['type' => 'node', 'id' => 2, 'lat' => 56.00009, 'lon' => 9.0, 'tags' => ['tourism' => 'camp_site', 'name' => 'Fjord Camping']],
            ['type' => 'node', 'id' => 3, 'lat' => 56.5, 'lon' => 9.5, 'tags' => ['tourism' => 'camp_site', 'name' => 'Lejrplads', 'camp_site' => 'basic', 'fee' => 'no']],
            ['type' => 'node', 'id' => 4, 'lat' => 56.6, 'lon' => 9.6, 'tags' => ['tourism' => 'camp_site', 'backcountry' => 'yes', 'addr:city' => 'Silkeborg']],
            $beach,
        ]];
    }

    private function admin(): array
    {
        return $this->authPayload($this->createUser(['is_admin' => 1]), ['is_admin' => true]);
    }

    /** @return array<string, array<string,mixed>> staged rows by source_ref */
    private function rows(): array
    {
        return array_column($this->staging->search([], null), null, 'source_ref');
    }

    public function testOsmLoadMapsCategoryNameFactsAndFlags(): void
    {
        $result = $this->osm->import('dk');

        $this->assertSame(['fetched' => 5, 'skipped' => 1, 'inserted' => 4, 'updated' => 0, 'tile' => 0, 'tiles' => 6], $result, 'one duplicate element');
        $rows = $this->rows();
        ksort($rows);
        $this->assertSame(['node/2', 'node/3', 'node/4', 'way/1'], array_keys($rows));

        $beach = $rows['way/1'];
        $this->assertSame('campsite', $beach['category']);
        $this->assertSame('Aarhus', $beach['place']);
        $this->assertSame('https://strand-camping.dk', $beach['website']);
        $this->assertSame([1, 1, 1], [(int) $beach['tents'], (int) $beach['caravans'], (int) $beach['cabins']], 'caravans: motorhome yes wins over caravans no');
        foreach (['Kategorie: mit Service', 'Sterne: 4', 'Zelte: ja · Wohnwagen: nein · Wohnmobile: ja · Hütten: ja',
            'Ausstattung: Toiletten, Duschen', 'Stellplätze: 120', 'Gebührenpflichtig', 'Telefon: +45 1234 5678',
            'E-Mail: info@strand.dk', 'Adresse: Strandvej 3, 8000 Aarhus'] as $line) {
            $this->assertStringContainsString($line, $beach['description']);
        }
        $this->assertStringNotContainsString('Strom', $beach['description'], 'power_supply=no is no facility');

        $this->assertSame('basic', $rows['node/3']['category']);
        $this->assertNull($rows['node/3']['tents'], 'not tagged');
        $this->assertSame('basic', $rows['node/4']['category'], 'backcountry=yes');
        $this->assertSame('Zeltplatz Silkeborg', $rows['node/4']['name'], 'no name: kind + town');

        $query = $this->client->queries[0];
        $this->assertStringContainsString('area["ISO3166-1"="DK"]', $query);
        $this->assertStringContainsString('nwr["tourism"="camp_site"]', $query);
        $this->assertStringContainsString('nwr.c[!"name"]->.u;', $query, 'surroundings only for unnamed sites');
    }

    public function testBigCountriesAreLoadedInTilesOfAtMostThreeDegrees(): void
    {
        $tiles = CampsiteOsmImportService::tiles('FR');
        $this->assertCount(20, $tiles, 'France at 3 degrees: 4 x 5 (CountryTilesTest)');

        $this->assertStringContainsString('nwr["tourism"="camp_site"](area.a)(41.3,-5.3,43.775,-2.3)->.c;', CampsiteOsmImportService::query('FR', $tiles[0]));
        $this->assertStringContainsString('nwr["tourism"="camp_site"](area.a)->.c;', CampsiteOsmImportService::query('FO'));

        $result = $this->osm->import('FR', false, 19);
        $this->assertSame([19, 20], [$result['tile'], $result['tiles']]);
        $this->assertStringContainsString('(48.725,6.7,51.2,9.7)', $this->client->queries[0]);
        $this->expectException(ValidationException::class);
        $this->now += 60;
        $this->osm->import('FR', false, 20);
    }

    public function testATileIsCutToItsBox(): void
    {
        $answer = $this->osm->import('DK', false, 2);
        $this->assertSame([2, 6], [$answer['tile'], $answer['tiles']]);
        $this->assertStringContainsString('(54.5,12.8333,56.15,15.3)', $this->client->queries[0]);
    }

    public function testUnnamedCampsitesAreNamedByNearestPlaceAndStreetOrCampsite(): void
    {
        $this->client->willAnswer(['elements' => [
            // Unnamed: nearest place Ry (2 km; Skanderborg 5 km), street 50 m away; Langvej 500 m is too far.
            ['type' => 'node', 'id' => 10, 'lat' => 56.1, 'lon' => 9.8, 'tags' => ['tourism' => 'camp_site']],
            // Unnamed, basic, town from the address, street nearby.
            ['type' => 'node', 'id' => 11, 'lat' => 56.3, 'lon' => 9.8, 'tags' => ['tourism' => 'camp_site', 'camp_site' => 'basic', 'addr:city' => 'Them']],
            // Unnamed, no place within 10 km, no street.
            ['type' => 'node', 'id' => 12, 'lat' => 57.5, 'lon' => 8.0, 'tags' => ['tourism' => 'camp_site']],
            // Named: surroundings ignored.
            ['type' => 'node', 'id' => 13, 'lat' => 56.1, 'lon' => 9.8001, 'tags' => ['tourism' => 'camp_site', 'name' => 'Ry Camping']],
            ['type' => 'node', 'id' => 100, 'lat' => 56.118, 'lon' => 9.8, 'tags' => ['place' => 'town', 'name' => 'Ry']],
            ['type' => 'node', 'id' => 101, 'lat' => 56.145, 'lon' => 9.8, 'tags' => ['place' => 'town', 'name' => 'Skanderborg']],
            ['type' => 'node', 'id' => 102, 'lat' => 56.1, 'lon' => 9.81, 'tags' => ['place' => 'farm', 'name' => 'Gården']],
            ['type' => 'way', 'id' => 200, 'tags' => ['highway' => 'residential', 'name' => 'Søvej'],
                'geometry' => [['lat' => 56.0995, 'lon' => 9.79], ['lat' => 56.0995, 'lon' => 9.81]]],
            ['type' => 'way', 'id' => 201, 'tags' => ['highway' => 'track', 'name' => 'Langvej'],
                'geometry' => [['lat' => 56.105, 'lon' => 9.79], ['lat' => 56.105, 'lon' => 9.81]]],
            ['type' => 'way', 'id' => 202, 'tags' => ['highway' => 'track', 'name' => 'Skovsti'],
                'geometry' => [['lat' => 56.3, 'lon' => 9.799], ['lat' => 56.3005, 'lon' => 9.8015]]],
        ]]);

        $result = $this->osm->import('DK');

        $this->assertSame(['fetched' => 4, 'skipped' => 0, 'inserted' => 4, 'updated' => 0, 'tile' => 0, 'tiles' => 6], $result, 'places and streets are no campsites');
        $rows = $this->rows();
        $this->assertSame('Campingplatz Ry, Søvej', $rows['node/10']['name']);
        $this->assertSame('Ry', $rows['node/10']['place'], 'nearest place fills the town filter too');
        $this->assertSame('Zeltplatz Them, Skovsti', $rows['node/11']['name']);
        $this->assertSame('Campsite', $rows['node/12']['name']);
        $this->assertSame('', (string) $rows['node/12']['place']);
        $this->assertSame('Ry Camping', $rows['node/13']['name']);
        $this->assertStringContainsString('["highway"!~"^motorway"]', $this->client->queries[0], 'no motorways as street names');
        $this->assertSame('Zeltplatz Feddet', CampsiteOsmMapper::generatedName('basic', 'Feddet', 'Feddet'), 'street named like the town');
        $this->assertSame('Campingplatz Søvej', CampsiteOsmMapper::generatedName('campsite', '', 'Søvej'));
    }

    public function testOsmRejectsCountriesOutsideEuropeAndSpacesRequests(): void
    {
        try {
            $this->osm->import('US');
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

    public function testDistancesAreComputedWhileLoadingAndFiltered(): void
    {
        $this->osm->import('DK');

        $beach = $this->rows()['way/1'];
        $this->assertEqualsWithDelta(100, (int) $beach['sea_m'], 2);
        $this->assertEqualsWithDelta(300, (int) $beach['river_m'], 2);
        $this->assertSame(0, $this->staging->countUnchecked());

        // way/1 (sea 100 m) and node/2 (10 m north: sea 90 m); the others are ~55+ km away.
        $this->assertSame(2, $this->staging->count(['max_sea' => 150]));
        $this->assertSame(1, $this->staging->count(['max_sea' => 95]));
        $this->assertSame(2, $this->staging->count(['max_any' => 400]));
        $this->assertSame(2, $this->staging->count(['category' => 'basic']));
        $this->assertSame(1, $this->staging->count(['tents' => 'yes']));
        $this->assertSame(0, $this->staging->count(['tents' => 'no']));
        $this->assertSame(1, $this->staging->count(['place' => 'Silke']));
        $this->assertSame(1, $this->staging->count(['max_sea' => 150, 'cabins' => 'yes']), 'filters combine');
    }

    public function testRefreshKeepsTheDistancesUnlessTheCampsiteMoved(): void
    {
        $this->osm->import('DK');
        $this->pdo->exec('UPDATE campsite_import SET sea_m = 7');

        $this->now += 60;
        $this->osm->import('DK');
        $this->assertSame(4, $this->staging->count(['max_sea' => 7]), 'same positions: stored distances kept');

        $this->assertSame(4, $this->staging->recomputeDistances(true), '--all');
        $this->assertSame(2, $this->staging->count(['max_sea' => 150]));
    }

    public function testACampsiteOrTentSitePoiNearbyMakesAPossibleDuplicate(): void
    {
        $this->osm->import('DK');
        $this->assertSame(0, $this->staging->count(['duplicate' => 'yes']));

        // A hand-drawn tent site 150 m from node/3, a shelter (other type) next to node/4.
        $pois = new PoiRepository($this->pdo);
        $pois->create($this->createUser(), ['poitype_id' => 1, 'name' => 'Mein Zeltplatz', 'latitude' => 56.50135, 'longitude' => 9.5, 'public' => 1, 'direction' => '0000111222211100']);
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (10, 'Shelter')");
        $pois->create($this->createUser(), ['poitype_id' => 10, 'name' => 'Shelter', 'latitude' => 56.6, 'longitude' => 9.6, 'public' => 1]);

        $this->assertSame(['node/3'], array_column($this->staging->search(['duplicate' => 'yes'], null), 'source_ref'));
        $this->assertSame(3, $this->staging->count(['duplicate' => 'no']));

        // Imported: the entry's own POI is no duplicate of it, but of its neighbour 10 m away.
        $beach = $this->rows()['way/1'];
        (new CampsiteImportService($this->staging, $pois))->import([(int) $beach['id']], $this->createUser(), true);
        $duplicates = array_column($this->staging->search(['duplicate' => 'yes'], null), 'source_ref');
        sort($duplicates);
        $this->assertSame(['node/2', 'node/3'], $duplicates);

        $neighbours = $this->staging->neighbours(array_values($this->rows()));
        $node3 = (int) $this->rows()['node/3']['id'];
        $this->assertSame('Mein Zeltplatz', $neighbours[$node3][0]['name']);
        $this->assertSame(1, $neighbours[$node3][0]['poitype_id']);
        $this->assertArrayNotHasKey((int) $beach['id'], $neighbours);
    }

    public function testImportCreatesPoisOfTheRightTypeWithLinkAndAttribution(): void
    {
        $admin = $this->admin();
        $this->osm->import('DK');
        $ids = $this->decode($this->controller->ids($this->request('GET', '/x', $admin, null, ['status' => 'open']), $this->response()))['data'];
        $this->assertCount(4, $ids);

        $result = $this->decode($this->controller->import(
            $this->request('POST', '/x', $admin, ['ids' => $ids, 'public' => true]),
            $this->response()
        ))['data'];

        $this->assertSame(['imported' => 4, 'skipped' => 0], $result);
        $pois = array_column($this->pdo->query('SELECT * FROM poi ORDER BY name')->fetchAll(), null, 'name');
        $this->assertSame('11', (string) $pois['Strand Camping']['poitype_id']);
        $this->assertSame('1', (string) $pois['Lejrplads']['poitype_id']);
        $this->assertSame('1', (string) $pois['Zeltplatz Silkeborg']['poitype_id']);
        $this->assertSame('https://strand-camping.dk', $pois['Strand Camping']['url']);
        $this->assertStringContainsString('Sterne: 4', $pois['Strand Camping']['description']);
        $this->assertStringEndsWith(CampsiteImportService::ATTRIBUTION, $pois['Fjord Camping']['description']);
        $this->assertSame('1', (string) $pois['Fjord Camping']['public']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM windshelter')->fetchColumn(), 'wind shelter unknown: no WSI row');
        $this->assertNull((new PoiRepository($this->pdo))->findById((int) $pois['Lejrplads']['id'])['direction']);

        $again = $this->decode($this->controller->import(
            $this->request('POST', '/x', $admin, ['ids' => $ids]),
            $this->response()
        ))['data'];
        $this->assertSame(['imported' => 0, 'skipped' => 4], $again);
    }

    public function testImportStoresTheDistancesForThePoi(): void
    {
        $distances = new \Ytan\Domain\Poi\PoiWaterDistanceRepository($this->pdo);
        $import = new CampsiteImportService(
            $this->staging,
            new PoiRepository($this->pdo),
            new \Ytan\Service\Water\PoiWaterDistanceService($this->water, $distances, new \Ytan\Domain\Settings\SettingsRepository($this->pdo))
        );
        $this->osm->import('DK');
        $entry = $this->rows()['way/1'];

        $import->import([(int) $entry['id']], $this->createUser(), true);

        $poiId = (int) $this->pdo->query("SELECT poi_id FROM campsite_import WHERE id = {$entry['id']}")->fetchColumn();
        $stored = $distances->find($poiId);
        $this->assertSame((int) $entry['sea_m'], $stored['sea_m']);
        $this->assertSame((int) $entry['river_m'], $stored['river_m']);
    }

    public function testIndexListsEntriesAndIsAdminOnly(): void
    {
        $admin = $this->admin();
        $this->osm->import('DK');

        $answer = $this->decode($this->controller->index($this->request('GET', '/x', $admin, null, ['category' => 'campsite']), $this->response()));
        $this->assertSame(2, $answer['meta']['total']);
        $this->assertSame(['DK'], $answer['meta']['countries']);
        $this->assertSame(0, $answer['meta']['unchecked']);
        $this->assertSame([], $answer['data'][0]['neighbours']);

        $this->expectException(ForbiddenException::class);
        $this->controller->index($this->request('GET', '/x', $this->authPayload($this->createUser())), $this->response());
    }

    public function testTransferFileRoundTripCarriesDistancesAndFlags(): void
    {
        $this->osm->import('DK');
        $this->pdo->exec("UPDATE campsite_import SET sea_m = 42 WHERE source_ref = 'way/1'");
        $file = json_decode(json_encode(CampsiteImportFile::build($this->staging->all())), true);
        $this->pdo->exec('DELETE FROM campsite_import');

        $this->assertSame(['inserted' => 4, 'updated' => 0], $this->staging->upsertMany(CampsiteImportFile::parse($file)));
        $this->assertSame(1, $this->staging->count(['max_sea' => 42]), 'taken from the file, not computed again');
        $beach = $this->rows()['way/1'];
        $this->assertSame([1, 1, 1], [(int) $beach['tents'], (int) $beach['caravans'], (int) $beach['cabins']]);
        $this->assertNull($this->rows()['node/2']['tents']);
        $this->assertSame('Aarhus', $beach['place']);
    }

    public function testTransferFileRejectsBadEntries(): void
    {
        $file = ['format' => CampsiteImportFile::FORMAT, 'version' => 1, 'entries' => [[
            'source' => 'osm', 'source_ref' => 'node/1', 'name' => 'X', 'category' => 'campsite', 'country' => 'DK',
            'place' => '', 'description' => '', 'website' => '', 'tents' => 'yes', 'caravans' => null, 'cabins' => null,
            'latitude' => 55, 'longitude' => 10, 'water_checked' => false,
        ]]];
        $this->expectException(ValidationException::class);
        CampsiteImportFile::parse($file);
    }
}
