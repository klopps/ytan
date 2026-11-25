<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Poi\PoiWaterDistanceRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\Water\WaterCorrectionRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\WaterIndexAdminController;
use Ytan\Service\Water\PoiWaterDistanceService;
use Ytan\Service\Water\WaterDistanceService;

/**
 * /admin/water: the overlay's segments and the corrections (stored, written
 * to the corrections file, applied by WaterDistanceService, POI distances
 * around them recomputed). Sample index: coast 100 m north of 56.0/9.0.
 */
final class WaterIndexAdminControllerTest extends ControllerTestCase
{
    private const AROUND_COAST = [[55.999, 8.98], [56.003, 8.98], [56.003, 9.003], [55.999, 9.003]];

    private string $file;
    private WaterIndexAdminController $controller;
    private PoiWaterDistanceRepository $distances;
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (10, 'Shelter')");
        $this->pdo->exec('INSERT INTO app_settings (id) VALUES (1)');
        $this->file = sys_get_temp_dir() . '/ytan-water-corrections-' . bin2hex(random_bytes(4)) . '.json';
        $water = new WaterDistanceService(dirname(__DIR__) . '/testdata/water-index-sample.bin', null, $this->file);
        $this->distances = new PoiWaterDistanceRepository($this->pdo);
        $this->controller = new WaterIndexAdminController(
            $water,
            new WaterCorrectionRepository($this->pdo),
            new PoiWaterDistanceService($water, $this->distances, new SettingsRepository($this->pdo)),
            $this->file
        );
        $this->admin = $this->authPayload($this->createUser(['is_admin' => 1]), ['is_admin' => true]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function addCorrection(array $body): array
    {
        return $this->decode($this->controller->create($this->request('POST', '/x', $this->admin, $body), $this->response()));
    }

    public function testOnlyAdmins(): void
    {
        $user = $this->authPayload($this->createUser());
        $this->expectException(ForbiddenException::class);
        $this->controller->segments($this->request('GET', '/x', $user, null, ['south' => 56, 'west' => 9, 'north' => 56.01, 'east' => 9.01]), $this->response());
    }

    public function testSegmentsOfTheBoxAndTooLargeBoxes(): void
    {
        $answer = $this->decode($this->controller->segments(
            $this->request('GET', '/x', $this->admin, null, ['south' => 55.98, 'west' => 8.98, 'north' => 56.02, 'east' => 9.05]),
            $this->response()
        ))['data'];
        $this->assertTrue($answer['available']);
        $this->assertFalse($answer['too_large']);
        $this->assertContains('sea', array_column($answer['segments'], 0));
        $this->assertContains('river', array_column($answer['segments'], 0));

        $large = $this->decode($this->controller->segments(
            $this->request('GET', '/x', $this->admin, null, ['south' => 55, 'west' => 8, 'north' => 57, 'east' => 10]),
            $this->response()
        ))['data'];
        $this->assertTrue($large['too_large']);
        $this->assertSame([], $large['segments']);
    }

    public function testACorrectionIsStoredWrittenAppliedAndRecomputesPois(): void
    {
        $userId = $this->createUser();
        $poiId = (int) (new PoiRepository($this->pdo))->create($userId, [
            'poitype_id' => 10, 'name' => 'Shelter', 'latitude' => 56.0, 'longitude' => 9.0, 'public' => 1,
        ])['id'];
        $this->distances->save($poiId, ['sea' => 100, 'river' => 300, 'lake' => 32000], null);

        $created = $this->addCorrection(['type' => 'delete', 'kinds' => ['sea'], 'points' => self::AROUND_COAST, 'note' => 'no coast here']);

        $this->assertSame(201, $created['status']);
        $this->assertSame('delete', $created['data']['correction']['type']);
        $this->assertSame(1, $created['data']['pois']['computed']);
        $this->assertFileExists($this->file);
        $this->assertNull($this->distances->find($poiId)['sea_m'], 'the POI no longer has the deleted coast');

        $segments = $this->decode($this->controller->segments(
            $this->request('GET', '/x', $this->admin, null, ['south' => 55.98, 'west' => 8.98, 'north' => 56.02, 'east' => 9.05]),
            $this->response()
        ))['data'];
        $this->assertNotContains('sea', array_column($segments['segments'], 0));
        $this->assertCount(1, $segments['removed']);

        $this->decode($this->controller->delete($this->request('DELETE', '/x', $this->admin), $this->response(), ['id' => $created['data']['correction']['id']]));
        $this->assertFileDoesNotExist($this->file, 'no corrections = no file');
        $this->assertEqualsWithDelta(100, $this->distances->find($poiId)['sea_m'], 2);
    }

    public function testAReplacedLineKeepsItsOriginalAndTravelsInTheTransferFile(): void
    {
        $coast = array_values(array_filter(
            $this->decode($this->controller->segments(
                $this->request('GET', '/x', $this->admin, null, ['south' => 55.98, 'west' => 8.98, 'north' => 56.02, 'east' => 9.05]),
                $this->response()
            ))['data']['segments'],
            static fn (array $s): bool => $s[0] === 'sea'
        ))[0];
        $original = [[$coast[1], $coast[2]], [$coast[3], $coast[4]]];

        $created = $this->addCorrection(['type' => 'replace', 'kinds' => ['sea'], 'original' => $original, 'points' => []]);
        $this->assertSame(201, $created['status']);
        $this->assertSame([], $created['data']['correction']['points']);
        $this->assertCount(2, $created['data']['correction']['original']);

        $export = $this->decode($this->controller->exportFile($this->request('GET', '/x', $this->admin), $this->response()))['data'];
        $this->assertSame($created['data']['correction']['original'], $export['corrections'][0]['original']);
        $again = $this->decode($this->controller->importFile($this->request('POST', '/x', $this->admin, $export), $this->response()))['data'];
        $this->assertSame(['added' => 0, 'skipped' => 1], ['added' => $again['added'], 'skipped' => $again['skipped']]);
    }

    public function testAnOwnLineIsChangedInItsCorrection(): void
    {
        $lat = 56.0 + 50 / 111195;
        $created = $this->addCorrection(['type' => 'add', 'kinds' => ['sea'], 'points' => [[$lat, 8.99], [$lat, 9.01]], 'note' => 'island']);
        $id = $created['data']['correction']['id'];

        $moved = 56.0 + 30 / 111195;
        $answer = $this->decode($this->controller->update(
            $this->request('PUT', '/x', $this->admin, ['points' => [[$moved, 8.99], [$moved, 9.01]]]),
            $this->response(),
            ['id' => $id]
        ));

        $this->assertSame(200, $answer['status']);
        $all = (new WaterCorrectionRepository($this->pdo))->all();
        $this->assertCount(1, $all, 'no second correction stacked on top');
        $this->assertSame('add', $all[0]['type']);
        $this->assertEqualsWithDelta($moved, $all[0]['points'][0][0], 1e-6);
        $this->assertSame('island', $all[0]['note'], 'the note stays when none is sent');
    }

    public function testAnAddedLineCanChangeItsKindAReplacedLineCannot(): void
    {
        $added = $this->addCorrection(['type' => 'add', 'kinds' => ['sea'], 'points' => [[56.0, 8.99], [56.0, 9.01]]]);
        $answer = $this->decode($this->controller->update(
            $this->request('PUT', '/x', $this->admin, ['points' => [[56.0, 8.99], [56.0, 9.01]], 'kinds' => ['canal']]),
            $this->response(),
            ['id' => $added['data']['correction']['id']]
        ))['data'];
        $this->assertSame(['canal'], $answer['correction']['kinds']);

        $replaced = $this->addCorrection([
            'type' => 'replace', 'kinds' => ['sea'],
            'original' => [[56.000898, 8.99], [56.000898, 9.01]],
            'points' => [[56.0005, 8.99], [56.0005, 9.01]],
        ]);
        $kept = $this->decode($this->controller->update(
            $this->request('PUT', '/x', $this->admin, ['points' => [[56.0004, 8.99], [56.0004, 9.01]], 'kinds' => ['river']]),
            $this->response(),
            ['id' => $replaced['data']['correction']['id']]
        ))['data'];
        $this->assertSame(['sea'], $kept['correction']['kinds'], 'the kind says which segments of the index it replaces');

        $this->expectException(ValidationException::class);
        $this->controller->update(
            $this->request('PUT', '/x', $this->admin, ['points' => [[56.0, 8.99], [56.0, 9.01]], 'kinds' => ['sea', 'lake']]),
            $this->response(),
            ['id' => $added['data']['correction']['id']]
        );
    }

    public function testTheNoteAloneCanBeChangedForEveryType(): void
    {
        $created = $this->addCorrection(['type' => 'delete', 'kinds' => ['sea'], 'points' => self::AROUND_COAST, 'note' => 'old']);
        $id = $created['data']['correction']['id'];

        $answer = $this->decode($this->controller->update(
            $this->request('PUT', '/x', $this->admin, ['note' => '  wrong coast  ']),
            $this->response(),
            ['id' => $id]
        ));
        $this->assertSame(200, $answer['status']);
        $this->assertSame('wrong coast', $answer['data']['correction']['note']);
        $this->assertSame(self::AROUND_COAST, $answer['data']['correction']['points'], 'the area stays');

        $cleared = $this->decode($this->controller->update($this->request('PUT', '/x', $this->admin, ['note' => '']), $this->response(), ['id' => $id]))['data'];
        $this->assertSame('', $cleared['correction']['note']);

        $this->expectException(ValidationException::class);
        $this->controller->update($this->request('PUT', '/x', $this->admin, ['note' => str_repeat('x', 501)]), $this->response(), ['id' => $id]);
    }

    public function testAnAddedLineCannotBeEmptiedOnlyDeleted(): void
    {
        $created = $this->addCorrection(['type' => 'add', 'kinds' => ['sea'], 'points' => [[56.0, 9.0], [56.01, 9.0]]]);
        $this->expectException(ValidationException::class);
        $this->controller->update(
            $this->request('PUT', '/x', $this->admin, ['points' => []]),
            $this->response(),
            ['id' => $created['data']['correction']['id']]
        );
    }

    public function testAReplacedLinesNewCourseCanBeEmptied(): void
    {
        $created = $this->addCorrection([
            'type' => 'replace', 'kinds' => ['sea'],
            'original' => [[56.000898, 8.99], [56.000898, 9.01]],
            'points' => [[56.0005, 8.99], [56.0005, 9.01]],
        ]);
        $answer = $this->decode($this->controller->update(
            $this->request('PUT', '/x', $this->admin, ['points' => []]),
            $this->response(),
            ['id' => $created['data']['correction']['id']]
        ))['data'];

        $this->assertSame([], $answer['correction']['points']);
        $this->assertCount(2, $answer['correction']['original'], 'the replaced line stays replaced');
    }

    public function testAReplacedLineNeedsAnOriginal(): void
    {
        $this->expectException(ValidationException::class);
        $this->addCorrection(['type' => 'replace', 'kinds' => ['sea'], 'points' => [[56.0, 9.0], [56.01, 9.0]]]);
    }

    public function testInvalidCorrectionsAreRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->addCorrection(['type' => 'add', 'kinds' => ['sea', 'lake'], 'points' => [[56.0, 9.0], [56.1, 9.0]]]);
    }

