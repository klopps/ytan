<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\User\UserRepository;
use Ytan\Service\AuthService;
use Ytan\Service\MailService;
use Ytan\Tests\TestCase;

/**
 * AuthService::authenticate() is what AuthMiddleware uses: the JWT's user
 * claims are minted at login, so it must overlay the CURRENT database values
 * (rights, name) - and reject a user who no longer exists.
 */
final class AuthServiceAuthenticateTest extends TestCase
{
    private AuthService $authService;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->pdo);
        $mail = new MailService('unreachable.invalid', 587, '', '', 'from@example.test', '', 'YTAN Test');
        $this->authService = new AuthService($this->users, 'unit-test-secret-at-least-32-bytes-long', 3600, $mail, 'http://localhost');
    }

    private function tokenFor(int $userId): string
    {
        $user = $this->users->findById($userId);
        $this->users->updatePassword($userId, password_hash('Correct-Horse-1', PASSWORD_DEFAULT));

        return $this->authService->login($user['username'], 'Correct-Horse-1')['token'];
    }

    public function testAuthenticateReflectsRightsAndNameChangedSinceTheTokenWasIssued(): void
    {
        $userId = $this->createUser(['firstname' => 'Old', 'is_admin' => 1, 'tour_create' => 0]);
        $token = $this->tokenFor($userId);

        $this->pdo->prepare('UPDATE user SET firstname = ?, is_admin = 0, tour_create = 1 WHERE id = ?')
            ->execute(['New', $userId]);

        $claims = $this->authService->authenticate($token);

        $this->assertSame($userId, $claims['sub']);
        $this->assertSame('New', $claims['firstname']);
        $this->assertFalse($claims['is_admin']);
        $this->assertTrue($claims['tour_create']);
        $this->assertArrayHasKey('exp', $claims);
    }

    public function testAuthenticateReturnsNullForADeletedUser(): void
    {
        $userId = $this->createUser();
        $token = $this->tokenFor($userId);
        $this->users->delete($userId);

        $this->assertNull($this->authService->authenticate($token));
    }

    public function testAuthenticateReturnsNullForGarbageOrMissingToken(): void
    {
        $this->assertNull($this->authService->authenticate('not-a-jwt'));
        $this->assertNull($this->authService->authenticate(null));
    }
}
