<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\User\UserRepository;
use Ytan\Domain\Watch\WatchLinkRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\AuthController;
use Ytan\Http\Controllers\WatchController;
use Ytan\Service\AuthService;
use Ytan\Service\MailService;
use Ytan\Service\WatchKeyVault;

/**
 * The user's default paddling speed (profile) and how it reaches the watch
 * as "s" in the device payload.
 */
final class DefaultSpeedTest extends ControllerTestCase
{
    private AuthController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $users = new UserRepository($this->pdo);
        $mail = new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test');
        $this->controller = new AuthController(new AuthService($users, 'unit-test-secret', 3600, $mail, 'http://localhost'), $users, $mail, 'http://localhost');
    }

    private function put(int $userId, mixed $value): array
    {
        $request = $this->request('PUT', '/api/v1/auth/default-speed', $this->authPayload($userId), ['default_speed_kmh' => $value]);

        return $this->decode($this->controller->setDefaultSpeed($request, $this->response()));
    }

    private function get(int $userId): ?float
    {
        $response = $this->controller->defaultSpeed($this->request('GET', '/api/v1/auth/default-speed', $this->authPayload($userId)), $this->response());

        return $this->decode($response)['data']['default_speed_kmh'];
    }

    public function testNothingIsSetByDefault(): void
    {
        $this->assertNull($this->get($this->createUser()));
    }

    public function testSpeedIsStoredWithOneDecimal(): void
    {
        $userId = $this->createUser();

        $this->assertSame(5.6, $this->put($userId, 5.556)['data']['default_speed_kmh']);
        $this->assertSame(5.6, $this->get($userId));
        $this->assertEquals(4.0, $this->put($userId, '4')['data']['default_speed_kmh']);
    }

    public function testEmptyZeroOrNullClearsIt(): void
    {
        $userId = $this->createUser();

        foreach ([null, '', 0, '0'] as $clear) {
            $this->put($userId, 6.0);
            $this->assertNull($this->put($userId, $clear)['data']['default_speed_kmh']);
            $this->assertNull($this->get($userId));
        }
    }

    public function testOutOfRangeOrNonNumericSpeedIsRejected(): void
    {
        $userId = $this->createUser();

        foreach ([0.4, 31, -3, 'fast', [5]] as $invalid) {
            try {
                $this->put($userId, $invalid);
                $this->fail('Accepted ' . json_encode($invalid));
            } catch (ValidationException $e) {
                $this->assertSame('auth.default_speed_invalid', $e->getErrorCode());
            }
        }
    }

    public function testRequiresLogin(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->controller->defaultSpeed($this->request('GET', '/api/v1/auth/default-speed'), $this->response());
    }

    public function testWatchGetsTheSpeedInMetersPerSecondOnlyWhenSet(): void
    {
        $userId = $this->createUser();
        $watch = new WatchController(new WatchLinkRepository($this->pdo), new RouteRepository($this->pdo), new WatchKeyVault('test-secret'));
        $token = $this->decode($watch->createToken($this->request('POST', '/api/v1/watch/token', $this->authPayload($userId)), $this->response()))['data']['token'];
        $device = fn () => json_decode((string) $watch->deviceRoute($this->request('GET', '/api/v1/watch/device/route')->withHeader('X-Watch-Token', $token), $this->response())->getBody(), true);

        $this->assertArrayNotHasKey('s', $device());

        $this->put($userId, 5.4);
        $this->assertSame(1.5, $device()['s']);

        $this->put($userId, null);
        $this->assertArrayNotHasKey('s', $device());
    }
}
