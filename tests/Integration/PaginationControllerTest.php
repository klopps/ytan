<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Area\AreaRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Http\Controllers\AreaController;
use Ytan\Http\Controllers\PoiController;
use Ytan\Http\Controllers\RouteController;
use Ytan\Http\Controllers\TourController;
use Ytan\Http\Controllers\UserController;
use Ytan\Service\AuthService;
use Ytan\Service\CaptchaService;
use Ytan\Service\MailService;
use Ytan\Service\ImageStorageService;
use Ytan\Service\TourNotificationService;

/**
 * Covers the `limit`/`offset` -> `meta` wiring shared by all five list
 * endpoints (BaseController::parsePagination()): omitting both must leave
 * the response exactly as before (no `meta` key, full result set - the
 * "existing callers see zero behavior change" guarantee), while passing
 * `limit` must both slice `data` and report the correct total in `meta`.
 */
final class PaginationControllerTest extends ControllerTestCase
{
    private function mailService(): MailService
    {
        return new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test');
    }

    public function testPoiIndexWithoutLimitOmitsMetaAndReturnsEverything(): void
    {
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (2, 'Test Type')");
        $pois = new PoiRepository($this->pdo);
        $controller = new PoiController($pois, new ImageStorageService(sys_get_temp_dir() . '/ytan-test-images'));
        $userId = $this->createUser();
        foreach (range(1, 3) as $i) {
            $pois->create($userId, ['poitype_id' => 2, 'name' => "Poi $i", 'latitude' => 54.0, 'longitude' => 10.0, 'public' => 1]);
        }

        $result = $this->decode($controller->index($this->request('GET', '/pois', null, null, ['scope' => 'public']), $this->response()));

        $this->assertCount(3, $result['data']);
        $this->assertNull($result['meta']);
    }

    public function testPoiIndexWithLimitSlicesDataAndReportsTotalInMeta(): void
    {
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (2, 'Test Type')");
        $pois = new PoiRepository($this->pdo);
        $controller = new PoiController($pois, new ImageStorageService(sys_get_temp_dir() . '/ytan-test-images'));
        $userId = $this->createUser();
        foreach (range(1, 3) as $i) {
            $pois->create($userId, ['poitype_id' => 2, 'name' => "Poi $i", 'latitude' => 54.0, 'longitude' => 10.0, 'public' => 1]);
        }

        $result = $this->decode($controller->index(
            $this->request('GET', '/pois', null, null, ['scope' => 'public', 'limit' => '2', 'offset' => '1']),
            $this->response()
        ));

        $this->assertCount(2, $result['data']);
        $this->assertSame(['total' => 3, 'limit' => 2, 'offset' => 1], $result['meta']);
    }

    public function testRouteIndexWithLimitReportsTotalInMeta(): void
    {
        $routes = new RouteRepository($this->pdo);
        $tours = new TourRepository($this->pdo);
        $notifications = new TourNotificationService(new UserRepository($this->pdo), $this->mailService(), 'http://localhost');
        $controller = new RouteController($routes, $tours, new CaptchaService('unit-test-secret'), $notifications, new ImageStorageService(sys_get_temp_dir() . '/ytan-test-images'));
        $userId = $this->createUser();
        $this->createRoute($userId, ['public' => 1]);
        $this->createRoute($userId, ['public' => 1]);
        $this->createRoute($userId, ['public' => 1]);

        $result = $this->decode($controller->index(
            $this->request('GET', '/routes', null, null, ['scope' => 'public', 'limit' => '2']),
            $this->response()
        ));

        $this->assertCount(2, $result['data']);
        $this->assertSame(['total' => 3, 'limit' => 2, 'offset' => 0], $result['meta']);
    }

