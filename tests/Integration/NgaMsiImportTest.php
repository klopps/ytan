<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NgaMsiException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\LighthouseImportController;
use Ytan\Service\LighthouseImportService;
use Ytan\Service\LighthouseList\NgaMsiLightMapper;
use Ytan\Service\NgaMsiImportService;
use Ytan\Tests\Fixtures\FakeNgaMsiClient;

/**
 * Loading a volume of the NGA List of Lights from the Maritime Safety
 * Information web service into the staging table (source "ngaapi"): upsert
 * without duplicates, volume check, request spacing, upstream failure, the
 * import of such an entry with its source, controller and admin gate.
 */
final class NgaMsiImportTest extends ControllerTestCase
{
    private LighthouseImportRepository $staging;
    private FakeNgaMsiClient $client;
    private NgaMsiImportService $service;
    private string $throttleFile;
    private int $now = 2_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (14, 'Lighthouse')");
        $this->seedDataSources();
        $this->staging = new LighthouseImportRepository($this->pdo);
        $this->client = new FakeNgaMsiClient($this->sampleRows());
        $this->throttleFile = sys_get_temp_dir() . '/ytan-nga-test-' . bin2hex(random_bytes(4));
        $this->service = new NgaMsiImportService($this->client, $this->staging, new NgaMsiLightMapper(), $this->throttleFile, fn () => $this->now);
    }

    protected function tearDown(): void
    {
        @unlink($this->throttleFile);
        parent::tearDown();
    }

    /** @return list<array<string,mixed>> */
    private function sampleRows(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../testdata/nga-msi-pub116-sample.json'), true)['ngalol'];
    }

    public function testLoadingAVolumeStagesItsLightsAsSourceNgaapi(): void
    {
        $result = $this->service->import(116);

        $this->assertSame(['fetched' => 11, 'lights' => 8, 'skipped' => 3, 'inserted' => 8, 'updated' => 0], $result);
        $rows = $this->staging->search(['source' => 'ngaapi'], null);
        $this->assertCount(8, $rows);
        $this->assertSame('pub116/4', $rows[0]['source_ref']);
        $this->assertSame([116], $this->client->volumes);
    }

    public function testLoadingTheVolumeAgainRefreshesInsteadOfDuplicating(): void
    {
        $this->service->import(116);
        $this->now += NgaMsiImportService::MIN_SECONDS_BETWEEN_REQUESTS;

        $again = $this->service->import(116);

        $this->assertSame(0, $again['inserted']);
        $this->assertSame(8, $this->staging->count(['source' => 'ngaapi']));
    }

    public function testOnlyTheVolumesOfTheListAreAccepted(): void
    {
        foreach ([109, 117, 0, -116] as $volume) {
            try {
                $this->service->import($volume);
                $this->fail("Expected ValidationException for volume $volume.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->client->volumes);
        $this->assertFalse(is_file($this->throttleFile), 'rejected requests don\'t use up the spacing');
    }

    public function testRequestsMustBeSpacedOut(): void
    {
        $this->service->import(116);

        $this->now += 4;
        try {
            $this->service->import(114);
            $this->fail('Expected the second request to be refused.');
        } catch (ApiException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame('lighthouse_import.nga_too_soon', $e->getErrorCode());
            $this->assertStringContainsString('6 s', $e->getMessage());
        }
        $this->assertSame([116], $this->client->volumes);

        $this->now += 6;
        $this->service->import(114);
        $this->assertSame([116, 114], $this->client->volumes);
    }

    public function testAnUnavailableServiceStagesNothing(): void
    {
        $this->client->willAnswer(null);

        try {
            $this->service->import(116);
            $this->fail('Expected NgaMsiException.');
        } catch (NgaMsiException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame('lighthouse_import.nga_unavailable', $e->getErrorCode());
        }
        $this->assertSame(0, $this->staging->count(['source' => 'ngaapi']));
    }

    public function testAnImportedEntryCarriesItsSource(): void
    {
        $this->service->import(116);
        $pois = new PoiRepository($this->pdo);
        $import = new LighthouseImportService($this->staging, $pois);
        $admin = $this->createUser(['is_admin' => 1]);
        $ids = $this->staging->ids(['international_id' => 'C0277']);

        $import->import($ids, $admin, true);

        $poi = $pois->findById((int) $this->staging->search(['international_id' => 'C0277'], null)[0]['poi_id']);
        $this->assertSame('Kattholmen', $poi['name']);
        $this->assertSame('ngaapi', $poi['data_source']);
        $this->assertSame('pub116/4', $poi['source_ref']);
        $this->assertSame('NGA List of Lights (MSI)', $poi['source_name']);
        $this->assertStringContainsString('(NGA List of Lights (MSI): Nr. 4, Int. nr. C0277)', $poi['description']);
        $this->assertStringContainsString('G 4M 177.9-204.7', $poi['sector_characteristic']);
    }

    public function testControllerLoadsAVolumeAndIsAdminOnly(): void
    {
        $controller = new LighthouseImportController(
            $this->staging,
            new LighthouseImportService($this->staging, new PoiRepository($this->pdo)),
            null,
            $this->service
        );
        $admin = $this->authPayload($this->createUser(['is_admin' => 1]), ['is_admin' => true]);

        $result = $this->decode($controller->loadNga($this->request('POST', '/x', $admin, ['volume' => 116]), $this->response()));
        $this->assertSame(8, $result['data']['inserted']);

        try {
            $controller->loadNga($this->request('POST', '/x', $admin, ['volume' => 'abc']), $this->response());
            $this->fail('Expected ValidationException for a non-numeric volume.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ForbiddenException::class);
        $controller->loadNga($this->request('POST', '/x', $this->authPayload($this->createUser()), ['volume' => 116]), $this->response());
    }
}
