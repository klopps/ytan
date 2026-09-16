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

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->controller = new AdminController(
            new ImageReconciliationService([]),
            $this->pois,
            $this->routes,
            $this->areas,
            $this->tours,
            $this->users,
        );
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
