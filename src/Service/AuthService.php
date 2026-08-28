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

        $expiresAt = time() + $this->ttlSeconds;
        $token = JWT::encode([
            'sub' => (int) $user['id'],
            'username' => $user['username'],
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
