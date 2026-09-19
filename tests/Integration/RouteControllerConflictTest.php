<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ConflictException;
use Ytan\Http\Controllers\RouteController;
use Ytan\Service\CaptchaService;
use Ytan\Service\ImageStorageService;
use Ytan\Service\MailService;
use Ytan\Service\TourNotificationService;

/**
 * Covers RouteController::delete()'s expected_updated_at query-param wiring
 * (todo.md's "Offline-Funktionalität" Phase 3) - RouteRepositoryTest already
 * covers the underlying repository-level check itself, this proves the
 * controller actually reads the query param and lets ConflictException
 * propagate as-is (App.php's error handler is what turns it into the 409
 * JSON body a real request sees).
 */
final class RouteControllerConflictTest extends ControllerTestCase
{
    private RouteController $controller;
    private RouteRepository $routes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->routes = new RouteRepository($this->pdo);
        $tours = new TourRepository($this->pdo);
        $captcha = new CaptchaService('unit-test-secret');
        $notifications = new TourNotificationService(
            new UserRepository($this->pdo),
            new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test'),
            'http://localhost'
        );
        $this->controller = new RouteController($this->routes, $tours, $captcha, $notifications, new ImageStorageService(sys_get_temp_dir() . '/ytan-test-images'));
    }

    public function testDeleteWithoutExpectedUpdatedAtQueryParamSucceedsAsBefore(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId);

        $response = $this->controller->delete(
            $this->request('DELETE', '/routes/' . $routeId, $this->authPayload($userId)),
            $this->response(),
            ['id' => (string) $routeId]
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDeleteWithStaleExpectedUpdatedAtThrowsConflictExceptionCarryingTheServerRecord(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId);

        try {
            $this->controller->delete(
                $this->request('DELETE', '/routes/' . $routeId, $this->authPayload($userId), null, [
                    'expected_updated_at' => '2000-01-01 00:00:00',
                ]),
                $this->response(),
                ['id' => (string) $routeId]
            );
            $this->fail('Expected ConflictException.');
        } catch (ConflictException $e) {
            $this->assertSame($routeId, $e->getPayload()['server']['id']);
        }

        // Still there - the failed attempt must not have deleted anything.
        $this->assertSame($routeId, $this->routes->findById($routeId)['id']);
    }

    public function testDeleteWithMatchingExpectedUpdatedAtSucceeds(): void
    {
        $userId = $this->createUser();
        $route = $this->routes->create($userId, ['name' => 'Original']);

        $response = $this->controller->delete(
            $this->request('DELETE', '/routes/' . $route['id'], $this->authPayload($userId), null, [
                'expected_updated_at' => $route['updated_at'],
            ]),
            $this->response(),
            ['id' => (string) $route['id']]
        );

        $this->assertSame(200, $response->getStatusCode());
    }
}
