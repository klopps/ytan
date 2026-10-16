<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Firebase\JWT\JWT;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\TooManyRequestsException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\AuthController;
use Ytan\Service\AuthService;
use Ytan\Service\RequestThrottle;
use Ytan\Service\MailService;

/**
 * Security audit 2026-10-16: revoking JWTs (token_version / "tv" claim),
 * brute-force throttling of login and forgot-password (RequestThrottle), the
 * legacy plaintext-password branch, set-password token purpose and hashed
 * user_token storage.
 */
final class AuthSecurityTest extends ControllerTestCase
{
    private const SECRET = 'unit-test-secret-at-least-32-bytes-long';
    private const PASSWORD = 'Correct-Horse-1';

    private UserRepository $users;
    private AuthService $auth;
    private AuthController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $mail = new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test');
        $this->auth = new AuthService($this->users, self::SECRET, 3600, $mail, 'http://localhost');
        $this->controller = new AuthController($this->auth, $this->users, $mail, 'http://localhost', new RequestThrottle($this->pdo));
    }

    private function userWithPassword(): array
    {
        $id = $this->createUser();
        $this->users->updatePassword($id, password_hash(self::PASSWORD, PASSWORD_DEFAULT));

        return $this->users->findById($id);
    }

    /* ------------------------------------------------------ Revocation */

    public function testChangePasswordRevokesOldTokensAndReturnsAWorkingNewOne(): void
    {
        $user = $this->userWithPassword();
        $old = $this->auth->login($user['username'], self::PASSWORD)['token'];

        $issued = $this->auth->changePassword((int) $user['id'], self::PASSWORD, 'New-Horse-22');

        $this->assertNull($this->auth->authenticate($old));
        $this->assertSame((int) $user['id'], $this->auth->authenticate($issued['token'])['sub']);
    }

    public function testLogoutEverywhereRevokesOldTokens(): void
    {
        $user = $this->userWithPassword();
        $old = $this->auth->login($user['username'], self::PASSWORD)['token'];

        $fresh = $this->auth->logoutEverywhere((int) $user['id'])['token'];

        $this->assertNull($this->auth->authenticate($old));
        $this->assertNotNull($this->auth->authenticate($fresh));
    }

    public function testAdminSetPasswordRevokesTheUsersTokens(): void
    {
        $user = $this->userWithPassword();
        $old = $this->auth->login($user['username'], self::PASSWORD)['token'];

        $this->auth->adminSetPassword((int) $user['id'], 'Admin-Set-33');

        $this->assertNull($this->auth->authenticate($old));
    }

    public function testPasswordResetRevokesOldTokens(): void
    {
        $user = $this->userWithPassword();
        $old = $this->auth->login($user['username'], self::PASSWORD)['token'];
        $resetToken = $this->users->createToken((int) $user['id'], 'reset', 3600);

        $issued = $this->auth->setNewPassword($resetToken, 'Reset-Horse-44');

        $this->assertNull($this->auth->authenticate($old));
        $this->assertNotNull($this->auth->authenticate($issued['token']));
    }

    public function testTokenWithoutTvClaimStaysValidUntilTheFirstRevocation(): void
    {
        $user = $this->userWithPassword();
        // Shape of a token issued before migration 039 (no "tv").
        $legacy = JWT::encode(['sub' => (int) $user['id'], 'iat' => time(), 'exp' => time() + 3600], self::SECRET, 'HS256');

        $this->assertNotNull($this->auth->authenticate($legacy));

        $this->users->bumpTokenVersion((int) $user['id']);
        $this->assertNull($this->auth->authenticate($legacy));
    }

    /* ----------------------------------------------------- Legacy login */

    public function testStoredHashIsNotAcceptedAsPassword(): void
    {
        $user = $this->userWithPassword();

        $this->expectException(UnauthorizedException::class);
        $this->auth->login($user['username'], (string) $user['password']);
    }

    public function testLegacyPlaintextPasswordStillWorksAndIsMigratedToAHash(): void
    {
        $id = $this->createUser();
        $this->users->updatePassword($id, 'Plain-Old-55');
        $user = $this->users->findById($id);

        $this->auth->login($user['username'], 'Plain-Old-55');

        $this->assertTrue(password_verify('Plain-Old-55', (string) $this->users->findById($id)['password']));
    }

    /* ------------------------------------------------- user_token table */

    public function testEmailChangeTokenCannotSetThePassword(): void
    {
        $user = $this->userWithPassword();
        $token = $this->users->createToken((int) $user['id'], 'email_change', 3600, 'new@example.test');

        $this->expectException(ValidationException::class);
        $this->auth->setNewPassword($token, 'Other-Horse-66');
    }

    public function testTokensAreStoredHashed(): void
    {
        $user = $this->userWithPassword();
        $token = $this->users->createToken((int) $user['id'], 'reset', 3600);

        $stored = $this->pdo->query('SELECT token FROM user_token WHERE user_id = ' . (int) $user['id'])->fetchColumn();

        $this->assertSame(hash('sha256', $token), $stored);
        $this->assertNotNull($this->users->findValidToken($token));
        $this->assertNull($this->users->findValidToken($stored), 'The stored hash itself must not work as a link token.');
    }

    /* ---------------------------------------------------------- Throttle */

    public function testLoginIsThrottledAfterTenFailures(): void
    {
        $user = $this->userWithPassword();
        $request = fn (string $password) => $this->request('POST', '/auth/login', null, ['username' => $user['username'], 'password' => $password]);

        for ($i = 0; $i < 10; $i++) {
            try {
                $this->controller->login($request('wrong'), $this->response());
                $this->fail('Wrong password must not log in.');
            } catch (UnauthorizedException) {
                $this->addToAssertionCount(1);
            }
        }

        // Even the right password is refused now.
        $this->expectException(TooManyRequestsException::class);
        $this->controller->login($request(self::PASSWORD), $this->response());
    }

    public function testSuccessfulLoginClearsEarlierFailures(): void
    {
        $user = $this->userWithPassword();
        $throttle = new RequestThrottle($this->pdo);
        for ($i = 0; $i < 9; $i++) {
            $throttle->record(RequestThrottle::LOGIN, $user['username'], '203.0.113.1');
        }

        $this->controller->login(
            $this->request('POST', '/auth/login', null, ['username' => $user['username'], 'password' => self::PASSWORD]),
            $this->response()
        );

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM auth_attempt WHERE kind = 'login'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testForgotPasswordIsThrottledPerIdentifierEvenForUnknownAccounts(): void
    {
        $request = fn () => $this->request('POST', '/auth/forgot-password', null, ['identifier' => 'nobody@example.test']);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->controller->forgotPassword($request(), $this->response())->getStatusCode());
        }

        $this->expectException(TooManyRequestsException::class);
        $this->controller->forgotPassword($request(), $this->response());
    }

    public function testThrottleWindowSlides(): void
    {
        $throttle = new RequestThrottle($this->pdo);
        $old = date('Y-m-d H:i:s', time() - 3700);
        $stmt = $this->pdo->prepare("INSERT INTO auth_attempt (kind, identifier, ip, created_at) VALUES ('forgot', 'x@example.test', '203.0.113.2', ?)");
        for ($i = 0; $i < 3; $i++) {
            $stmt->execute([$old]);
        }

        $throttle->assertAllowed(RequestThrottle::FORGOT, 'X@example.test', '203.0.113.2');
        $this->addToAssertionCount(1);
    }
}
