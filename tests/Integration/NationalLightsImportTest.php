<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NationalLightsException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\LighthouseImportController;
use Ytan\Service\LighthouseImportService;
use Ytan\Service\LighthouseList\FinnishLightMapper;
use Ytan\Service\LighthouseList\NorwegianLightMapper;
use Ytan\Service\NationalLightsImportService;
use Ytan\Tests\Fixtures\FakeNationalLightsClient;

/**
 * Loading the lights of Finland (Väylävirasto) and Norway (Kystverket) into the staging table (sources "fi" and
 * "no"): upsert without duplicates, country check, request spacing, upstream failure, the import of such an entry
 * with its source, controller and admin gate.
 */
final class NationalLightsImportTest extends ControllerTestCase
{
    private LighthouseImportRepository $staging;
    private FakeNationalLightsClient $client;
    private NationalLightsImportService $service;
    private string $throttleFile;
    private int $now = 2_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (14, 'Lighthouse')");
        $this->seedDataSources();
        $this->staging = new LighthouseImportRepository($this->pdo);
        $this->client = new FakeNationalLightsClient(
            [
                'aids' => [
                    ['turvalaitenumero' => '110', 'nimifi' => 'Ljusaklack', 'nimisv' => null, 'sijaintifi' => 'Kallioluodolla.', 'turvalaitetyyppifi' => 'Sektoriloisto', 'toimintatilakoodi' => 1],
                    ['turvalaitenumero' => '111', 'nimifi' => 'Pois', 'nimisv' => null, 'sijaintifi' => '', 'turvalaitetyyppifi' => 'Apuloisto', 'toimintatilakoodi' => 2],
                ],
                'lights' => [
                    ['id' => 1, 'laatu' => 'Pääloisto', 'laji' => 'Yövalo', 'korkeusvedesta' => 6.5, 'optinenkanto' => 7.2, 'maantietkanto' => null,
                        'viralvalotunnuse' => 'Fl (2) 6 s', 'tarkkavalotunnus' => null, 'valoominaisuusfi' => null, 'turvalaitenumero' => '110', 'lat' => 60.32, 'lon' => 26.5],
                    ['id' => 2, 'laatu' => 'Pääloisto', 'laji' => 'Yövalo', 'korkeusvedesta' => 5.0, 'optinenkanto' => 3.0, 'maantietkanto' => null,
                        'viralvalotunnuse' => 'Q', 'tarkkavalotunnus' => null, 'valoominaisuusfi' => null, 'turvalaitenumero' => '111', 'lat' => 60.0, 'lon' => 26.0],
                ],
                'sectors' => [['id' => 1, 'loisto' => 1, 'alkukulma' => 74.0, 'loppukulma' => 80.0, 'vari' => 'v']],
            ],
            [
                'lights' => [
                    ['objectid' => '1', 'sjomerke_nr' => '560000', 'navn' => 'Sklinna', 'sted' => 'Sklinna', 'beliggenhet' => '', 'klasse' => 'FS - Fyrstasjon', 'nfs_status' => 'Operativ',
                        'karakter' => 'Oc WRG 6s', 'beskrivelse' => '', 'lys_hoyde' => '45.0', 'rekkevidde_hvit' => '18.6', 'globalid' => '{011A7450-2BF9-4BE3-802B-BAC1C7EF787E}',
                        'lat' => 65.2, 'lon' => 10.99],
                    ['objectid' => '2', 'sjomerke_nr' => '1', 'navn' => 'Lanterne', 'sted' => '', 'beliggenhet' => '', 'klasse' => 'LA - Lanterne', 'nfs_status' => 'Operativ',
                        'karakter' => 'F R', 'beskrivelse' => '', 'lys_hoyde' => '5', 'globalid' => '{011A7450-2BF9-4BE3-802B-BAC1C7EF7880}', 'lat' => 60.0, 'lon' => 5.0],
                ],
                'sectors' => [['lys_farge' => 'Hvit', 'retning_sektorlinje_1' => '47.6', 'retning_sektorlinje_2' => '53.7', 'sektor_nr' => '1', 'fknavinst' => '{011A7450-2BF9-4BE3-802B-BAC1C7EF787E}']],
            ]
        );
        $this->throttleFile = sys_get_temp_dir() . '/ytan-national-test-' . bin2hex(random_bytes(4));
        $this->service = new NationalLightsImportService(
            $this->client,
            $this->staging,
            new FinnishLightMapper(),
            new NorwegianLightMapper(),
            $this->throttleFile,
            fn () => $this->now
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->throttleFile);
        parent::tearDown();
    }

    public function testLoadingFinlandStagesItsLightsAsSourceFi(): void
    {
        $result = $this->service->import('fi');

        $this->assertSame(['fetched' => 2, 'lights' => 1, 'skipped' => 1, 'inserted' => 1, 'updated' => 0], $result);
        $rows = $this->staging->search(['source' => 'fi'], null);
        $this->assertCount(1, $rows);
        $this->assertSame('Ljusaklack', $rows[0]['name']);
        $this->assertSame('W 7.2M 74-80', $rows[0]['sector_characteristic']);
        $this->assertSame(['fi'], array_unique(array_column($this->staging->search([], null), 'source')));
    }

    public function testLoadingNorwayStagesItsLightsAsSourceNo(): void
    {
        $result = $this->service->import('no');

        $this->assertSame(['fetched' => 2, 'lights' => 1, 'skipped' => 1, 'inserted' => 1, 'updated' => 0], $result);
        $rows = $this->staging->search(['source' => 'no'], null);
        $this->assertSame('Sklinna', $rows[0]['name']);
        $this->assertSame('W 18.6M 47.6-53.7', $rows[0]['sector_characteristic']);
    }

    public function testLoadingAgainRefreshesInsteadOfDuplicating(): void
    {
        $this->service->import('no');
        $this->now += 31;
        $again = $this->service->import('no');

        $this->assertSame(0, $again['inserted']);
        $this->assertSame(1, $this->staging->count(['source' => 'no']));
    }

    public function testAnUnknownCountryIsRefusedBeforeAnythingIsAsked(): void
    {
        try {
            $this->service->import('se');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException) {
            $this->assertSame([], $this->client->calls);
            $this->assertFalse(is_file($this->throttleFile));
        }
    }

    public function testTheServicesAreAskedAtMostOnceEveryThirtySeconds(): void
    {
        $this->service->import('fi');
        $this->now += 10;

        try {
            $this->service->import('no');
            $this->fail('Expected the second request to be refused.');
        } catch (ApiException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame(['fi'], $this->client->calls, 'the refused request asked nobody');
        }

        $this->now += 21;
        $this->service->import('no');
        $this->assertSame(['fi', 'no'], $this->client->calls);
    }

    public function testAnUpstreamFailureIsA502AndStagesNothing(): void
    {
        $this->client->fail = true;

        try {
            $this->service->import('fi');
            $this->fail('Expected a NationalLightsException.');
        } catch (NationalLightsException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame(0, $this->staging->count([]));
        }
    }

    public function testAnImportedNorwegianLightCarriesItsSourceAndTheDescriptionNamesIt(): void
    {
        $this->service->import('no');
        $admin = $this->createUser(['is_admin' => 1]);
        $import = new LighthouseImportService($this->staging, new PoiRepository($this->pdo));
        $import->import($this->staging->ids([]), $admin, true);

        $poi = (new PoiRepository($this->pdo))->findAll()[0];
        $this->assertSame('Kystverket (Norway)', $poi['source_name']);
        $this->assertSame('no', $poi['data_source']);
        $this->assertStringContainsString('(Kystverket (Norway): Nr. 560000)', $poi['description']);
        $this->assertSame('W 18.6M 47.6-53.7', $poi['sector_characteristic']);
    }

    public function testControllerLoadsACountryAndIsAdminOnly(): void
    {
        $controller = new LighthouseImportController(
            $this->staging,
            new LighthouseImportService($this->staging, new PoiRepository($this->pdo)),
            null,
            new \Ytan\Service\Log\ImportLog(),
            $this->service
        );
        $admin = $this->authPayload($this->createUser(['is_admin' => 1]), ['is_admin' => true]);

        $result = $this->decode($controller->loadNational($this->request('POST', '/x', $admin, ['country' => 'fi']), $this->response()));
        $this->assertSame(1, $result['data']['inserted']);

        foreach ([['country' => 12], []] as $body) {
            try {
                $controller->loadNational($this->request('POST', '/x', $admin, $body), $this->response());
                $this->fail('Expected a ValidationException for a missing or non-text country.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ForbiddenException::class);
        $controller->loadNational($this->request('POST', '/x', $this->authPayload($this->createUser()), ['country' => 'fi']), $this->response());
    }
}
