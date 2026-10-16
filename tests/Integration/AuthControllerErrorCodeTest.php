<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\User\UserRepository;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\AuthController;
use Ytan\Service\AuthService;
use Ytan\Service\MailService;

/**
 * Covers the machine-readable error codes added to AuthController/
 * AuthService's highest-traffic throw sites (login, password reset/set,
 * form validation - Phase 4 of the i18n effort, see
 * resources/i18n/{en,de}.json's "error.*" keys) - these propagate through
 * src/App.php's error handler as the JSON error body's "code" field, which
 * i18n.js's translateApiError() then looks up. Only the codes reachable via
 * AuthController itself are covered here; AuthServicePasswordValidationTest
 * already covers validatePasswordFormat()'s own code directly.
 */
final class AuthControllerErrorCodeTest extends ControllerTestCase
{
    private AuthController $controller;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $mail = new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test');
        $authService = new AuthService($this->users, 'unit-test-secret-at-least-32-bytes-long', 3600, $mail, 'http://localhost');
        $this->controller = new AuthController($authService, $this->users, $mail, 'http://localhost', new \Ytan\Service\RequestThrottle($this->pdo));
    }

    public function testLoginWithAWrongPasswordCarriesTheInvalidCredentialsCode(): void
    {
        $userId = $this->createUser();
        $this->users->updatePassword($userId, password_hash('Correct-Horse-1', PASSWORD_DEFAULT));
        $user = $this->users->findById($userId);

        try {
            $this->controller->login(
                $this->request('POST', '/auth/login', null, [
                    'username' => $user['username'],
                    'password' => 'wrong-password',
                ]),
                $this->response()
            );
            $this->fail('Expected UnauthorizedException.');
        } catch (UnauthorizedException $e) {
            $this->assertSame('auth.invalid_credentials', $e->getErrorCode());
        }
    }

    public function testLoginWithAnUnknownUsernameCarriesTheInvalidCredentialsCode(): void
    {
        try {
            $this->controller->login(
                $this->request('POST', '/auth/login', null, [
                    'username' => 'no-such-user-' . bin2hex(random_bytes(4)),
                    'password' => 'whatever',
                ]),
                $this->response()
            );
            $this->fail('Expected UnauthorizedException.');
        } catch (UnauthorizedException $e) {
            $this->assertSame('auth.invalid_credentials', $e->getErrorCode());
        }
    }

    public function testLoginWithAMissingPasswordCarriesTheValidationCode(): void
    {
        try {
            $this->controller->login(
                $this->request('POST', '/auth/login', null, ['username' => 'someone']),
                $this->response()
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('auth.username_password_required', $e->getErrorCode());
        }
    }

    public function testSetPasswordWithAnInvalidTokenCarriesTheLinkExpiredCode(): void
    {
        try {
            $this->controller->setPassword(
                $this->request('POST', '/auth/set-password', null, [
                    'token' => 'not-a-real-token',
                    'password' => 'Str0ng!Pass',
                ]),
                $this->response()
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('auth.link_expired', $e->getErrorCode());
        }
    }

    public function testMeReturnsTheCurrentDatabaseValuesNotTheStaleTokenClaims(): void
    {
        $userId = $this->createUser(['firstname' => 'Old', 'lastname' => 'Name', 'tour_create' => 0]);
        $staleClaims = $this->authPayload($userId);

        $this->pdo->prepare('UPDATE user SET firstname = ?, lastname = ?, tour_create = 1 WHERE id = ?')
            ->execute(['New', 'Person', $userId]);

        $data = $this->decode(
            $this->controller->me($this->request('GET', '/auth/me', $staleClaims), $this->response())
        )['data'];

        $this->assertSame($userId, $data['sub']);
        $this->assertSame('New', $data['firstname']);
        $this->assertSame('Person', $data['lastname']);
        $this->assertTrue($data['tour_create']);
        $this->assertFalse($data['is_admin']);
        $this->assertArrayHasKey('pending_email', $data);
    }

    public function testMeForADeletedUserIsUnauthorized(): void
    {
        $userId = $this->createUser();
        $claims = $this->authPayload($userId);
        $this->users->delete($userId);

        $this->expectException(UnauthorizedException::class);
        $this->controller->me($this->request('GET', '/auth/me', $claims), $this->response());
    }
}