    public function testRouteIndexByTourIdIgnoresLimitSinceItsNeverPaginated(): void
    {
        $routes = new RouteRepository($this->pdo);
        $tours = new TourRepository($this->pdo);
        $notifications = new TourNotificationService(new UserRepository($this->pdo), $this->mailService(), 'http://localhost');
        $controller = new RouteController($routes, $tours, new CaptchaService('unit-test-secret'), $notifications, new ImageStorageService(sys_get_temp_dir() . '/ytan-test-images'));
        $userId = $this->createUser();
        $tour = $tours->create($userId, ['name' => 'T']);
        $route = $this->createRoute($userId);
        $tours->addRoute((int) $tour['id'], $route);

        $result = $this->decode($controller->index(
            $this->request('GET', '/routes', null, null, ['tour_id' => (string) $tour['id'], 'limit' => '1']),
            $this->response()
        ));

        $this->assertCount(1, $result['data']);
        $this->assertNull($result['meta']);
    }

    public function testAreaIndexWithLimitReportsTotalInMeta(): void
    {
        $areas = new AreaRepository($this->pdo);
        $controller = new AreaController($areas, new ImageStorageService(sys_get_temp_dir() . '/ytan-test-images'));
        $userId = $this->createUser();
        $areas->create($userId, ['name' => 'A1', 'public' => 1]);
        $areas->create($userId, ['name' => 'A2', 'public' => 1]);
        $areas->create($userId, ['name' => 'A3', 'public' => 1]);

        $result = $this->decode($controller->index(
            $this->request('GET', '/areas', null, null, ['scope' => 'public', 'limit' => '2']),
            $this->response()
        ));

        $this->assertCount(2, $result['data']);
        $this->assertSame(['total' => 3, 'limit' => 2, 'offset' => 0], $result['meta']);
    }

    public function testUserIndexWithLimitReportsTotalInMeta(): void
    {
        $users = new UserRepository($this->pdo);
        $controller = new UserController($users, $this->mailService(), new AuthService($users, 'unit-test-secret', 3600, $this->mailService(), 'http://localhost'), 'http://localhost');
        $admin = $this->createUser(['is_admin' => 1]);
        $this->createUser(['username' => 'zzz']);
        $this->createUser(['username' => 'aaa']);

        $result = $this->decode($controller->index(
            $this->request('GET', '/users', $this->authPayload($admin, ['is_admin' => true]), null, ['limit' => '2']),
            $this->response()
        ));

        // admin + 2 created = 3 total, page size 2.
        $this->assertCount(2, $result['data']);
        $this->assertSame(['total' => 3, 'limit' => 2, 'offset' => 0], $result['meta']);
    }

    public function testTourIndexWithLimitReportsTotalInMeta(): void
    {
        $tours = new TourRepository($this->pdo);
        $controller = new TourController($tours, new ImageStorageService(sys_get_temp_dir() . '/ytan-test-images'));
        $userId = $this->createUser();
        $tours->create($userId, ['name' => 'Alpha']);
        $tours->create($userId, ['name' => 'Beta']);
        $tours->create($userId, ['name' => 'Gamma']);

        $result = $this->decode($controller->index(
            $this->request('GET', '/tours', $this->authPayload($userId), null, ['scope' => 'mine', 'limit' => '2']),
            $this->response()
        ));

        $this->assertCount(2, $result['data']);
        $this->assertSame(['total' => 3, 'limit' => 2, 'offset' => 0], $result['meta']);
    }

    public function testLimitIsClampedToTheConfiguredMaximum(): void
    {
        $areas = new AreaRepository($this->pdo);
        $controller = new AreaController($areas, new ImageStorageService(sys_get_temp_dir() . '/ytan-test-images'));
        $userId = $this->createUser();
        $areas->create($userId, ['name' => 'A1', 'public' => 1]);

        $result = $this->decode($controller->index(
            $this->request('GET', '/areas', null, null, ['scope' => 'public', 'limit' => '999999']),
            $this->response()
        ));

        $this->assertSame(500, $result['meta']['limit']);
    }
}
