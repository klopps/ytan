<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\LighthouseImportController;
use Ytan\Service\LighthouseImportService;

/**
 * The lighthouse list import: staging repository (filters, pagination,
 * re-staging keeps "imported"), the service (create/overwrite/skip around the
 * lighthouse.international_id that may repeat but needs a per-entry decision), the POI repository's handling of that
 * column and the controller's admin gate.
 */
final class LighthouseImportTest extends ControllerTestCase
{
    private LighthouseImportRepository $staging;
    private PoiRepository $pois;
    private LighthouseImportService $service;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        // poitype is a lookup table the test DB starts without.
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (14, 'Lighthouse')");
        $this->staging = new LighthouseImportRepository($this->pdo);
        $this->pois = new PoiRepository($this->pdo);
        $this->service = new LighthouseImportService($this->staging, $this->pois);
        $this->adminId = $this->createUser(['is_admin' => 1]);
    }

    /** @return array<string,mixed> a parser-shaped entry */
    private function entry(string $number, string $intNo, string $name, array $overrides = []): array
    {
        return array_merge([
            'source_key' => $number . '|' . $intNo . '|55.0000|8.0000',
            'dk_no' => $number,
            'international_id' => $intNo,
            'region' => 'A. Nordsøen',
            'subregion' => 'Vadehavet',
            'name' => $name,
            'location' => 'Ved molen',
            'latitude' => 55.0,
            'longitude' => 8.0,
            'characteristic' => 'Oc(2) WRG 6s',
            'other_signals' => '',
            'flame_height' => '7',
            'light_range' => 'W 6,5',
            'appearance' => 'Gult tårn',
            'remarks' => 'G327°-331°',
            'sector_characteristic' => "G 6.5M 327-331",
            'sector_missing_range' => false,
        ], $overrides);
    }

    /** @return list<int> staging ids in insertion order */
    private function stage(array $entries): array
    {
        $this->staging->upsertMany('dk', $entries);

        return $this->staging->ids([]);
    }

    public function testFiltersCombineAndPaginate(): void
    {
        $this->stage([
            $this->entry('1', 'B1000', 'Skagen Fyr', ['region' => 'D. Kattegat', 'location' => 'Grenen']),
            $this->entry('2', 'B1001', 'Skagen Havn', ['region' => 'D. Kattegat']),
            $this->entry('3', 'B2000', 'Rømø Havn', ['region' => 'A. Nordsøen']),
        ]);

        $this->assertSame(3, $this->staging->count([]));
        $this->assertSame(2, $this->staging->count(['name' => 'skagen']));
        $this->assertSame(1, $this->staging->count(['name' => 'skagen', 'location' => 'grenen']));
        $this->assertSame(1, $this->staging->count(['international_id' => 'B 2000']), 'a space in the search must not matter');
        $this->assertSame(2, $this->staging->count(['region' => 'Kattegat']));
        $this->assertSame(1, $this->staging->count(['dk_no' => '3']));
        $this->assertSame(0, $this->staging->count(['name' => 'skagen', 'region' => 'Nordsøen']));

        $page = $this->staging->search([], 2, 2);
        $this->assertCount(1, $page);
        $this->assertSame('Rømø Havn', $page[0]['name']);
        $this->assertSame(['A. Nordsøen', 'D. Kattegat'], $this->staging->regions());
    }

    public function testLikeWildcardsInAFilterAreTakenLiterally(): void
    {
        $this->stage([$this->entry('1', 'B1000', 'Skagen'), $this->entry('2', 'B1001', '100% Fyr')]);

        $this->assertSame(1, $this->staging->count(['name' => '%']));
    }

    public function testRestagingRefreshesTheEntryAndKeepsItImported(): void
    {
        [$id] = $this->stage([$this->entry('1', 'B1000', 'Skagen Fyr')]);
        $this->service->import([$id], $this->adminId, true);

        $result = $this->staging->upsertMany('dk', [$this->entry('1', 'B1000', 'Skagen Fyr II')]);

        $this->assertSame(['inserted' => 0, 'updated' => 1], $result);
        $row = $this->staging->search([], null)[0];
        $this->assertSame('Skagen Fyr II', $row['name']);
        $this->assertNotNull($row['poi_id']);
    }

    public function testImportCreatesALighthousePoiFromTheEntry(): void
    {
        [$id] = $this->stage([$this->entry('44', 'B1780.2', 'Fovrfelt N')]);

        $result = $this->service->import([$id], $this->adminId, true);

        $this->assertSame(['imported' => 1, 'overwritten' => 0, 'skipped' => 0, 'conflicts' => []], $result);
        $row = $this->staging->search([], null)[0];
        $poi = $this->pois->findById((int) $row['poi_id']);
        $this->assertSame(PoiRepository::TYPE_LIGHTHOUSE, (int) $poi['poitype_id']);
        $this->assertSame($this->adminId, (int) $poi['user_id']);
        $this->assertSame(1, (int) $poi['public']);
        $this->assertSame('Fovrfelt N', $poi['name']);
        $this->assertSame('Oc(2) WRG 6s', $poi['characteristic']);
        $this->assertSame('G 6.5M 327-331', $poi['sector_characteristic']);
        $this->assertSame('B1780.2', $poi['international_id']);
        $this->assertSame(
            "Ved molen
Gult tårn
G327°-331°
(Dansk Fyrliste: Dansk nr. 44, Int. nr. B1780.2)",
            $poi['description'],
            'location, appearance and the remarks column, then the source'
        );
    }

    public function testSectorsWithoutARangeGetTheDefaultOfTheirColourAndTheDescriptionSaysSo(): void
    {
        [$id] = $this->stage([$this->entry('44', 'B1780.2', 'Fovrfelt N', [
            'sector_characteristic' => "G ?M 327-331\nW ?M 331-340\nR 5M 340-350\nR ?M 350-10",
            'sector_missing_range' => true,
        ])]);

        $this->service->import([$id], $this->adminId, true);

        $poi = $this->pois->findById((int) $this->staging->search([], null)[0]['poi_id']);
        $this->assertSame("G 2M 327-331\nW 3M 331-340\nR 5M 340-350\nR 2M 350-10", $poi['sector_characteristic']);
        $this->assertStringContainsString(
            'Es sind keine Leuchtweiten bekannt. Es wurden 3, 2 und 2 Seemeilen für Weiß, Rot und Grün angenommen.',
            $poi['description']
        );
    }

    public function testTheDefaultRangesCanBeChosenAtImport(): void
    {
        [$id] = $this->stage([$this->entry('44', 'B1780.2', 'Fovrfelt N', [
            'sector_characteristic' => "G ?M 327-331\nW ?M 331-340\nR ?M 340-350",
            'sector_missing_range' => true,
        ])]);

        $this->service->import([$id], $this->adminId, true, LighthouseImportService::CONFLICT_ASK, ['W' => 4.5, 'R' => 3.0, 'G' => 2.5]);

        $poi = $this->pois->findById((int) $this->staging->search([], null)[0]['poi_id']);
        $this->assertSame("G 2.5M 327-331\nW 4.5M 331-340\nR 3M 340-350", $poi['sector_characteristic']);
        $this->assertStringContainsString('Es wurden 4,5, 3 und 2,5 Seemeilen für Weiß, Rot und Grün angenommen.', $poi['description']);
    }

    public function testNoNoteWhenTheListGivesEveryRange(): void
    {
        [$id] = $this->stage([$this->entry('44', 'B1780.2', 'Fovrfelt N')]);

        $this->service->import([$id], $this->adminId, true);

        $poi = $this->pois->findById((int) $this->staging->search([], null)[0]['poi_id']);
        $this->assertSame('G 6.5M 327-331', $poi['sector_characteristic']);
        $this->assertStringNotContainsString('Leuchtweiten', $poi['description']);
    }

    public function testTheControllerPassesAndValidatesTheDefaultRanges(): void
    {
        $controller = new LighthouseImportController($this->staging, $this->service);
        $admin = $this->authPayload($this->adminId, ['is_admin' => true]);
        [$id] = $this->stage([$this->entry('44', 'B1780.2', 'Fovrfelt N', [
            'sector_characteristic' => "W ?M 1-2",
            'sector_missing_range' => true,
        ])]);

        $controller->import($this->request('POST', '/x', $admin, ['ids' => [$id], 'default_ranges' => ['W' => '6']]), $this->response());

        $poi = $this->pois->findById((int) $this->staging->search([], null)[0]['poi_id']);
        $this->assertSame('W 6M 1-2', $poi['sector_characteristic']);

        $this->expectException(ValidationException::class);
        $controller->import($this->request('POST', '/x', $admin, ['ids' => [$id], 'default_ranges' => ['R' => 0]]), $this->response());
    }

    public function testTheSourceListCanBeFilteredAndShowsInTheDescription(): void
    {
        $this->staging->upsertMany('dk', [$this->entry('1', 'B1', 'Dansk', ['source_key' => 'a'])]);
        $this->staging->upsertMany('nga', [$this->entry('2628', 'C0969', 'Aaro Island', ['source_key' => 'a'])]);

        $this->assertSame(2, $this->staging->count([]));
        $this->assertSame(1, $this->staging->count(['source' => 'nga']));
        $this->assertSame('Aaro Island', $this->staging->search(['source' => 'nga'], null)[0]['name']);

        $ids = $this->staging->ids(['source' => 'nga']);
        $this->service->import($ids, $this->adminId, true);

        $poi = $this->pois->findById((int) $this->staging->search(['source' => 'nga'], null)[0]['poi_id']);
        $this->assertStringContainsString('(NGA List of Lights: Nr. 2628, Int. nr. C0969)', $poi['description']);
    }

    public function testImportStampsTheLighthouseAndAnEditKeepsTheStamp(): void
    {
        [$id] = $this->stage([$this->entry('44', 'B1780.2', 'Fovrfelt N')]);
        $manual = $this->pois->create($this->adminId, ['poitype_id' => 14, 'name' => 'Von Hand', 'latitude' => 54.0, 'longitude' => 9.0]);
        $this->assertNull($manual['imported_at'], 'a hand-drawn lighthouse has no import time');

        $this->service->import([$id], $this->adminId, true);

        $poiId = (int) $this->staging->search([], null)[0]['poi_id'];
        $poi = $this->pois->findById($poiId);
        $this->assertNotNull($poi['imported_at']);
        $this->assertEqualsWithDelta(time(), strtotime((string) $poi['imported_at']), 120);

        // The map's POI form doesn't know the column: an ordinary edit must keep it.
        $edited = $this->pois->update($poiId, [
            'poitype_id' => 14, 'name' => 'Umbenannt', 'latitude' => 54.0, 'longitude' => 9.0, 'characteristic' => 'Fl R 3s',
        ]);
        $this->assertSame($poi['imported_at'], $edited['imported_at']);
    }

    public function testOverwriteStampsTheExistingLighthouse(): void
    {
        $existing = $this->pois->create($this->adminId, [
            'poitype_id' => 14, 'name' => 'Mein Fyr', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B1780.2',
        ]);
        [$id] = $this->stage([$this->entry('44', 'B1780.2', 'Fovrfelt N')]);

        $this->service->import([$id], $this->adminId, true, LighthouseImportService::CONFLICT_OVERWRITE);

        $this->assertNotNull($this->pois->findById((int) $existing['id'])['imported_at']);
    }

    public function testAnEntryIsImportedOnlyOnce(): void
    {
        [$id] = $this->stage([$this->entry('44', 'B1780.2', 'Fovrfelt N')]);
        $this->service->import([$id], $this->adminId, false);

        $second = $this->service->import([$id], $this->adminId, false);

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM poi')->fetchColumn());
    }

    public function testAnExistingInternationalNumberIsReportedBeforeAnythingIsImported(): void
    {
        $existing = $this->pois->create($this->adminId, [
            'poitype_id' => 14, 'name' => 'Mein Fyr', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B1780.2',
        ]);
        [$conflicting, $fresh] = $this->stage([
            $this->entry('44', 'B1780.2', 'Fovrfelt N'),
            $this->entry('45', 'B1780.3', 'Fovrfelt S'),
        ]);

        $result = $this->service->import([$conflicting, $fresh], $this->adminId, true);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['conflicts']);
        $this->assertSame($conflicting, $result['conflicts'][0]['id']);
        $this->assertSame('B1780.2', $result['conflicts'][0]['international_id']);
        $this->assertSame((int) $existing['id'], $result['conflicts'][0]['existing_poi_id']);
        $this->assertSame('Mein Fyr', $result['conflicts'][0]['existing_name']);
        $this->assertSame(1, $result['conflicts'][0]['existing_count']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM poi')->fetchColumn(), 'nothing may have been created');
    }

    public function testOverwriteReplacesTheContentButKeepsOwnerAndVisibility(): void
    {
        $otherUser = $this->createUser();
        $existing = $this->pois->create($otherUser, [
            'poitype_id' => 14, 'name' => 'Mein Fyr', 'latitude' => 54.0, 'longitude' => 9.0,
            'public' => 0, 'characteristic' => 'F R', 'international_id' => 'B1780.2',
        ]);
        [$id] = $this->stage([$this->entry('44', 'B1780.2', 'Fovrfelt N')]);

        $result = $this->service->import([$id], $this->adminId, true, LighthouseImportService::CONFLICT_OVERWRITE);

        $this->assertSame(['imported' => 0, 'overwritten' => 1, 'skipped' => 0, 'conflicts' => []], $result);
        $poi = $this->pois->findById((int) $existing['id']);
        $this->assertSame('Fovrfelt N', $poi['name']);
        $this->assertSame('Oc(2) WRG 6s', $poi['characteristic']);
        $this->assertSame($otherUser, (int) $poi['user_id']);
        $this->assertSame(0, (int) $poi['public']);
        $this->assertSame((int) $existing['id'], (int) $this->staging->search([], null)[0]['poi_id']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM poi')->fetchColumn());
    }

    public function testSkipLeavesTheConflictingOneAndImportsTheRest(): void
    {
        $existing = $this->pois->create($this->adminId, [
            'poitype_id' => 14, 'name' => 'Mein Fyr', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B1780.2',
        ]);
        [$conflicting, $fresh] = $this->stage([
            $this->entry('44', 'B1780.2', 'Fovrfelt N'),
            $this->entry('45', 'B1780.3', 'Fovrfelt S'),
        ]);

        $result = $this->service->import([$conflicting, $fresh], $this->adminId, true, LighthouseImportService::CONFLICT_SKIP);

        $this->assertSame(['imported' => 1, 'overwritten' => 0, 'skipped' => 1, 'conflicts' => []], $result);
        $this->assertSame('Mein Fyr', $this->pois->findById((int) $existing['id'])['name']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM poi')->fetchColumn());
    }

    public function testDecisionsAreMadePerEntry(): void
    {
        $existingA = $this->pois->create($this->adminId, ['poitype_id' => 14, 'name' => 'Alt A', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B1']);
        $existingB = $this->pois->create($this->adminId, ['poitype_id' => 14, 'name' => 'Alt B', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B2']);
        $existingC = $this->pois->create($this->adminId, ['poitype_id' => 14, 'name' => 'Alt C', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B3']);
        [$add, $overwrite, $skip] = $this->stage([
            $this->entry('1', 'B1', 'Neu A', ['source_key' => 'a']),
            $this->entry('2', 'B2', 'Neu B', ['source_key' => 'b']),
            $this->entry('3', 'B3', 'Neu C', ['source_key' => 'c']),
        ]);

        $result = $this->service->import(
            [$add, $overwrite, $skip],
            $this->adminId,
            true,
            LighthouseImportService::CONFLICT_ASK,
            LighthouseImportService::DEFAULT_RANGES,
            [$add => 'add', $overwrite => 'overwrite', $skip => 'skip']
        );

        $this->assertSame(['imported' => 1, 'overwritten' => 1, 'skipped' => 1, 'conflicts' => []], $result);
        $this->assertSame('Alt A', $this->pois->findById((int) $existingA['id'])['name'], 'added: the old one stays');
        $this->assertSame('Neu B', $this->pois->findById((int) $existingB['id'])['name'], 'overwritten');
        $this->assertSame('Alt C', $this->pois->findById((int) $existingC['id'])['name'], 'skipped');
        $this->assertSame(2, $this->pois->countPoisByInternationalId('B1'), 'the lighthouse now exists twice');
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM poi')->fetchColumn());
        $this->assertNull($this->staging->search(['name' => 'Neu C'], null)[0]['poi_id'], 'a skipped entry stays open');
    }

    public function testEntriesWithoutADecisionAreStillAskedAbout(): void
    {
        $this->pois->create($this->adminId, ['poitype_id' => 14, 'name' => 'Alt A', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B1']);
        $this->pois->create($this->adminId, ['poitype_id' => 14, 'name' => 'Alt B', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B2']);
        [$decided, $undecided] = $this->stage([
            $this->entry('1', 'B1', 'Neu A', ['source_key' => 'a']),
            $this->entry('2', 'B2', 'Neu B', ['source_key' => 'b']),
        ]);

        $result = $this->service->import(
            [$decided, $undecided],
            $this->adminId,
            true,
            LighthouseImportService::CONFLICT_ASK,
            LighthouseImportService::DEFAULT_RANGES,
            [$decided => 'add']
        );

        $this->assertSame(0, $result['imported']);
        $this->assertSame([$undecided], array_column($result['conflicts'], 'id'));
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM poi')->fetchColumn(), 'nothing may have been created');
    }

    public function testOverwriteTargetsTheOldestOfSeveralExistingLighthouses(): void
    {
        $oldest = $this->pois->create($this->adminId, ['poitype_id' => 14, 'name' => 'Erstes', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B1']);
        $newer = $this->pois->create($this->adminId, ['poitype_id' => 14, 'name' => 'Zweites', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B1']);
        [$id] = $this->stage([$this->entry('1', 'B1', 'Neu')]);

        $conflicts = $this->service->import([$id], $this->adminId, true)['conflicts'];
        $this->assertSame(2, $conflicts[0]['existing_count']);
        $this->assertSame((int) $oldest['id'], $conflicts[0]['existing_poi_id']);

        $this->service->import([$id], $this->adminId, true, LighthouseImportService::CONFLICT_ASK, LighthouseImportService::DEFAULT_RANGES, [$id => 'overwrite']);

        $this->assertSame('Neu', $this->pois->findById((int) $oldest['id'])['name']);
        $this->assertSame('Zweites', $this->pois->findById((int) $newer['id'])['name']);
    }

    public function testControllerValidatesTheDecisions(): void
    {
        $controller = new LighthouseImportController($this->staging, $this->service);
        $admin = $this->authPayload($this->adminId, ['is_admin' => true]);
        [$id] = $this->stage([$this->entry('1', 'B1', 'Neu')]);

        foreach (['nonsense', ['x'], null] as $bad) {
            try {
                $controller->import($this->request('POST', '/x', $admin, ['ids' => [$id], 'decisions' => [$id => $bad]]), $this->response());
                $this->fail('Expected ValidationException.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTwoSelectedEntriesWithTheSameNumberCountAsAConflict(): void
    {
        [$a, $b] = $this->stage([
            $this->entry('1', 'B1000', 'Erste', ['source_key' => 'a']),
            $this->entry('2', 'B1000', 'Zweite', ['source_key' => 'b']),
        ]);

        $result = $this->service->import([$a, $b], $this->adminId, true);

        $this->assertSame(0, $result['imported']);
        $this->assertSame([$b], array_column($result['conflicts'], 'id'));
        $this->assertNull($result['conflicts'][0]['existing_poi_id']);
    }

    public function testEntriesWithoutAnInternationalNumberNeverConflict(): void
    {
        [$a, $b] = $this->stage([
            $this->entry('1', '', 'Erste', ['source_key' => 'a']),
            $this->entry('2', '', 'Zweite', ['source_key' => 'b']),
        ]);

        $result = $this->service->import([$a, $b], $this->adminId, true);

        $this->assertSame(2, $result['imported']);
        $this->assertSame([], $result['conflicts']);
    }

    public function testPoiRepositoryStoresTheNumberWithoutSpacesAndAllowsADuplicate(): void
    {
        $first = $this->pois->create($this->adminId, [
            'poitype_id' => 14, 'name' => 'A', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => ' B 1780.2 ',
        ]);
        $second = $this->pois->create($this->adminId, [
            'poitype_id' => 14, 'name' => 'B', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B1780.2',
        ]);

        $this->assertSame('B1780.2', $first['international_id']);
        $this->assertSame('B1780.2', $second['international_id']);
        $this->assertSame(2, $this->pois->countPoisByInternationalId('B 1780.2'));
        $this->assertSame((int) $first['id'], $this->pois->findPoiIdByInternationalId('B1780.2'), 'the oldest one');
    }

    public function testAnOrdinaryEditKeepsTheInternationalNumber(): void
    {
        $poi = $this->pois->create($this->adminId, [
            'poitype_id' => 14, 'name' => 'A', 'latitude' => 54.0, 'longitude' => 9.0, 'international_id' => 'B1780.2',
        ]);

        // What the map's POI form sends: no international_id at all.
        $updated = $this->pois->update((int) $poi['id'], [
            'poitype_id' => 14, 'name' => 'A (neu)', 'latitude' => 54.0, 'longitude' => 9.0, 'characteristic' => 'Fl R 3s',
        ]);

        $this->assertSame('B1780.2', $updated['international_id']);
        $this->assertSame('Fl R 3s', $updated['characteristic']);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM lighthouse WHERE poi_id = ?');
        $stmt->execute([$poi['id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'an edit must not add a second lighthouse row for the POI');
    }

    public function testLighthousesWithoutANumberCanCoexist(): void
    {
        $a = $this->pois->create($this->adminId, ['poitype_id' => 14, 'name' => 'A', 'latitude' => 54.0, 'longitude' => 9.0]);
        $b = $this->pois->create($this->adminId, ['poitype_id' => 14, 'name' => 'B', 'latitude' => 54.1, 'longitude' => 9.1, 'international_id' => '']);

        $this->assertNull($a['international_id']);
        $this->assertNull($b['international_id']);
    }

    public function testControllerIsAdminOnly(): void
    {
        $controller = new LighthouseImportController($this->staging, $this->service);
        $normalUser = $this->authPayload($this->createUser());

        foreach (['index', 'ids'] as $method) {
            try {
                $controller->$method($this->request('GET', '/admin/lighthouse-import', $normalUser), $this->response());
                $this->fail("$method must be admin only.");
            } catch (ForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(ForbiddenException::class);
        $controller->import($this->request('POST', '/admin/lighthouse-import', $normalUser, ['ids' => [1]]), $this->response());
    }

    public function testControllerListsImportsAndValidates(): void
    {
        $controller = new LighthouseImportController($this->staging, $this->service);
        $admin = $this->authPayload($this->adminId, ['is_admin' => true]);
        [$a, $b] = $this->stage([
            $this->entry('1', 'B1000', 'Erste', ['source_key' => 'a']),
            $this->entry('2', 'B1001', 'Zweite', ['source_key' => 'b']),
        ]);

        $list = $this->decode($controller->index(
            $this->request('GET', '/admin/lighthouse-import', $admin, null, ['limit' => '1', 'name' => 'e']),
            $this->response()
        ));
        $this->assertCount(1, $list['data']);
        $this->assertSame(2, $list['meta']['total']);

        $ids = $this->decode($controller->ids($this->request('GET', '/admin/lighthouse-import/ids', $admin, null, ['name' => 'Zweite']), $this->response()));
        $this->assertSame([$b], $ids['data']);

        $imported = $this->decode($controller->import(
            $this->request('POST', '/admin/lighthouse-import', $admin, ['ids' => [$a, $b], 'public' => true]),
            $this->response()
        ));
        $this->assertSame(2, $imported['data']['imported']);

        $this->expectException(ValidationException::class);
        $controller->import($this->request('POST', '/admin/lighthouse-import', $admin, ['ids' => [$a], 'on_conflict' => 'nonsense']), $this->response());
    }
}
