<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Area\AreaRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Http\Controllers\AdminController;
use Ytan\Service\ImageReconciliationService;
use Ytan\Service\Water\WaterDistanceService;

/**
 * Covers AdminController::dashboardStats() - the admin-gating and the
 * public/private split it derives from countAll()/countPublic() per
 * entity type. scanImageOrphans()/deleteImageOrphans() already have their
 * own coverage via EntityImageCleanupTest/ImageReconciliationServiceTest,
 * not duplicated here.
 */
final class AdminControllerTest extends ControllerTestCase
{
    private AdminController $controller;
    private PoiRepository $pois;
    private RouteRepository $routes;
    private AreaRepository $areas;
    private TourRepository $tours;
    private UserRepository $users;
    private string $metaDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metaDir = sys_get_temp_dir() . '/ytan-admin-water-' . bin2hex(random_bytes(4));
        mkdir($this->metaDir);

        // poitype is a lookup table; bin/setup-test-db.php clones structure
        // only, so the test DB starts with none of the live seed rows -
        // poi.poitype_id's FK needs at least one to exist (same fixture as
        // PoiRepositoryTest).
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (2, 'Test Type')");

        $this->pois = new PoiRepository($this->pdo);
        $this->routes = new RouteRepository($this->pdo);
        $this->areas = new AreaRepository($this->pdo);
        $this->tours = new TourRepository($this->pdo);
        $this->users = new UserRepository($this->pdo);

        $this->controller = $this->newController();
    }

    /** A controller with a fresh WaterDistanceService (it reads water-index.json once per instance). */
    private function newController(): AdminController
    {
        return new AdminController(
            new ImageReconciliationService([]),
            $this->pois,
            $this->routes,
            $this->areas,
            $this->tours,
            $this->users,
            new WaterDistanceService($this->metaDir . '/water-index.bin', $this->metaDir . '/water-index.json'),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->metaDir . '/water-index.json');
        @rmdir($this->metaDir);
        parent::tearDown();
    }

    /** The test's water-index.json with this version (a time "YYYYMMDDHHMM"); without one there is no index. */
    private function waterIndexVersion(string $version): void
    {
        file_put_contents($this->metaDir . '/water-index.json', json_encode(['version' => $version]));
    }

    private function stats(): array
    {
        $response = $this->newController()->dashboardStats(
            $this->request('GET', '/api/v1/admin/dashboard-stats', $this->authPayload($this->createUser(), ['is_admin' => true])),
            $this->response()
        );

        return $this->decode($response)['data'];
    }

    public function testTheDashboardShowsTheAgeOfTheWaterIndex(): void
    {
        $this->waterIndexVersion(gmdate('YmdHi', strtotime('-8 months')));
        $index = $this->stats()['water_index'];
        $this->assertSame('ok', $index['state']);
        $this->assertContains($index['age_months'], [7, 8]); // "-8 months" overflows at the end of a month
        $this->assertGreaterThan(200, $index['age_days']);

        $this->waterIndexVersion(gmdate('YmdHi', strtotime('-12 months -10 days')));
        $this->assertSame('warn', $this->stats()['water_index']['state']);

        $this->waterIndexVersion(gmdate('YmdHi', strtotime('-14 months')));
        $this->assertSame('old', $this->stats()['water_index']['state']);
    }

    public function testWithoutAWaterIndexTheTileIsNull(): void
    {
        $this->assertNull($this->stats()['water_index']); // no water-index.json in the temp directory
    }

    public function testDashboardStatsReturnsPublicPrivateSplitPerEntityAndUserTotal(): void
    {
        $userId = $this->createUser();

        $this->pois->create($userId, ['poitype_id' => 2, 'name' => 'P1', 'latitude' => 54.0, 'longitude' => 10.0, 'public' => 1]);
        $this->pois->create($userId, ['poitype_id' => 2, 'name' => 'P2', 'latitude' => 54.0, 'longitude' => 10.0, 'public' => 0]);

        $this->createRoute($userId, ['public' => 1]);
        $this->createRoute($userId, ['public' => 1]);
        $this->createRoute($userId, ['public' => 0]);

        $this->areas->create($userId, ['name' => 'A1', 'public' => 0]);

        $tour = $this->tours->create($userId, ['name' => 'T1']);
        $this->tours->setPublished((int) $tour['id'], true, $userId);
        $this->tours->create($userId, ['name' => 'T2']);
        $this->tours->create($userId, ['name' => 'T3']);

        $response = $this->controller->dashboardStats(
            $this->request('GET', '/api/v1/admin/dashboard-stats', $this->authPayload($userId, ['is_admin' => true])),
            $this->response()
        );

        $decoded = $this->decode($response);
        $this->assertSame(200, $decoded['status']);
        $this->assertSame(['public' => 1, 'private' => 1], $decoded['data']['poi']);
        $this->assertSame(['public' => 2, 'private' => 1], $decoded['data']['route']);
        $this->assertSame(['public' => 0, 'private' => 1], $decoded['data']['area']);
        $this->assertSame(['public' => 1, 'private' => 2], $decoded['data']['tour']);
        $this->assertSame(['total' => 1], $decoded['data']['users']);
    }

    public function testDashboardStatsRejectsANonAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->controller->dashboardStats(
            $this->request('GET', '/api/v1/admin/dashboard-stats', $this->authPayload(1, ['is_admin' => false])),
            $this->response()
        );
    }

    public function testDashboardStatsRejectsAnUnauthenticatedRequest(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->controller->dashboardStats(
            $this->request('GET', '/api/v1/admin/dashboard-stats', null),
            $this->response()
        );
    }
}
