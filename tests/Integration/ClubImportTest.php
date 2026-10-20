<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\ClubImport\ClubImportRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\ClubImportController;
use Ytan\Service\ClubImportFile;
use Ytan\Service\ClubImportService;
use Ytan\Service\ClubList\ClubOsmMapper;
use Ytan\Service\ClubOsmImportService;
use Ytan\Tests\Fixtures\FakeOverpassClient;

/**
 * Club import: OSM mapping (website!), staging upsert, the Overpass query,
 * request spacing, the import into POIs of type 13 (website as POI link,
 * duplicates linked instead of created) and the transfer file.
 */
final class ClubImportTest extends ControllerTestCase
{
    private ClubImportRepository $staging;
    private FakeOverpassClient $client;
    private ClubOsmImportService $osm;
    private ClubImportController $controller;
    private string $throttleFile;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (13, 'Clubs')");
        $this->staging = new ClubImportRepository($this->pdo);
        $this->client = new FakeOverpassClient($this->answer());
        $this->throttleFile = sys_get_temp_dir() . '/ytan-club-test-' . bin2hex(random_bytes(4));
        $this->osm = new ClubOsmImportService($this->client, $this->staging, new ClubOsmMapper(), $this->throttleFile, fn () => $this->now);
        $this->controller = new ClubImportController(
            $this->staging,
            new ClubImportService($this->staging, new PoiRepository($this->pdo), $this->pdo),
            $this->osm
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->throttleFile);
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function answer(): array
    {
        return ['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => 54.32, 'lon' => 10.14, 'tags' => [
                'name' => 'Kieler Kanu-Club', 'sport' => 'canoe;kayak', 'club' => 'sport', 'website' => 'kanu-kiel.example.org',
                'addr:street' => 'Hafenstraße', 'addr:housenumber' => '5', 'addr:postcode' => '24103', 'addr:city' => 'Kiel',
            ]],
            ['type' => 'way', 'id' => 2, 'center' => ['lat' => 53.55, 'lon' => 9.99], 'tags' => [
                'name' => 'Hamburger Ruderclub', 'sport' => 'rowing', 'leisure' => 'sports_club', 'contact:website' => 'https://ruder.example.org/',
            ]],
            ['type' => 'node', 'id' => 3, 'lat' => 54.0, 'lon' => 10.0, 'tags' => ['sport' => 'rowing', 'club' => 'sport']],
            ['type' => 'node', 'id' => 4, 'lat' => 54.1, 'lon' => 10.1, 'tags' => [
                'name' => 'Ruder- und Kanuverein', 'sport' => 'rowing;canoe', 'club' => 'sport', 'website' => 'mailto:info@example.org',
            ]],
        ]];
    }

    private function admin(): array
    {
        return $this->authPayload($this->createUser(['is_admin' => 1]), ['is_admin' => true]);
    }

    public function testMapperReadsKindWebsiteAndAddress(): void
    {
        $rows = [];
        foreach ($this->answer()['elements'] as $element) {
            $entry = (new ClubOsmMapper())->map($element, 'de');
            if ($entry !== null) {
                $rows[$entry['osm_ref']] = $entry;
            }
        }

        $this->assertCount(3, $rows, 'the element without a name is dropped');
        $this->assertSame('canoe', $rows['node/1']['kind']);
        $this->assertSame('https://kanu-kiel.example.org', $rows['node/1']['website'], 'a bare domain gets https://');
        $this->assertSame('Hafenstraße 5, 24103 Kiel', $rows['node/1']['address']);
        $this->assertSame('Kiel', $rows['node/1']['city']);
        $this->assertSame('DE', $rows['node/1']['country']);
        $this->assertSame('rowing', $rows['way/2']['kind']);
        $this->assertSame(53.55, $rows['way/2']['latitude'], 'a way uses its center');
        $this->assertSame('https://ruder.example.org/', $rows['way/2']['website'], 'contact:website is used');
        $this->assertSame('both', $rows['node/4']['kind']);
        $this->assertSame('', $rows['node/4']['website'], 'mailto: is not a website');
    }

    public function testWebsiteRejectsUnsafeSchemes(): void
    {
        $this->assertSame('', ClubOsmMapper::website(['website' => 'javascript:alert(1)']));
        $this->assertSame('', ClubOsmMapper::website(['website' => 'ftp://example.org']));
        $this->assertSame('https://b.example.org', ClubOsmMapper::website(['website' => 'localhost', 'url' => 'b.example.org']));
    }

    public function testLoadingACountryStagesClubsAndAsksOverpassForThatCountry(): void
    {
        $result = $this->osm->import('de');

        $this->assertSame(['fetched' => 4, 'skipped' => 1, 'inserted' => 3, 'updated' => 0], $result);
        $this->assertSame(['DE'], $this->staging->countries());
        $query = $this->client->queries[0];
        $this->assertStringContainsString('area["ISO3166-1"="DE"]', $query);
        $this->assertStringContainsString('out tags center;', $query);

        $this->now += \Ytan\Service\OsmImportService::MIN_SECONDS_BETWEEN_REQUESTS;
        $this->assertSame(0, $this->osm->import('DE')['inserted'], 'a second load refreshes instead of duplicating');
        $this->assertSame(3, $this->staging->count([]));
    }

    public function testRejectsABadCountryCode(): void
    {
        $this->expectException(ValidationException::class);
        $this->osm->import('Germany');
    }

    public function testRequestsMustBeSpacedOut(): void
    {
        $this->osm->import('DE');
        $this->now += 10;
        try {
            $this->osm->import('DK');
            $this->fail('Expected the second request to be refused.');
        } catch (ApiException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
        $this->assertCount(1, $this->client->queries);
    }

    public function testImportCreatesPoisWithWebsiteAndMarksEntriesImported(): void
    {
        $this->osm->import('DE');
        $admin = $this->admin();
        $ids = $this->staging->ids(['name' => 'Kieler']);

        $result = $this->decode($this->controller->import(
            $this->request('POST', '/x', $admin, ['ids' => $ids, 'public' => true]),
            $this->response()
        ))['data'];

        $this->assertSame(['imported' => 1, 'linked' => 0, 'skipped' => 0], $result);
        $poi = $this->pdo->query("SELECT * FROM poi WHERE name = 'Kieler Kanu-Club'")->fetch();
        $this->assertSame('13', (string) $poi['poitype_id']);
        $this->assertSame('https://kanu-kiel.example.org', $poi['url']);
        $this->assertSame('1', (string) $poi['public']);
        $this->assertStringContainsString('Hafenstraße 5, 24103 Kiel', $poi['description']);
        $this->assertStringContainsString('OpenStreetMap', $poi['description']);

        // Importing the same entry again does nothing.
        $again = $this->decode($this->controller->import(
            $this->request('POST', '/x', $admin, ['ids' => $ids]),
            $this->response()
        ))['data'];
        $this->assertSame(['imported' => 0, 'linked' => 0, 'skipped' => 1], $again);
        $this->assertSame(1, $this->staging->count(['status' => 'imported']));
    }

    public function testAnExistingClubPoiIsLinkedInsteadOfDuplicated(): void
    {
        $owner = $this->createUser();
        (new PoiRepository($this->pdo))->create($owner, [
            'poitype_id' => 13, 'name' => 'Kieler Kanu-Club', 'latitude' => 54.3202, 'longitude' => 10.1402, 'public' => 1,
        ]);
        $this->osm->import('DE');

        $result = $this->decode($this->controller->import(
            $this->request('POST', '/x', $this->admin(), ['ids' => $this->staging->ids(['name' => 'Kieler'])]),
            $this->response()
        ))['data'];

        $this->assertSame(['imported' => 0, 'linked' => 1, 'skipped' => 0], $result);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM poi WHERE name = 'Kieler Kanu-Club'")->fetchColumn());
    }

    public function testFiltersAndAdminOnly(): void
    {
        $this->osm->import('DE');
        $this->assertSame(1, $this->staging->count(['kind' => 'rowing']));
        $this->assertSame(1, $this->staging->count(['website' => 'ruder.example']));
        $this->assertSame(3, $this->staging->count(['status' => 'open']));
        $this->assertSame(0, $this->staging->count(['country' => 'DK']));

        $this->expectException(ForbiddenException::class);
        $this->controller->index($this->request('GET', '/x', $this->authPayload($this->createUser())), $this->response());
    }

    public function testTransferFileRoundTripKeepsWebsites(): void
    {
        $this->osm->import('DE');
        $file = ClubImportFile::build($this->staging->all());
        $this->pdo->exec('DELETE FROM club_import');

        $result = $this->staging->upsertMany(ClubImportFile::parse(json_decode(json_encode($file), true)));

        $this->assertSame(['inserted' => 3, 'updated' => 0], $result);
        $this->assertSame(1, $this->staging->count(['website' => 'https://kanu-kiel.example.org']));
    }

    public function testTransferFileRejectsABadWebsiteScheme(): void
    {
        $file = ClubImportFile::build([]);
        $file['entries'][] = ['osm_ref' => 'node/9', 'name' => 'X', 'kind' => 'canoe', 'country' => 'DE', 'city' => '', 'address' => '',
            'website' => 'javascript:alert(1)', 'latitude' => 1.0, 'longitude' => 1.0];

        $this->expectException(ValidationException::class);
        ClubImportFile::parse($file);
    }
}
