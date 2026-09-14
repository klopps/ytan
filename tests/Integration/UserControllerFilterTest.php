<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\User\UserRepository;
use Ytan\Http\Controllers\UserController;
use Ytan\Service\AuthService;
use Ytan\Service\MailService;

/**
 * Regression test: UserController::index()'s query-param allow-list (used
 * by the admin panel's "Filter by right" checkboxes) used to be a separate,
 * hand-kept list of tour-right field names that never got the new
 * route_view_recording right added to it - so GET /users?route_view_recording=1
 * would silently ignore the filter (unknown params are just dropped, not
 * rejected) even though UserRepository::findAll()/buildRightFilterWhere()
 * already supported it via the shared RIGHT_COLUMNS list. Caught while
 * wiring up the admin-user.js checkbox for the new right.
 */
final class UserControllerFilterTest extends ControllerTestCase
{
    private function mailService(): MailService
    {
        return new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test');
    }

    private function makeController(): UserController
    {
        $users = new UserRepository($this->pdo);

        return new UserController(
            $users,
            $this->mailService(),
            new AuthService($users, 'unit-test-secret', 3600, $this->mailService(), 'http://localhost'),
            'http://localhost'
        );
    }

    public function testIndexFiltersByRouteViewRecordingRight(): void
    {
        $admin = $this->createUser(['is_admin' => 1]);
        $withRight = $this->createUser(['username' => 'recorder-viewer', 'route_view_recording' => 1]);
        $this->createUser(['username' => 'no-right', 'route_view_recording' => 0]);
        $controller = $this->makeController();

        $result = $this->decode($controller->index(
            $this->request('GET', '/users', $this->authPayload($admin, ['is_admin' => true]), null, ['route_view_recording' => '1']),
            $this->response()
        ));

        $ids = array_column($result['data'], 'id');
        $this->assertContains($withRight, $ids);
        $this->assertCount(1, $result['data']);
    }
}
