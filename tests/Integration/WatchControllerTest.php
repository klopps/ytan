<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Watch\WatchLinkRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Exception\NotFoundException;
use Ytan\Http\Controllers\WatchController;
use Ytan\Service\WatchKeyVault;
use Ytan\Service\WatchSettingsFile;

final class WatchControllerTest extends ControllerTestCase
{
    private WatchController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new WatchController(new WatchLinkRepository($this->pdo), new RouteRepository($this->pdo), new WatchKeyVault('test-secret'));
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

    private function appPayload(int $userId, array $authOverrides = []): array
    {
        $response = $this->controller->payload($this->request('GET', '/api/v1/watch/payload', $this->authPayload($userId, $authOverrides)), $this->response());

        return json_decode((string) $response->getBody(), true);
    }

    public function testAppPayloadNeedsNoKeyAndEqualsWhatTheKeyServes(): void
    {
        $userId = $this->createUser();
        $routeId = $this->createRoute($userId, ['name' => 'Schlei', 'points' => $this->points()]);
        $this->sendToWatch($userId, $routeId, 'nautical');
        $this->pdo->exec('UPDATE user SET default_speed_kmh = 7.2 WHERE id = ' . $userId);
        $token = $this->createToken($userId);

        $fromApp = $this->appPayload($userId);

        // Same "v": the watch must not restart its navigation when the app's
        // message and its own key-based fetch both deliver.
        $this->assertSame($this->deviceRoute($token), $fromApp);
        $this->assertSame('Schlei', $fromApp['n']);
        $this->assertSame('n', $fromApp['u']);
        $this->assertSame([5450000, 1030000, 5460000, 1040000], $fromApp['p']);
        $this->assertEquals(2.0, $fromApp['s']);
    }

    public function testAppPayloadWithoutAnyWatchSettingsIsEmptyWithDefaultColors(): void
    {
        $userId = $this->createUser();

        $payload = $this->appPayload($userId);

        $this->assertSame('none', $payload['v']);
        $this->assertSame([], $payload['p']);
        $this->assertSame(\Ytan\Service\WatchColors::flatten(\Ytan\Service\WatchColors::effective(null)), $payload['c']);
        $this->assertArrayNotHasKey('s', $payload);
    }

    public function testAppPayloadHidesARouteMadePrivateLater(): void
    {
        $owner = $this->createUser();
        $userId = $this->createUser();
        $routeId = $this->createRoute($owner, ['public' => 1, 'points' => $this->points()]);
        $this->sendToWatch($userId, $routeId);
        $this->assertNotSame([], $this->appPayload($userId)['p']);

        $this->pdo->exec('UPDATE route SET public = 0 WHERE id = ' . $routeId);

        $this->assertSame([], $this->appPayload($userId)['p']);
    }

    public function testAppPayloadRequiresLogin(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->controller->payload($this->request('GET', '/api/v1/watch/payload'), $this->response());
    }

    private function settingsFile(int $userId): \Psr\Http\Message\ResponseInterface
    {
        return $this->controller->settingsFile($this->request('GET', '/api/v1/watch/settings-file', $this->authPayload($userId)), $this->response());
    }

    public function testSettingsFileHoldsTheCurrentKeyUntilANewOneIsCreated(): void
    {
        $userId = $this->createUser();
        $first = $this->createToken($userId);

        $response = $this->settingsFile($userId);
        $this->assertSame('attachment; filename="ytan-ReplaceByWatchName.SET"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame(WatchSettingsFile::build($first), (string) $response->getBody());

        // Still there on the next request, and the status says so.
        $this->assertSame(WatchSettingsFile::build($first), (string) $this->settingsFile($userId)->getBody());
        $status = $this->decode($this->controller->status($this->request('GET', '/api/v1/watch', $this->authPayload($userId)), $this->response()))['data'];
        $this->assertTrue($status['has_settings_file']);

        $second = $this->createToken($userId);
        $this->assertNotSame($first, $second);
        $this->assertSame(WatchSettingsFile::build($second), (string) $this->settingsFile($userId)->getBody());
    }

    public function testSettingsFileIsStoredEncryptedNotAsTheKey(): void
    {
        $userId = $this->createUser();
        $token = $this->createToken($userId);

        $stored = (string) $this->pdo->query('SELECT token_enc FROM watch_link WHERE user_id = ' . $userId)->fetchColumn();

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($token, $stored);
        $this->assertSame($token, (new WatchKeyVault('test-secret'))->decrypt($stored));
    }

    public function testSettingsFileIsGoneAfterUnpairing(): void
    {
        $userId = $this->createUser();
        $this->createToken($userId);
        $this->controller->deleteToken($this->request('DELETE', '/api/v1/watch/token', $this->authPayload($userId)), $this->response());

        $this->expectException(NotFoundException::class);
        $this->settingsFile($userId);
    }

    public function testSettingsFileUnavailableForAKeyWithoutAnEncryptedCopy(): void
    {
        $userId = $this->createUser();
        $this->createToken($userId);
        // A key from before migration 030.
        $this->pdo->exec('UPDATE watch_link SET token_enc = NULL WHERE user_id = ' . $userId);

        $status = $this->decode($this->controller->status($this->request('GET', '/api/v1/watch', $this->authPayload($userId)), $this->response()))['data'];
        $this->assertTrue($status['has_token']);
        $this->assertFalse($status['has_settings_file']);

        $this->expectException(NotFoundException::class);
        $this->settingsFile($userId);
    }

    public function testSettingsFileWithoutAnyKeyIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->settingsFile($this->createUser());
    }

    public function testSettingsFileRequiresLogin(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->controller->settingsFile($this->request('GET', '/api/v1/watch/settings-file'), $this->response());
    }

    public function testStatusRequiresLogin(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->controller->status($this->request('GET', '/api/v1/watch'), $this->response());
    }
}
