<?php

declare(strict_types=1);

namespace Ytan\Domain\User;

use PDO;
use PDOException;
use Ytan\Exception\ValidationException;

final class UserRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM user WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM user WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM user WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    /**
     * @return array<int, array<string, mixed>> all users, password hashes excluded
     */
    public function findAll(): array
    {
        return $this->db->query(
            'SELECT id, username, email, firstname, lastname, is_admin FROM user ORDER BY username'
        )->fetchAll();
    }

    public function create(array $data): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user (username, email, firstname, lastname, is_admin, password)
             VALUES (?, ?, ?, ?, ?, NULL)'
        );

        try {
            $stmt->execute([
                $data['username'],
                $data['email'],
                $data['firstname'] ?? null,
                $data['lastname'] ?? null,
                (int) ($data['is_admin'] ?? 0),
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException('Username or email already in use.');
            }
            throw $e;
        }

        return $this->findById((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $data): array
    {
        $stmt = $this->db->prepare(
            'UPDATE user SET username=?, email=?, firstname=?, lastname=?, is_admin=? WHERE id=?'
        );

        try {
            $stmt->execute([
                $data['username'],
                $data['email'],
                $data['firstname'] ?? null,
                $data['lastname'] ?? null,
                (int) ($data['is_admin'] ?? 0),
                $id,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException('Username or email already in use.');
            }
            throw $e;
        }

        return $this->findById($id);
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM user WHERE id = ?')->execute([$id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $stmt = $this->db->prepare('UPDATE user SET password = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
    }

    /**
     * Creates a fresh single-use token for the given purpose, invalidating
     * any prior unused token of the same purpose for that user first.
     */
    public function createToken(int $userId, string $purpose, int $ttlSeconds): string
    {
        $this->db->prepare(
            'DELETE FROM user_token WHERE user_id = ? AND purpose = ? AND used_at IS NULL'
        )->execute([$userId, $purpose]);

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        $this->db->prepare(
            'INSERT INTO user_token (user_id, token, purpose, expires_at) VALUES (?, ?, ?, ?)'
        )->execute([$userId, $token, $purpose, $expiresAt]);

        return $token;
    }

    public function findValidToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM user_token WHERE token = ? AND used_at IS NULL AND expires_at > NOW()'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function consumeToken(string $token): void
    {
        $this->db->prepare('UPDATE user_token SET used_at = NOW() WHERE token = ?')->execute([$token]);
    }
}
