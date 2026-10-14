<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\LighthouseImportController;
use Ytan\Service\LighthouseImportFile;
use Ytan\Service\LighthouseImportService;

/**
 * The transfer file of the staged lighthouse entries: export on one system,
 * read on another.
 */
final class LighthouseImportFileTest extends ControllerTestCase
{
    private LighthouseImportRepository $staging;
    private LighthouseImportController $controller;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (14, 'Lighthouse')");
        $this->staging = new LighthouseImportRepository($this->pdo);
        $this->controller = new LighthouseImportController(
            $this->staging,
            new LighthouseImportService($this->staging, new PoiRepository($this->pdo))
        );
        $this->admin = $this->authPayload($this->createUser(['is_admin' => 1]), ['is_admin' => true]);
    }

    /** @return array<string,mixed> */
    private function entry(string $key, array $overrides = []): array
    {
        return array_merge([
            'source_key' => $key,
            'dk_no' => '44',
            'international_id' => 'B1780.2',
            'region' => 'A. Nordsøen',
            'subregion' => 'Vadehavet',
            'name' => 'Fovrfelt N',
            'location' => 'Ved molen',
            'latitude' => 55.4881667,
            'longitude' => 8.3965,
            'characteristic' => 'Oc(2) WRG 6s',
            'other_signals' => '',
            'flame_height' => '7',
            'light_range' => 'W 6,5',
            'appearance' => 'Gult tårn',
            'remarks' => 'G327°-331°',
            'sector_characteristic' => 'G 6.5M 327-331',
            'sector_missing_range' => false,
        ], $overrides);
    }

    public function testExportedFileCarriesEveryEntryButNoDatabaseSpecificIds(): void
    {
        $this->staging->upsertMany('dk', [$this->entry('a'), $this->entry('b', ['name' => 'Zweite'])]);

        $answer = $this->decode($this->controller->exportFile($this->request('GET', '/x', $this->admin), $this->response()));
        $file = $answer['data'];

        $this->assertSame(LighthouseImportFile::FORMAT, $file['format']);
        $this->assertSame(LighthouseImportFile::VERSION, $file['version']);
        $this->assertCount(2, $file['entries']);
        $this->assertSame('dk', $file['entries'][0]['source']);
        $this->assertSame('Fovrfelt N', $file['entries'][0]['name']);
        $this->assertSame(55.4881667, $file['entries'][0]['latitude']);
        $this->assertFalse($file['entries'][0]['sector_missing_range']);
        foreach (['id', 'poi_id', 'imported_at'] as $databaseSpecific) {
            $this->assertArrayNotHasKey($databaseSpecific, $file['entries'][0]);
        }
    }

    public function testReadingTheFileOnAnotherSystemReproducesTheEntries(): void
    {
        $this->staging->upsertMany('dk', [$this->entry('a'), $this->entry('b', ['name' => 'Zweite', 'remarks' => null, 'sector_characteristic' => null])]);
        $file = LighthouseImportFile::build($this->staging->all());

        // "Other system": empty table.
        $this->pdo->exec('DELETE FROM lighthouse_import');
        $result = $this->decode($this->controller->importFile($this->request('POST', '/x', $this->admin, $file), $this->response()));

        $this->assertSame(['inserted' => 2, 'updated' => 0], $result['data']);
        $rows = $this->staging->search([], null);
        $this->assertSame(['Fovrfelt N', 'Zweite'], array_column($rows, 'name'));
        $this->assertSame('G 6.5M 327-331', $rows[0]['sector_characteristic']);
        $this->assertNull($rows[1]['sector_characteristic']);
        $this->assertNull($rows[0]['poi_id']);
    }

    public function testReadingTheFileAgainRefreshesAndKeepsImportedEntriesImported(): void
    {
        $this->staging->upsertMany('dk', [$this->entry('a')]);
        $row = $this->staging->search([], null)[0];
        $poiId = (int) (new PoiRepository($this->pdo))->create((int) $this->admin['sub'], [
            'poitype_id' => 14, 'name' => 'X', 'latitude' => 55.0, 'longitude' => 8.0,
        ])['id'];
        $this->staging->markImported((int) $row['id'], $poiId);

        $file = LighthouseImportFile::build([array_merge($row, ['name' => 'Fovrfelt N (neu)'])]);
        $result = $this->decode($this->controller->importFile($this->request('POST', '/x', $this->admin, $file), $this->response()));

        $this->assertSame(['inserted' => 0, 'updated' => 1], $result['data']);
        $after = $this->staging->search([], null)[0];
        $this->assertSame('Fovrfelt N (neu)', $after['name']);
        $this->assertSame($poiId, (int) $after['poi_id']);
    }

    public function testInvalidFilesAreRejected(): void
    {
        $valid = LighthouseImportFile::build([]);
        $broken = [
            'wrong format' => array_merge($valid, ['format' => 'something-else']),
            'wrong version' => array_merge($valid, ['version' => 99]),
            'no entries' => ['format' => LighthouseImportFile::FORMAT, 'version' => 1],
            'bad source' => array_merge($valid, ['entries' => [array_merge($this->entry('a'), ['source' => 'DROP TABLE'])]]),
            'no key' => array_merge($valid, ['entries' => [array_merge($this->entry(''), ['source' => 'dk'])]]),
            'bad latitude' => array_merge($valid, ['entries' => [array_merge($this->entry('a'), ['source' => 'dk', 'latitude' => 123])]]),
            'text is a list' => array_merge($valid, ['entries' => [array_merge($this->entry('a'), ['source' => 'dk', 'name' => ['x']])]]),
        ];

        foreach ($broken as $label => $file) {
            try {
                LighthouseImportFile::parse($file);
                $this->fail("Expected ValidationException for: $label");
            } catch (ValidationException $e) {
                $this->assertSame('lighthouse_import.invalid_file', $e->getErrorCode(), $label);
            }
        }
    }

    public function testOverlongTextIsCutToTheColumnSize(): void
    {
        $file = array_merge(LighthouseImportFile::build([]), [
            'entries' => [array_merge($this->entry('a'), ['source' => 'dk', 'name' => str_repeat('x', 500)])],
        ]);

        $entries = LighthouseImportFile::parse($file)['dk'];

        $this->assertSame(200, mb_strlen($entries[0]['name']));
    }

    public function testBothEndpointsAreAdminOnly(): void
    {
        $normalUser = $this->authPayload($this->createUser());

        foreach (['exportFile', 'importFile'] as $method) {
            try {
                $this->controller->$method($this->request('POST', '/x', $normalUser, LighthouseImportFile::build([])), $this->response());
                $this->fail("$method must be admin only.");
            } catch (ForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
