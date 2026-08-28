<?php

declare(strict_types=1);

namespace Ytan\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly string $jwtSecret,
        private readonly int $ttlSeconds,
    ) {
    }

    /**
     * Validates credentials and returns [token, expiresAt, user] on success.
     * Mirrors the legacy behaviour of migrating a still-plaintext stored
     * password to a bcrypt hash on first successful login.
     */
    public function login(string $username, string $password): array
    {
        $user = $this->users->findByUsername($username);
        if ($user === null || $user['password'] === null) {
            throw new UnauthorizedException('Invalid username or password.');
        }

        if (password_verify($password, $user['password'])) {
            // already a proper hash
        } elseif (hash_equals((string) $user['password'], $password)) {
            $user['password'] = password_hash($password, PASSWORD_DEFAULT);
            $this->users->updatePassword((int) $user['id'], $user['password']);
        } else {
            throw new UnauthorizedException('Invalid username or password.');
        }

        unset($user['password']);

        return $this->issueToken($user);
    }

    /**
     * Validates a set-password token (from either the first-login invite
     * email or a forgot-password email - both share one token table), sets
     * the new password, consumes the token, and logs the user straight in.
     */
    public function setNewPassword(string $rawToken, string $newPassword): array
    {
        $tokenRow = $this->users->findValidToken($rawToken);
        if ($tokenRow === null) {
            throw new ValidationException('This link is invalid or has expired.');
        }

        $this->validatePasswordFormat($newPassword);

        $user = $this->users->findById((int) $tokenRow['user_id']);
        $this->users->updatePassword((int) $user['id'], password_hash($newPassword, PASSWORD_DEFAULT));
        $this->users->consumeToken($rawToken);

        unset($user['password']);

        return $this->issueToken($user);
    }

    /**
     * Self-service password change for a logged-in user - requires proof of
     * the current password, unlike adminSetPassword() below.
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->findById($userId);
        if ($user === null || $user['password'] === null || !password_verify($currentPassword, $user['password'])) {
            throw new UnauthorizedException('Current password is incorrect.');
        }

        $this->validatePasswordFormat($newPassword);
        $this->users->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
    }

    /**
     * Admin override - sets a user's password directly, without requiring
     * (or even knowing) their current one.
     */
    public function adminSetPassword(int $userId, string $newPassword): void
    {
        $this->validatePasswordFormat($newPassword);
        $this->users->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
    }

    private function issueToken(array $user): array
    {
        $expiresAt = time() + $this->ttlSeconds;
        $token = JWT::encode([
            'sub' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'firstname' => $user['firstname'],
            'lastname' => $user['lastname'],
            'is_admin' => (bool) $user['is_admin'],
            'iat' => time(),
            'exp' => $expiresAt,
        ], $this->jwtSecret, 'HS256');

        return ['token' => $token, 'expires_at' => $expiresAt, 'user' => $user];
    }

    /**
     * Returns the decoded token payload as an assoc array, or null if no/invalid token.
     */
    public function verifyToken(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        } catch (\Throwable) {
            return null;
        }

        return (array) $decoded;
    }

    /**
     * Same password strength rule as the legacy app: at least 8 characters,
     * covering at least 3 of the 4 character classes (lower/upper/digit/other).
     */
    public function validatePasswordFormat(string $password): void
    {
        $classes = 0;
        $classes += preg_match('/[0-9]/', $password);
        $classes += preg_match('/[A-ZÄÖÜ]/', $password);
        $classes += preg_match('/[a-zäöü]/', $password);
        $classes += preg_match('/[^0-9A-Za-zÄÖÜäöü]/', $password);

        if (strlen($password) < 8 || $classes < 3) {
            throw new ValidationException(
                'Password must be at least 8 characters and use at least 3 of: lowercase, uppercase, digits, symbols.'
            );
        }
    }
}