    public function testDeletingAnUnknownCorrection(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller->delete($this->request('DELETE', '/x', $this->admin), $this->response(), ['id' => 999999]);
    }

    public function testTransferFileRoundTripSkipsIdenticalCorrections(): void
    {
        $this->addCorrection(['type' => 'flip', 'kinds' => ['sea'], 'points' => self::AROUND_COAST]);
        $export = $this->decode($this->controller->exportFile($this->request('GET', '/x', $this->admin), $this->response()))['data'];
        $this->assertSame('ytan-water-corrections', $export['format']);
        $this->assertCount(1, $export['corrections']);

        $export['corrections'][] = ['type' => 'add', 'kinds' => ['lake'], 'points' => [[56.0, 9.0], [56.01, 9.0]], 'username' => 'elsewhere', 'created_at' => '2026-11-01 10:00:00'];
        $result = $this->decode($this->controller->importFile($this->request('POST', '/x', $this->admin, $export), $this->response()))['data'];

        $this->assertSame(1, $result['added']);
        $this->assertSame(1, $result['skipped']);
        $all = (new WaterCorrectionRepository($this->pdo))->all();
        $this->assertCount(2, $all);
        $this->assertSame('elsewhere', $all[1]['username']);
        $this->assertSame('2026-11-01 10:00:00', $all[1]['created_at']);
    }
}
