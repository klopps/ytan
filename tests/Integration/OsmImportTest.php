<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\OverpassException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\LighthouseImportController;
use Ytan\Service\LighthouseImportService;
use Ytan\Service\LighthouseList\OsmLightMapper;
use Ytan\Service\OsmImportService;
use Ytan\Tests\Fixtures\FakeOverpassClient;

/**
 * Loading an area from OpenStreetMap into the staging table: the Overpass
 * query, mapping, upsert (no duplicates on a second load), limits, the
 * request spacing, upstream failures - and the import of an "osm" entry
 * with its source.
 */
final class OsmImportTest extends ControllerTestCase
{
    private LighthouseImportRepository $staging;
    private FakeOverpassClient $client;
    private OsmImportService $service;
    private string $throttleFile;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (14, 'Lighthouse')");
        $this->seedDataSources();
        $this->staging = new LighthouseImportRepository($this->pdo);
        $this->client = new FakeOverpassClient($this->fixtureAnswer());
        $this->throttleFile = sys_get_temp_dir() . '/ytan-overpass-test-' . bin2hex(random_bytes(4));
        $this->service = new OsmImportService($this->client, $this->staging, new OsmLightMapper(), $this->throttleFile, fn () => $this->now);
    }

    protected function tearDown(): void
    {
        @unlink($this->throttleFile);
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function fixtureAnswer(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../testdata/overpass-bornholm.json'), true);
    }

    public function testLoadingAnAreaStagesTheLightsAsSourceOsm(): void
    {
        $result = $this->service->import(54.95, 14.6, 55.35, 15.2, 'Bornholm');

        $this->assertSame(['fetched' => 9, 'skipped' => 0, 'inserted' => 9, 'updated' => 0], $result);
        $rows = $this->staging->search(['source' => 'osm'], null);
        $this->assertCount(9, $rows);
        $this->assertSame('Bornholm', $rows[0]['region']);
        $this->assertSame('node/295875845', $rows[0]['source_ref']);
        $this->assertSame(['osm'], array_unique(array_column($rows, 'source')));

        $this->assertCount(1, $this->client->queries);
        $query = $this->client->queries[0];
        $this->assertStringContainsString('[out:json]', $query);
        foreach (['light_major', 'light_minor', 'light_vessel', 'light_float'] as $type) {
            $this->assertStringContainsString('node["seamark:type"="' . $type . '"](54.95000,14.60000,55.35000,15.20000);', $query, 'south,west,north,east, locale independent');
        }
        $this->assertStringContainsString('out tags center;', $query);
    }

    public function testLoadingTheSameAreaAgainRefreshesInsteadOfDuplicating(): void
    {
        $this->service->import(54.95, 14.6, 55.35, 15.2, 'Bornholm');
        $this->now += OsmImportService::MIN_SECONDS_BETWEEN_REQUESTS;

        $again = $this->service->import(54.95, 14.6, 55.35, 15.2, 'Bornholm');

        $this->assertSame(0, $again['inserted']);
        $this->assertSame(9, $this->staging->count(['source' => 'osm']));
    }

    public function testNonLightsInTheAnswerAreCountedAsSkipped(): void
    {
        $answer = $this->fixtureAnswer();
        $answer['elements'][] = ['type' => 'node', 'id' => 99, 'lat' => 55.0, 'lon' => 14.8, 'tags' => [
            'seamark:type' => 'light_minor', 'seamark:light:category' => 'aero', 'seamark:light:character' => 'F',
        ]];
        $this->client->willAnswer($answer);

        $result = $this->service->import(54.95, 14.6, 55.35, 15.2, 'Bornholm');

        $this->assertSame(['fetched' => 10, 'skipped' => 1, 'inserted' => 9, 'updated' => 0], $result);
    }

    public function testRequestsMustBeSpacedOut(): void
    {
        $this->service->import(54.95, 14.6, 55.35, 15.2, 'Bornholm');

        $this->now += 10;
        try {
            $this->service->import(55.0, 14.0, 55.5, 14.5, 'Weiter');
            $this->fail('Expected the second request to be refused.');
        } catch (ApiException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame('lighthouse_import.osm_too_soon', $e->getErrorCode());
            $this->assertStringContainsString('20 s', $e->getMessage());
        }
        $this->assertCount(1, $this->client->queries, 'the refused request never reached Overpass');

        $this->now += 20;
        $this->service->import(55.0, 14.0, 55.5, 14.5, 'Weiter');
        $this->assertCount(2, $this->client->queries);
    }

    public function testAFailingOverpassStillCountsForTheSpacingAndStagesNothing(): void
    {
        $this->client->willAnswer(null);

        try {
            $this->service->import(54.95, 14.6, 55.35, 15.2, 'Bornholm');
            $this->fail('Expected OverpassException.');
        } catch (OverpassException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame('lighthouse_import.osm_unavailable', $e->getErrorCode());
        }
        $this->assertSame(0, $this->staging->count(['source' => 'osm']));

        $this->expectException(ApiException::class);
        $this->service->import(54.95, 14.6, 55.35, 15.2, 'Bornholm');
    }

    public function testInvalidAreasAreRejectedBeforeAnyRequest(): void
    {
        $bad = [
            'south >= north' => [55.0, 14.0, 54.0, 15.0, 'X'],
            'west >= east' => [54.0, 15.0, 55.0, 14.0, 'X'],
            'out of range' => [-91.0, 0.0, 10.0, 10.0, 'X'],
            'too large' => [40.0, 0.0, 55.0, 15.0, 'X'],
            'no label' => [54.0, 14.0, 55.0, 15.0, '  '],
            'long label' => [54.0, 14.0, 55.0, 15.0, str_repeat('x', 101)],
        ];
        foreach ($bad as $label => $args) {
            try {
                $this->service->import(...$args);
                $this->fail("Expected ValidationException for: $label");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->client->queries);
        $this->assertFalse(is_file($this->throttleFile), 'rejected requests don\'t use up the spacing');
    }

    public function testAnImportedOsmEntryCarriesItsSource(): void
    {
        $this->service->import(54.95, 14.6, 55.35, 15.2, 'Bornholm');
        $pois = new PoiRepository($this->pdo);
        $import = new LighthouseImportService($this->staging, $pois);
        $admin = $this->createUser(['is_admin' => 1]);
        $ids = $this->staging->ids(['international_id' => 'C2556']);

        $result = $import->import($ids, $admin, true);

        $this->assertSame(1, $result['imported']);
        $poi = $pois->findById((int) $this->staging->search(['international_id' => 'C2556'], null)[0]['poi_id']);
        $this->assertSame('Christiansø', $poi['name']);
        $this->assertSame('osm', $poi['data_source']);
        $this->assertSame('node/295875845', $poi['source_ref']);
        $this->assertSame('OpenStreetMap / OpenSeaMap', $poi['source_name']);
        $this->assertSame('Fl W 5s', $poi['characteristic']);
        $this->assertStringContainsString('(OpenStreetMap / OpenSeaMap: Int. nr. C2556)', $poi['description']);
    }

    public function testAnOsmEntryWithoutANameGetsAReadableOne(): void
    {
        $this->service->import(54.95, 14.6, 55.35, 15.2, 'Bornholm');
        $pois = new PoiRepository($this->pdo);
        $import = new LighthouseImportService($this->staging, $pois);
        $admin = $this->createUser(['is_admin' => 1]);
        $ids = $this->staging->ids(['international_id' => 'C2541']); // the fog signal light has no name

        $import->import($ids, $admin, true);

        $poi = $pois->findById((int) $this->staging->search(['international_id' => 'C2541'], null)[0]['poi_id']);
        $this->assertSame('OpenStreetMap / OpenSeaMap C2541', $poi['name']);
    }

    public function testControllerLoadsAnAreaAndIsAdminOnly(): void
    {
        $controller = new LighthouseImportController(
            $this->staging,
            new LighthouseImportService($this->staging, new PoiRepository($this->pdo)),
            $this->service
        );
        $admin = $this->authPayload($this->createUser(['is_admin' => 1]), ['is_admin' => true]);
        $body = ['south' => 54.95, 'west' => 14.6, 'north' => 55.35, 'east' => 15.2, 'label' => 'Bornholm'];

        $result = $this->decode($controller->loadOsm($this->request('POST', '/x', $admin, $body), $this->response()));
        $this->assertSame(9, $result['data']['inserted']);

        try {
            $controller->loadOsm($this->request('POST', '/x', $admin, ['south' => 'x'] + $body), $this->response());
            $this->fail('Expected ValidationException for a non-numeric bound.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ForbiddenException::class);
        $controller->loadOsm($this->request('POST', '/x', $this->authPayload($this->createUser()), $body), $this->response());
    }
}
