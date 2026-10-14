<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Domain\Poi\LighthouseDuplicateRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\LighthouseDuplicateController;
use Ytan\Service\ImageStorageService;
use Ytan\Service\LighthouseDuplicateService;

/**
 * The lighthouse duplicate check: groups of close lighthouses (latitude-sweep
 * + haversine, chains join up) and merging the chosen ones - field sources,
 * kept record, photos, list-import bookkeeping, validation, admin gate.
 */
final class LighthouseDuplicateTest extends ControllerTestCase
{
    private PoiRepository $pois;
    private LighthouseDuplicateService $service;
    private LighthouseImportRepository $staging;
    private string $imageDir;
    private ImageStorageService $images;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (14, 'Lighthouse'), (2, 'Other')");
        $this->pois = new PoiRepository($this->pdo);
        $this->staging = new LighthouseImportRepository($this->pdo);
        $this->imageDir = sys_get_temp_dir() . '/ytan-dup-test-' . bin2hex(random_bytes(4));
        $this->images = new ImageStorageService($this->imageDir);
        $this->service = new LighthouseDuplicateService($this->pois, new LighthouseDuplicateRepository($this->pdo), $this->images, $this->pdo);
        $this->userId = $this->createUser();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->imageDir)) {
            foreach (glob($this->imageDir . '/*/*') ?: [] as $file) {
                unlink($file);
            }
            foreach (glob($this->imageDir . '/*') ?: [] as $dir) {
                rmdir($dir);
            }
            rmdir($this->imageDir);
        }
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function lighthouse(string $name, float $lat, float $lon, array $extra = []): array
    {
        return $this->pois->create($this->userId, array_merge([
            'poitype_id' => 14, 'name' => $name, 'latitude' => $lat, 'longitude' => $lon, 'public' => 0,
            'characteristic' => null, 'sector_characteristic' => null,
        ], $extra));
    }

    /** ~11 m per 0.0001 degrees of latitude. */
    private const STEP = 0.0001;

    public function testNearbyLighthousesFormAGroupAndFarOnesDoNot(): void
    {
        $a = $this->lighthouse('A', 55.0, 10.0);
        $b = $this->lighthouse('B', 55.0 + 3 * self::STEP, 10.0);   // ~33 m
        $this->lighthouse('Far', 55.5, 10.0);
        $this->pois->create($this->userId, ['poitype_id' => 2, 'name' => 'Not a lighthouse', 'latitude' => 55.0, 'longitude' => 10.0]);

        $groups = $this->service->findGroups(50.0);

        $this->assertCount(1, $groups);
        $this->assertSame([(int) $a['id'], (int) $b['id']], array_map(fn ($p) => (int) $p['id'], $groups[0]['pois']));
        $this->assertSame((int) $a['id'] . '-' . (int) $b['id'], $groups[0]['key']);
        $this->assertEqualsWithDelta(33.4, $groups[0]['max_distance'], 1.0);
        $this->assertSame(0.0, $groups[0]['pois'][0]['distance_m']);
        $this->assertEqualsWithDelta(33.4, $groups[0]['pois'][1]['distance_m'], 1.0);
        $this->assertCount(0, $this->service->findGroups(10.0), 'a smaller distance finds nothing');
    }

    public function testAChainOfCloseLighthousesIsOneGroup(): void
    {
        $this->lighthouse('A', 55.0, 10.0);
        $this->lighthouse('B', 55.0 + 4 * self::STEP, 10.0);   // ~44 m from A
        $this->lighthouse('C', 55.0 + 8 * self::STEP, 10.0);   // ~44 m from B, ~89 m from A

        $groups = $this->service->findGroups(50.0);

        $this->assertCount(1, $groups);
        $this->assertCount(3, $groups[0]['pois']);
        $this->assertEqualsWithDelta(89.0, $groups[0]['max_distance'], 2.0);
    }

    public function testLongitudeDistancesAreMeasuredOnTheGlobe(): void
    {
        // At 60 N a degree of longitude is half as long as at the equator: 0.0010 deg = ~56 m.
        $this->lighthouse('A', 60.0, 10.0);
        $this->lighthouse('B', 60.0, 10.0010);

        $this->assertCount(1, $this->service->findGroups(60.0));
        $this->assertCount(0, $this->service->findGroups(50.0));
    }

    public function testHandDrawnLighthousesComeFirstInAGroup(): void
    {
        $imported = $this->lighthouse('Imported', 55.0, 10.0);
        $manual = $this->lighthouse('Manual', 55.0 + self::STEP, 10.0);
        $this->staging->upsertMany('dk', [$this->stagedEntry('k1')]);
        $this->staging->markImported((int) $this->staging->search([], null)[0]['id'], (int) $imported['id']);

        $pois = $this->service->findGroups(50.0)[0]['pois'];

        $this->assertSame([(int) $manual['id'], (int) $imported['id']], array_map(fn ($p) => (int) $p['id'], $pois));
        $this->assertTrue($pois[0]['manual']);
        $this->assertFalse($pois[1]['manual']);
    }

    public function testGroupsAreSortedByLargestDistanceAscending(): void
    {
        // Created in this order: wide (~44 m), narrow (~11 m), medium (~33 m).
        $this->lighthouse('Wide 1', 55.0, 10.0);
        $this->lighthouse('Wide 2', 55.0 + 4 * self::STEP, 10.0);
        $this->lighthouse('Narrow 1', 56.0, 10.0);
        $this->lighthouse('Narrow 2', 56.0 + self::STEP, 10.0);
        $this->lighthouse('Medium 1', 57.0, 10.0);
        $this->lighthouse('Medium 2', 57.0 + 3 * self::STEP, 10.0);

        $groups = $this->service->findGroups(50.0);

        $this->assertSame(['Narrow 1', 'Medium 1', 'Wide 1'], array_map(fn ($g) => $g['pois'][0]['name'], $groups));
        $distances = array_column($groups, 'max_distance');
        $sorted = $distances;
        sort($sorted);
        $this->assertSame($sorted, $distances);
    }

    /** @return list<string> the names of all lighthouses in the groups found */
    private function groupNames(bool $hideDifferentIds): array
    {
        $names = [];
        foreach ($this->service->findGroups(50.0, $hideDifferentIds) as $group) {
            foreach ($group['pois'] as $poi) {
                $names[] = $poi['name'];
            }
        }

        return $names;
    }

    public function testTheSwitchHidesImportedGroupsWhoseInternationalIdsAllExistAndDiffer(): void
    {
        $imported = '2026-10-14 12:00:00';
        // Group 1: imported, both ids set and different -> hidden by the switch.
        $this->lighthouse('Diff 1', 55.0, 10.0, ['international_id' => 'B1', 'imported_at' => $imported]);
        $this->lighthouse('Diff 2', 55.0 + self::STEP, 10.0, ['international_id' => 'B2']);
        // Group 2: imported, same id on both -> stays.
        $this->lighthouse('Same 1', 56.0, 10.0, ['international_id' => 'B3', 'imported_at' => $imported]);
        $this->lighthouse('Same 2', 56.0 + self::STEP, 10.0, ['international_id' => 'B3']);
        // Group 3: different ids, one of them NULL -> stays.
        $this->lighthouse('Null 1', 57.0, 10.0, ['international_id' => 'B4', 'imported_at' => $imported]);
        $this->lighthouse('Null 2', 57.0 + self::STEP, 10.0);
        // Group 4: different ids, one of them empty text -> stays.
        $this->lighthouse('Empty 1', 58.0, 10.0, ['international_id' => 'B5', 'imported_at' => $imported]);
        $this->lighthouse('Empty 2', 58.0 + self::STEP, 10.0, ['international_id' => '  ']);
        // Group 5: different ids but nothing imported (both drawn by hand) -> stays.
        $this->lighthouse('Manual 1', 59.0, 10.0, ['international_id' => 'B6']);
        $this->lighthouse('Manual 2', 59.0 + self::STEP, 10.0, ['international_id' => 'B7']);

        $all = $this->groupNames(false);
        $filtered = $this->groupNames(true);

        $this->assertCount(10, $all);
        $this->assertNotContains('Diff 1', $filtered);
        $this->assertNotContains('Diff 2', $filtered);
        foreach (['Same 1', 'Same 2', 'Null 1', 'Null 2', 'Empty 1', 'Empty 2', 'Manual 1', 'Manual 2'] as $stays) {
            $this->assertContains($stays, $filtered, "$stays must stay visible");
        }
        $this->assertCount(8, $filtered);
    }

    public function testTheSwitchForGroupsWithANonImportedEntry(): void
    {
        $imported = '2026-10-14 12:00:00';
        // All imported -> hidden by the switch.
        $this->lighthouse('Imported 1', 55.0, 10.0, ['imported_at' => $imported]);
        $this->lighthouse('Imported 2', 55.0 + self::STEP, 10.0, ['imported_at' => $imported]);
        // One imported, one not -> stays.
        $this->lighthouse('Mixed 1', 56.0, 10.0, ['imported_at' => $imported]);
        $this->lighthouse('Mixed 2', 56.0 + self::STEP, 10.0);
        // None imported -> stays.
        $this->lighthouse('Manual 1', 57.0, 10.0);
        $this->lighthouse('Manual 2', 57.0 + self::STEP, 10.0);

        $filtered = array_merge(...array_map(
            fn ($g) => array_column($g['pois'], 'name'),
            $this->service->findGroups(50.0, false, true)
        ));

        $this->assertNotContains('Imported 1', $filtered);
        $this->assertNotContains('Imported 2', $filtered);
        $this->assertEqualsCanonicalizing(['Mixed 1', 'Mixed 2', 'Manual 1', 'Manual 2'], $filtered);
        $this->assertCount(3, $this->service->findGroups(50.0), 'off: everything');
    }

    public function testBothSwitchesCombineAndTheControllerReportsThem(): void
    {
        $controller = new LighthouseDuplicateController($this->service);
        $admin = $this->authPayload($this->createUser(['is_admin' => 1]), ['is_admin' => true]);
        $imported = '2026-10-14 12:00:00';
        // Differing ids, both imported: hidden by either switch.
        $this->lighthouse('A1', 55.0, 10.0, ['international_id' => 'B1', 'imported_at' => $imported]);
        $this->lighthouse('A2', 55.0 + self::STEP, 10.0, ['international_id' => 'B2', 'imported_at' => $imported]);
        // Same ids, one manual: stays with both.
        $this->lighthouse('M1', 56.0, 10.0, ['international_id' => 'B3', 'imported_at' => $imported]);
        $this->lighthouse('M2', 56.0 + self::STEP, 10.0, ['international_id' => 'B3']);

        $params = ['hide_different_ids' => '1', 'only_with_manual' => '1'];
        $both = $this->decode($controller->index($this->request('GET', '/x', $admin, null, $params), $this->response()));
        $none = $this->decode($controller->index($this->request('GET', '/x', $admin), $this->response()));

        $this->assertSame(1, $both['meta']['total']);
        $this->assertTrue($both['meta']['only_with_manual']);
        $this->assertSame(2, $none['meta']['total']);
        $this->assertFalse($none['meta']['only_with_manual']);
    }

    public function testControllerPassesTheSwitchAndReportsIt(): void
    {
        $controller = new LighthouseDuplicateController($this->service);
        $admin = $this->authPayload($this->createUser(['is_admin' => 1]), ['is_admin' => true]);
        $this->lighthouse('Diff 1', 55.0, 10.0, ['international_id' => 'B1', 'imported_at' => '2026-10-14 12:00:00']);
        $this->lighthouse('Diff 2', 55.0 + self::STEP, 10.0, ['international_id' => 'B2']);

        $off = $this->decode($controller->index($this->request('GET', '/x', $admin, null, ['hide_different_ids' => '0']), $this->response()));
        $on = $this->decode($controller->index($this->request('GET', '/x', $admin, null, ['hide_different_ids' => '1']), $this->response()));

        $this->assertSame(1, $off['meta']['total']);
        $this->assertFalse($off['meta']['hide_different_ids']);
        $this->assertSame(0, $on['meta']['total']);
        $this->assertTrue($on['meta']['hide_different_ids']);
    }

    public function testALighthouseWithAnImportTimeCountsAsImportedEvenWithoutAStagedEntry(): void
    {
        $this->lighthouse('Mit Zeit', 55.0, 10.0, ['imported_at' => '2026-10-14 12:00:00']);
        $this->lighthouse('Von Hand', 55.0001, 10.0);

        $pois = $this->service->findGroups(50.0)[0]['pois'];

        $this->assertSame(['Von Hand', 'Mit Zeit'], array_column($pois, 'name'), 'hand-drawn first');
        $this->assertTrue($pois[0]['manual']);
        $this->assertFalse($pois[1]['manual']);
        $this->assertSame('2026-10-14 12:00:00', $pois[1]['imported_at']);
        $this->assertNull($pois[0]['imported_at']);
        $this->assertNotNull($pois[0]['updated_at']);
    }

    public function testTheKeptLighthouseTakesTheLatestImportTimeOfTheMerge(): void
    {
        $manual = $this->lighthouse('Von Hand', 55.0, 10.0);
        $early = $this->lighthouse('Früh', 55.0001, 10.0, ['imported_at' => '2026-10-01 08:00:00']);
        $late = $this->lighthouse('Spät', 55.0002, 10.0, ['imported_at' => '2026-10-14 08:00:00']);

        $this->service->merge([['keep_id' => (int) $manual['id'], 'ids' => [(int) $manual['id'], (int) $early['id'], (int) $late['id']]]]);

        $this->assertSame('2026-10-14 08:00:00', $this->pois->findById((int) $manual['id'])['imported_at']);
    }

    /** @return array<string,mixed> */
    private function stagedEntry(string $key): array
    {
        return [
            'source_key' => $key, 'dk_no' => '1', 'international_id' => '', 'region' => '', 'subregion' => '', 'name' => 'E',
            'location' => '', 'latitude' => 55.0, 'longitude' => 10.0, 'characteristic' => '', 'other_signals' => '',
            'flame_height' => '', 'light_range' => '', 'appearance' => '', 'remarks' => '',
            'sector_characteristic' => null, 'sector_missing_range' => false,
        ];
    }

    public function testMergeTakesEachFieldFromTheChosenRecord(): void
    {
        $manual = $this->lighthouse('Skagen Fyr', 55.00000, 10.0, ['description' => 'von Hand', 'characteristic' => 'Fl W 10s', 'public' => 1]);
        $imported = $this->lighthouse('Skagen', 55.00040, 10.0, [
            'description' => 'aus der Liste', 'characteristic' => 'Fl(2) W 10s', 'sector_characteristic' => 'W 10M 10-20',
            'international_id' => 'B1234', 'public' => 0,
        ]);
        $m = (int) $manual['id'];
        $i = (int) $imported['id'];

        $result = $this->service->merge([[
            'keep_id' => $m,
            'ids' => [$m, $i],
            'fields' => [
                'name' => $m, 'description' => $i, 'position' => $m, 'characteristic' => $i,
                'sector_characteristic' => $i, 'international_id' => $i, 'public' => $m,
            ],
        ]]);

        $this->assertSame(['merged' => 1, 'deleted' => 1, 'images_moved' => 0, 'images_dropped' => 0], $result);
        $kept = $this->pois->findById($m);
        $this->assertSame('Skagen Fyr', $kept['name']);
        $this->assertSame('aus der Liste', $kept['description']);
        $this->assertEqualsWithDelta(55.0, (float) $kept['latitude'], 0.000001);
        $this->assertSame('Fl(2) W 10s', $kept['characteristic']);
        $this->assertSame('W 10M 10-20', $kept['sector_characteristic']);
        $this->assertSame('B1234', $kept['international_id']);
        $this->assertSame(1, (int) $kept['public']);
        $this->assertSame($this->userId, (int) $kept['user_id']);
        $this->expectException(NotFoundException::class);
        $this->pois->findById($i);
    }

    public function testFieldsWithoutAChoiceStayWithTheKeptRecord(): void
    {
        $a = $this->lighthouse('Eins', 55.0, 10.0, ['description' => 'A']);
        $b = $this->lighthouse('Zwei', 55.0001, 10.0, ['description' => 'B']);

        $this->service->merge([['keep_id' => (int) $b['id'], 'ids' => [(int) $a['id'], (int) $b['id']]]]);

        $kept = $this->pois->findById((int) $b['id']);
        $this->assertSame('Zwei', $kept['name']);
        $this->assertSame('B', $kept['description']);
    }

    public function testMergeOfThreeDeletesTheOthersAndOneLighthouseRowIsLeft(): void
    {
        $a = $this->lighthouse('A', 55.0, 10.0);
        $b = $this->lighthouse('B', 55.0001, 10.0);
        $c = $this->lighthouse('C', 55.0002, 10.0);

        $result = $this->service->merge([['keep_id' => (int) $a['id'], 'ids' => [(int) $a['id'], (int) $b['id'], (int) $c['id']]]]);

        $this->assertSame(2, $result['deleted']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM poi')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM lighthouse')->fetchColumn());
    }

    public function testListImportEntriesPointAtTheSurvivorAfterwards(): void
    {
        $manual = $this->lighthouse('Manual', 55.0, 10.0);
        $imported = $this->lighthouse('Imported', 55.0001, 10.0);
        $this->staging->upsertMany('dk', [$this->stagedEntry('k1')]);
        $stagingId = (int) $this->staging->search([], null)[0]['id'];
        $this->staging->markImported($stagingId, (int) $imported['id']);

        $this->service->merge([['keep_id' => (int) $manual['id'], 'ids' => [(int) $manual['id'], (int) $imported['id']]]]);

        $row = $this->staging->search([], null)[0];
        $this->assertSame((int) $manual['id'], (int) $row['poi_id'], 'must not turn "open" again');
    }

    public function testPhotosOfTheOthersAreTakenOverUpToTheLimit(): void
    {
        $keep = $this->lighthouse('Keep', 55.0, 10.0);
        $other = $this->lighthouse('Other', 55.0001, 10.0);
        $k = (int) $keep['id'];
        $o = (int) $other['id'];
        // Keep has 3 photos, the other 4: only two more fit.
        foreach (range(1, 3) as $n) {
            $this->addPhoto($k, "keep$n.jpg");
        }
        foreach (range(1, 4) as $n) {
            $this->addPhoto($o, "other$n.jpg");
        }

        $result = $this->service->merge([['keep_id' => $k, 'ids' => [$k, $o]]]);

        $this->assertSame(2, $result['images_moved']);
        $this->assertSame(2, $result['images_dropped']);
        $this->assertSame(5, $this->pois->countImages($k));
        // The copies exist under the kept POI, the other POI's files are gone.
        foreach ($this->pois->getImages($k) as $image) {
            $this->assertFileExists($this->imageDir . '/' . $k . '/' . $image['filename']);
        }
        $this->assertSame([], glob($this->imageDir . '/' . $o . '/*') ?: []);
    }

    private function addPhoto(int $poiId, string $name): void
    {
        $dir = $this->imageDir . '/' . $poiId;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $name, 'x');
        $this->pois->addImage($poiId, $name, 'image/jpeg', 1);
    }

    public function testInvalidMergesAreRejectedAndChangeNothing(): void
    {
        $a = $this->lighthouse('A', 55.0, 10.0);
        $b = $this->lighthouse('B', 55.0001, 10.0);
        $other = $this->pois->create($this->userId, ['poitype_id' => 2, 'name' => 'X', 'latitude' => 55.0, 'longitude' => 10.0]);
        $ids = [(int) $a['id'], (int) $b['id']];

        $bad = [
            'one record' => ['keep_id' => (int) $a['id'], 'ids' => [(int) $a['id']]],
            'keep not among them' => ['keep_id' => (int) $other['id'], 'ids' => $ids],
            'not a lighthouse' => ['keep_id' => (int) $a['id'], 'ids' => [(int) $a['id'], (int) $other['id']]],
            'unknown field' => ['keep_id' => (int) $a['id'], 'ids' => $ids, 'fields' => ['owner' => (int) $b['id']]],
            'source outside the merge' => ['keep_id' => (int) $a['id'], 'ids' => $ids, 'fields' => ['name' => (int) $other['id']]],
        ];
        foreach ($bad as $label => $merge) {
            try {
                $this->service->merge([$merge]);
                $this->fail("Expected ValidationException for: $label");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM poi')->fetchColumn());
    }

    public function testControllerListsPagedGroupsMergesAndIsAdminOnly(): void
    {
        $controller = new LighthouseDuplicateController($this->service);
        $admin = $this->authPayload($this->createUser(['is_admin' => 1]), ['is_admin' => true]);
        $a = $this->lighthouse('A1', 55.0, 10.0);
        $b = $this->lighthouse('A2', 55.0001, 10.0);
        $this->lighthouse('B1', 56.0, 10.0);
        $this->lighthouse('B2', 56.0001, 10.0);

        $page = $this->decode($controller->index(
            $this->request('GET', '/x', $admin, null, ['distance' => '50', 'limit' => '1', 'offset' => '1']),
            $this->response()
        ));
        $this->assertCount(1, $page['data']);
        $this->assertSame(2, $page['meta']['total']);
        $this->assertSame('B1', $page['data'][0]['pois'][0]['name']);

        $merged = $this->decode($controller->merge(
            $this->request('POST', '/x', $admin, ['merges' => [['keep_id' => (int) $a['id'], 'ids' => [(int) $a['id'], (int) $b['id']]]]]),
            $this->response()
        ));
        $this->assertSame(1, $merged['data']['merged']);

        foreach (['distance' => '0', 'too far' => '99999'] as $label => $distance) {
            try {
                $controller->index($this->request('GET', '/x', $admin, null, ['distance' => $distance]), $this->response());
                $this->fail("Expected ValidationException for $label");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $normal = $this->authPayload($this->createUser());
        try {
            $controller->index($this->request('GET', '/x', $normal), $this->response());
            $this->fail('index must be admin only');
        } catch (ForbiddenException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(ForbiddenException::class);
        $controller->merge($this->request('POST', '/x', $normal, ['merges' => [[]]]), $this->response());
    }
}
