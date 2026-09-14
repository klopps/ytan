<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Http\Controllers\RouteController;
use Ytan\Service\CaptchaService;
use Ytan\Service\MailService;
use Ytan\Service\TourNotificationService;

/**
 * route.recorded_at/recording_duration_seconds/recorded_by_username (a
 * GPS-recorded route's metadata, migrations 017/018) are only visible to
 * admins and users with the route_view_recording right - everyone else
 * (including anonymous viewers) gets a route row with all three keys
 * removed entirely. Covers RouteController::redactRecordingInfo(), applied
 * to index()/show()/create()/update().
 */
final class RouteControllerRecordingInfoTest extends ControllerTestCase
{
    private function makeController(): RouteController
    {
        $mail = new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test');
        $notifications = new TourNotificationService(new UserRepository($this->pdo), $mail, 'http://localhost');

        return new RouteController(
            new RouteRepository($this->pdo),
            new TourRepository($this->pdo),
            new CaptchaService('unit-test-secret'),
            $notifications
        );
    }

    private function createRecordedRoute(int $userId): int
    {
        return $this->createRoute($userId, [
            'public' => 1,
            'recorded_at' => '2026-09-14 08:00:00',
            'recording_duration_seconds' => 5400,
        ]);
    }

    public function testShowHidesRecordingInfoFromAnonymousViewer(): void
    {
        $owner = $this->createUser(['username' => 'recorder']);
        $routeId = $this->createRecordedRoute($owner);
        $controller = $this->makeController();

        $result = $this->decode($controller->show($this->request('GET', "/routes/$routeId"), $this->response(), ['id' => (string) $routeId]));

        $this->assertArrayNotHasKey('recorded_at', $result['data']);
        $this->assertArrayNotHasKey('recording_duration_seconds', $result['data']);
        $this->assertArrayNotHasKey('recorded_by_username', $result['data']);
    }

    public function testShowHidesRecordingInfoFromLoggedInUserWithoutTheRight(): void
    {
        $owner = $this->createUser(['username' => 'recorder']);
        $viewer = $this->createUser(['route_view_recording' => 0]);
        $routeId = $this->createRecordedRoute($owner);
        $controller = $this->makeController();

        $result = $this->decode($controller->show(
            $this->request('GET', "/routes/$routeId", $this->authPayload($viewer)),
            $this->response(),
            ['id' => (string) $routeId]
        ));

        $this->assertArrayNotHasKey('recorded_at', $result['data']);
    }

    public function testShowRevealsRecordingInfoToUserWithTheRight(): void
    {
        $owner = $this->createUser(['username' => 'recorder']);
        $viewer = $this->createUser(['route_view_recording' => 1]);
        $routeId = $this->createRecordedRoute($owner);
        $controller = $this->makeController();

        $result = $this->decode($controller->show(
            $this->request('GET', "/routes/$routeId", $this->authPayload($viewer, ['route_view_recording' => true])),
            $this->response(),
            ['id' => (string) $routeId]
        ));

        $this->assertSame('2026-09-14 08:00:00', $result['data']['recorded_at']);
        $this->assertSame(5400, (int) $result['data']['recording_duration_seconds']);
        $this->assertSame('recorder', $result['data']['recorded_by_username']);
    }

    public function testShowRevealsRecordingInfoToAdminEvenWithoutTheDedicatedRight(): void
    {
        $owner = $this->createUser(['username' => 'recorder']);
        $admin = $this->createUser(['is_admin' => 1]);
        $routeId = $this->createRecordedRoute($owner);
        $controller = $this->makeController();

        $result = $this->decode($controller->show(
            $this->request('GET', "/routes/$routeId", $this->authPayload($admin, ['is_admin' => true])),
            $this->response(),
            ['id' => (string) $routeId]
        ));

        $this->assertSame('2026-09-14 08:00:00', $result['data']['recorded_at']);
    }

    public function testIndexRedactsRecordingInfoForEveryRouteWhenViewerLacksTheRight(): void
    {
        $owner = $this->createUser();
        $this->createRecordedRoute($owner);
        $this->createRecordedRoute($owner);
        $controller = $this->makeController();

        $result = $this->decode($controller->index($this->request('GET', '/routes', null, null, ['scope' => 'public']), $this->response()));

        $this->assertCount(2, $result['data']);
        foreach ($result['data'] as $route) {
            $this->assertArrayNotHasKey('recorded_at', $route);
        }
    }

    public function testIndexKeepsRecordingInfoForViewerWithTheRight(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser(['route_view_recording' => 1]);
        $this->createRecordedRoute($owner);
        $controller = $this->makeController();

        $result = $this->decode($controller->index(
            $this->request('GET', '/routes', $this->authPayload($viewer, ['route_view_recording' => true]), null, ['scope' => 'public']),
            $this->response()
        ));

        $this->assertArrayHasKey('recorded_at', $result['data'][0]);
    }

    public function testCreateResponseIsRedactedForOwnerWithoutTheRight(): void
    {
        // Even the person who just recorded and saved the route doesn't see
        // their own recording metadata in the response unless they're an
        // admin or have the right - matches the exact requirement as given
        // (no implicit "owner can always see it" exception).
        $owner = $this->createUser();
        $controller = $this->makeController();

        $result = $this->decode($controller->create($this->request('POST', '/routes', $this->authPayload($owner), [
            'name' => 'Recorded Route',
            'points' => '[]',
            'recorded_at' => '2026-09-14 08:00:00',
            'recording_duration_seconds' => 300,
        ]), $this->response()));

        $this->assertArrayNotHasKey('recorded_at', $result['data']);
    }
}
