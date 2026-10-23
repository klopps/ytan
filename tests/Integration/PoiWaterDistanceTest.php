<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Poi\PoiWaterDistanceRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\PoiController;
use Ytan\Http\Controllers\SettingsController;
use Ytan\Service\ImageStorageService;
use Ytan\Service\Water\PoiWaterDistanceService;
use Ytan\Service\Water\WaterDistanceService;

/**
 * Distances to water stored per POI (poi_water_distance): computed when a POI
 * of an enabled type (app_settings.water_distance_poitypes) is created or
 * moved, dropped when it changes to a type that isn't enabled; the setting
 * itself (SettingsController). Uses the sample index
 * tests/testdata/water-index-sample.bin (coast 100 m north of 56.0/9.0).
 */
final class PoiWaterDistanceTest extends ControllerTestCase
{
    private PoiController $pois;
    private PoiWaterDistanceRepository $distances;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (10, 'Shelter'), (2, 'Landing'), (3, 'Water')");
        $this->pdo->exec('INSERT INTO app_settings (id) VALUES (1)');
        $this->settings = new SettingsRepository($this->pdo);
        $this->distances = new PoiWaterDistanceRepository($this->pdo);
        $water = new WaterDistanceService(dirname(__DIR__) . '/testdata/water-index-sample.bin');
        $this->pois = new PoiController(
            new PoiRepository($this->pdo),
            new ImageStorageService(sys_get_temp_dir() . '/ytan-test-images'),
            new PoiWaterDistanceService($water, $this->distances, $this->settings)
        );
    }

    private function createPoi(array $auth, int $type, float $lat, float $lng): int
    {
        $response = $this->pois->create(
            $this->request('POST', '/x', $auth, ['poitype_id' => $type, 'name' => 'P', 'latitude' => $lat, 'longitude' => $lng, 'public' => 1]),
            $this->response()
        );

        return (int) $this->decode($response)['data']['id'];
    }

    public function testAllTypesButThoseAtTheWaterAreEnabledByDefault(): void
    {
        $this->assertSame([0, 1, 3, 4, 5, 7, 8, 10, 11, 12, 13, 15], $this->settings->waterDistancePoiTypes());
    }

    public function testCreatingAPoiOfAnEnabledTypeStoresItsDistances(): void
    {
        $auth = $this->authPayload($this->createUser());

        $id = $this->createPoi($auth, 10, 56.0, 9.0);
        $other = $this->createPoi($auth, 2, 56.0, 9.0);

        $stored = $this->distances->find($id);
        $this->assertEqualsWithDelta(100, $stored['sea_m'], 2);
        $this->assertEqualsWithDelta(300, $stored['river_m'], 2);
        $this->assertNotNull($stored['lake_m']);
        $this->assertNull($this->distances->find($other), 'type 2 (landing site) is not enabled');
    }

    public function testPoiAnswersCarryTheDistancesAsOneCompactField(): void
    {
        $userId = $this->createUser();
        $auth = $this->authPayload($userId);

        $created = $this->decode($this->pois->create(
            $this->request('POST', '/x', $auth, ['poitype_id' => 10, 'name' => 'P', 'latitude' => 56.0, 'longitude' => 9.0, 'public' => 1]),
            $this->response()
        ))['data'];
        $this->assertCount(3, $created['water'], 'the answer of create already has what afterSave() computed');
        $this->assertEqualsWithDelta(100, $created['water'][0], 2);
        $this->assertEqualsWithDelta(300, $created['water'][1], 2);
        $this->assertArrayNotHasKey('water_sea_m', $created);

        $other = $this->createPoi($auth, 2, 56.0, 9.0);
        $list = $this->decode($this->pois->index($this->request('GET', '/x', $auth), $this->response()))['data'];
        $byId = array_column($list, null, 'id');
        $this->assertSame($created['water'], $byId[$created['id']]['water']);
        $this->assertNull($byId[$other]['water'], 'type 2 has nothing stored');
        $this->assertArrayNotHasKey('water_lake_m', $byId[$other]);

        $shown = $this->decode($this->pois->show($this->request('GET', '/x', $auth), $this->response(), ['id' => $created['id']]))['data'];
        $this->assertSame($created['water'], $shown['water']);

        $updated = $this->decode($this->pois->update(
            $this->request('PUT', '/x', $auth, ['poitype_id' => 2, 'name' => 'P', 'latitude' => 56.0, 'longitude' => 9.0, 'public' => 1]),
            $this->response(),
            ['id' => $created['id']]
        ))['data'];
        $this->assertNull($updated['water'], 'a type without distances answers without them');
    }

    public function testMovingRecomputesAndAChangedTypeDropsTheDistances(): void
    {
        $userId = $this->createUser();
        $auth = $this->authPayload($userId);
        $id = $this->createPoi($auth, 10, 56.0, 9.0);

        $this->pois->update($this->request('PUT', '/x', $auth, ['poitype_id' => 10, 'name' => 'P', 'latitude' => 56.01, 'longitude' => 9.0, 'public' => 1]), $this->response(), ['id' => $id]);
        $this->assertEqualsWithDelta(1012, $this->distances->find($id)['sea_m'], 3);

        $this->pois->update($this->request('PUT', '/x', $auth, ['poitype_id' => 2, 'name' => 'P', 'latitude' => 56.01, 'longitude' => 9.0, 'public' => 1]), $this->response(), ['id' => $id]);
        $this->assertNull($this->distances->find($id));
    }

    public function testPoisToComputeForTheCli(): void
    {
        $auth = $this->authPayload($this->createUser());
        $this->createPoi($auth, 2, 56.0, 9.0);
        $this->createPoi($auth, 10, 56.0, 9.0);

        $this->assertCount(1, $this->distances->poisToCompute([2], null, false), 'type 2 is not enabled, so none yet');
        $this->assertCount(0, $this->distances->poisToCompute([10], null, false), 'computed on create, same (no) version');
        $this->assertCount(1, $this->distances->poisToCompute([10], '202610211200', false), 'computed with another index');
        $this->assertCount(2, $this->distances->poisToCompute([2, 10], null, true));
    }

    public function testTheSettingIsAdminOnlyAndValidated(): void
    {
        $controller = new SettingsController($this->settings);
        $admin = $this->authPayload($this->createUser(), ['is_admin' => true]);

        $answer = $this->decode($controller->updateWaterDistancePoiTypes($this->request('PUT', '/x', $admin, ['poitypes' => [14, 10, 10]]), $this->response()));
        $this->assertSame([10, 14], $answer['data']['water_distance_poitypes']);
        $this->assertSame([10, 14], $this->settings->waterDistancePoiTypes());

        try {
            $controller->updateWaterDistancePoiTypes($this->request('PUT', '/x', $admin, ['poitypes' => [99]]), $this->response());
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
        }
        $this->expectException(\Ytan\Exception\ForbiddenException::class);
        $controller->updateWaterDistancePoiTypes($this->request('PUT', '/x', $this->authPayload($this->createUser()), ['poitypes' => []]), $this->response());
    }
}
