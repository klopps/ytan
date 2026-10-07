<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Watch\WatchLinkRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\WatchController;

final class WatchControllerTest extends ControllerTestCase
{
    private WatchController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new WatchController(new WatchLinkRepository($this->pdo), new RouteRepository($this->pdo));
    }

    private function points(): string
    {
        return json_encode([['lat' => 54.5, 'lng' => 10.3], ['lat' => 54.6, 'lng' => 10.4]]);
    }

    private function createToken(int $userId): string
    {
        $result = $this->decode($this->controller->createToken($this->request('POST', '/api/v1/watch/token', $this->authPayload($userId)), $this->response()));
        $this->assertSame(201, $result['status']);

        return $result['data']['token'];
    }

    private function deviceRoute(string $token): array
    {
        $request = $this->request('GET', '/api/v1/watch/device/route')->withHeader('X-Watch-Token', $token);
        $response = $this->controller->deviceRoute($request, $this->response());

        return json_decode((string) $response->getBody(), true);
    }

    private function sendToWatch(int $userId, int $routeId, string $unit = 'metric', array $authOverrides = []): array
    {
        $request = $this->request('PUT', '/api/v1/watch/route', $this->authPayload($userId, $authOverrides), ['route_id' => $routeId, 'unit' => $unit]);

        return $this->decode($this->controller->setRoute($request, $this->response()));
    }

    public function testOwnRouteSentToWatchIsServedToTheDevice(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId, ['name' => 'Schlei', 'points' => $this->points()]);
        $token = $this->createToken($userId);

        $status = $this->sendToWatch($userId, $routeId, 'nautical')['data'];
        $this->assertSame(['id' => $routeId, 'name' => 'Schlei'], $status['route']);
        $this->assertTrue($status['has_token']);

        $payload = $this->deviceRoute($token);
        $this->assertSame('Schlei', $payload['n']);
        $this->assertSame('n', $payload['u']);
        $this->assertSame([5450000, 1030000, 5460000, 1040000], $payload['p']);
    }

    public function testTokenIsStoredOnlyAsHash(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId);

        $hash = $this->pdo->query('SELECT token_hash FROM watch_link WHERE user_id = ' . $userId)->fetchColumn();
        $this->assertSame(hash('sha256', $token), $hash);
        $this->assertNotSame($token, $hash);
    }

    public function testDeviceRequestWithoutOrWithUnknownTokenIsRejected(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->deviceRoute('not-a-real-token');
    }

    public function testRegeneratingTheTokenInvalidatesTheOldOne(): void
    {
        $userId = $this->createUser();
        $old = $this->createToken($userId);
        $this->createToken($userId);

        $this->expectException(UnauthorizedException::class);
        $this->deviceRoute($old);
    }

    public function testDeletedTokenNoLongerWorks(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId);
        $this->controller->deleteToken($this->request('DELETE', '/api/v1/watch/token', $this->authPayload($userId)), $this->response());

        $this->expectException(UnauthorizedException::class);
        $this->deviceRoute($token);
    }

    public function testWithoutSelectedRouteTheDeviceGetsAnEmptyRoute(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId);

        $payload = $this->deviceRoute($token);
        $this->assertSame('none', $payload['v']);
        $this->assertSame([], $payload['p']);
    }

    public function testSomeoneElsesPublicRouteCanBeSentToTheWatch(): void
    {
        $owner = $this->createUser();
        $userId = $this->createUser();
        $routeId = $this->createRoute($owner, ['public' => 1, 'points' => $this->points()]);

        $this->assertSame($routeId, $this->sendToWatch($userId, $routeId)['data']['route']['id']);
    }

    public function testSomeoneElsesPrivateRouteCannotBeSentToTheWatch(): void
    {
        $owner = $this->createUser();
        $userId = $this->createUser();
        $routeId = $this->createRoute($owner, ['public' => 0]);

        $this->expectException(ForbiddenException::class);
        $this->sendToWatch($userId, $routeId);
    }

    public function testRouteMadePrivateLaterIsNoLongerServed(): void
    {
        $owner = $this->createUser();
        $userId = $this->createUser();
        $routeId = $this->createRoute($owner, ['public' => 1, 'points' => $this->points()]);
        $token = $this->createToken($userId);
        $this->sendToWatch($userId, $routeId);

        $this->pdo->exec('UPDATE route SET public = 0 WHERE id = ' . $routeId);

        $this->assertSame([], $this->deviceRoute($token)['p']);
    }

    public function testDeletingTheRouteClearsTheWatchSelection(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId, ['points' => $this->points()]);
        $token = $this->createToken($userId);
        $this->sendToWatch($userId, $routeId);

        $this->pdo->exec('DELETE FROM route WHERE id = ' . $routeId);

        $this->assertSame([], $this->deviceRoute($token)['p']);
    }

    public function testInvalidUnitIsRejected(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId);

        $this->expectException(ValidationException::class);
        $this->sendToWatch($userId, $routeId, 'furlongs');
    }

    private function putColors(int $userId, mixed $colors): array
    {
        $request = $this->request('PUT', '/api/v1/watch/colors', $this->authPayload($userId), ['colors' => $colors]);

        return $this->decode($this->controller->setColors($request, $this->response()));
    }

    public function testDeviceGetsTheDefaultColorsUntilConfigured(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId);

        // bearing, distance, text, north - each dark background, light background.
        $this->assertSame(
            [0x20c0ff, 0x0000c0, 0x80ff80, 0x008000, 0xffffff, 0x000000, 0xff0000, 0xff0000],
            $this->deviceRoute($token)['c']
        );
    }

    public function testConfiguredColorsReachTheDeviceAndPartialInputKeepsTheRest(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId);

        $status = $this->putColors($userId, ['distance' => ['dark' => '#FFAA00'], 'north' => ['light' => '#112233']])['data'];
        $this->assertSame('#ffaa00', $status['colors']['distance']['dark']);
        $this->assertSame('#008000', $status['colors']['distance']['light']);
        $this->assertSame('#20c0ff', $status['default_colors']['bearing']['dark']);

        $this->assertSame(
            [0x20c0ff, 0x0000c0, 0xffaa00, 0x008000, 0xffffff, 0x000000, 0xff0000, 0x112233],
            $this->deviceRoute($token)['c']
        );
    }

    public function testChangingColorsDoesNotChangeTheRouteVersion(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId, ['points' => $this->points()]);
        $token = $this->createToken($userId);
        $this->sendToWatch($userId, $routeId);
        // A new version would restart the watch's navigation at the first waypoint.
        $this->pdo->exec("UPDATE watch_link SET updated_at = '2026-01-01 12:00:00' WHERE user_id = " . $userId);
        $before = $this->deviceRoute($token)['v'];

        $this->putColors($userId, ['text' => ['dark' => '#00ff00']]);

        $this->assertSame($before, $this->deviceRoute($token)['v']);
    }

    public function testColorsCanBeSavedBeforeAWatchIsPairedAndReset(): void
    {
        $userId = $this->createUser();

        $this->putColors($userId, ['text' => ['light' => '#222222']]);
        $this->assertSame('#222222', $this->decode($this->controller->status($this->request('GET', '/api/v1/watch', $this->authPayload($userId)), $this->response()))['data']['colors']['text']['light']);

        $reset = $this->decode($this->controller->resetColors($this->request('DELETE', '/api/v1/watch/colors', $this->authPayload($userId)), $this->response()))['data'];
        $this->assertSame(\Ytan\Service\WatchColors::DEFAULTS, $reset['colors']);
    }

    public function testInvalidColorsAreRejected(): void
    {
        $userId = $this->createUser();

        foreach ([
            ['text' => ['dark' => 'red']],
            ['text' => ['dark' => '#fff']],
            ['text' => ['dark' => 123456]],
            ['text' => ['grey' => '#ffffff']],
            ['title' => ['dark' => '#ffffff']],
            'text',
        ] as $invalid) {
            try {
                $this->putColors($userId, $invalid);
                $this->fail('Accepted ' . json_encode($invalid));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStatusRequiresLogin(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->controller->status($this->request('GET', '/api/v1/watch'), $this->response());
    }
}
