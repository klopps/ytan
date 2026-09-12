<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Database\Connection;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\AuthService;
use Ytan\Service\MailService;

/**
 * validatePasswordFormat() is pure logic (no DB reads/writes), but
 * AuthService's constructor needs a UserRepository (which needs a PDO) and
 * a MailService - constructing both is cheap (MailService only connects to
 * SMTP inside send(), never at construction) and tests/bootstrap.php has
 * already pointed the PDO at the dedicated test database, so this stays
 * safe to run alongside the DB-backed integration tests.
 */
final class AuthServicePasswordValidationTest extends TestCase
{
    private AuthService $auth;

    protected function setUp(): void
    {
        $users = new UserRepository(Connection::fromEnv());
        $mail = new MailService('', 587, '', '', 'test@example.test', '', 'YTAN Test');
        $this->auth = new AuthService($users, 'unit-test-secret', 3600, $mail, 'http://localhost');
    }

    public function testAcceptsAStrongPassword(): void
    {
        $this->auth->validatePasswordFormat('Str0ng!Pass');
        $this->addToAssertionCount(1); // no exception = pass
    }

    public function testRejectsAPasswordShorterThan8Characters(): void
    {
        $this->expectException(ValidationException::class);
        $this->auth->validatePasswordFormat('Sh0rt!');
    }

    public function testRejectionCarriesTheAuthPasswordPolicyErrorCode(): void
    {
        try {
            $this->auth->validatePasswordFormat('Sh0rt!');
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('auth.password_policy', $e->getErrorCode());
        }
    }

    /**
     * Needs at least 3 of: lowercase, uppercase, digit, symbol - all-lowercase
     * letters is only 1 class even at sufficient length.
     */
    public function testRejectsAPasswordWithTooFewCharacterClasses(): void
    {
        $this->expectException(ValidationException::class);
        $this->auth->validatePasswordFormat('onlylowercase');
    }

    public function testAcceptsExactlyThreeCharacterClasses(): void
    {
        $this->auth->validatePasswordFormat('lowerUPPER123');
        $this->addToAssertionCount(1);
    }
}
