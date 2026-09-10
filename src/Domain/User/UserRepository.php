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

    private const TOUR_RIGHT_COLUMNS = ['is_admin', 'tour_create', 'tour_publish', 'tour_manage', 'tour_copy'];

    /**
     * @param array<string,mixed> $filters optional exact-match filters, one
     *        key per column in TOUR_RIGHT_COLUMNS (e.g. ['tour_manage' => 1])
     *        - the admin user list's "filter by right" controls.
     * @return array<int, array<string, mixed>> all users, password hashes excluded
     */
    public function findAll(array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->buildRightFilterWhere($filters);

        $sql = 'SELECT id, username, email, firstname, lastname, is_admin, tour_create, tour_publish, tour_manage, tour_copy FROM user';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY username' . $this->limitSuffix($limit, $offset);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $filters same shape as findAll()'s
     */
    public function countAll(array $filters = []): int
    {
        [$where, $params] = $this->buildRightFilterWhere($filters);

        $sql = 'SELECT COUNT(*) FROM user';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{0: string[], 1: array<int,mixed>}
     */
    private function buildRightFilterWhere(array $filters): array
    {
        $where = [];
        $params = [];
        foreach (self::TOUR_RIGHT_COLUMNS as $column) {
            if (isset($filters[$column])) {
                $where[] = "$column = ?";
                $params[] = (int) $filters[$column];
            }
        }

        return [$where, $params];
    }

    private function limitSuffix(?int $limit, int $offset): string
    {
        return $limit !== null ? ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset) : '';
    }

    public function create(array $data): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user (username, email, firstname, lastname, is_admin, tour_create, tour_publish, tour_manage, tour_copy, password)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );

        try {
            $stmt->execute([
                $data['username'],
                $data['email'],
                $data['firstname'] ?? null,
                $data['lastname'] ?? null,
                (int) ($data['is_admin'] ?? 0),
                (int) ($data['tour_create'] ?? 0),
                (int) ($data['tour_publish'] ?? 0),
                (int) ($data['tour_manage'] ?? 0),
                (int) ($data['tour_copy'] ?? 0),
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException('Username or email already in use.');
            }
            throw $e;
        }

        return $this->findById((int) $this->db->lastInsertId());
    }

    /**
     * Merges $data onto the existing row before writing, so a caller that
     * only sends e.g. {tour_manage: 1} (any partial payload - not just the
     * admin panel's full form, which always sends every field anyway)
     * doesn't blank out firstname/lastname/etc. it didn't mean to touch.
     */
    public function update(int $id, array $data): array
    {
        $existing = $this->findById($id) ?? [];
        $merged = array_merge($existing, $data);

        $stmt = $this->db->prepare(
            'UPDATE user SET username=?, email=?, firstname=?, lastname=?, is_admin=?, tour_create=?, tour_publish=?, tour_manage=?, tour_copy=? WHERE id=?'
        );

        try {
            $stmt->execute([
                $merged['username'],
                $merged['email'],
                $merged['firstname'] ?? null,
                $merged['lastname'] ?? null,
                (int) ($merged['is_admin'] ?? 0),
                (int) ($merged['tour_create'] ?? 0),
                (int) ($merged['tour_publish'] ?? 0),
                (int) ($merged['tour_manage'] ?? 0),
                (int) ($merged['tour_copy'] ?? 0),
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
     * Self-service update of a user's own display name - deliberately
     * separate from update() above, which also lets an admin change
     * username/email/is_admin for an arbitrary user.
     */
    public function updateProfile(int $id, string $firstname, string $lastname): array
    {
        $this->db->prepare('UPDATE user SET firstname=?, lastname=? WHERE id=?')
            ->execute([$firstname, $lastname, $id]);

        return $this->findById($id);
    }

    public function updateEmail(int $id, string $email): array
    {
        try {
            $this->db->prepare('UPDATE user SET email=? WHERE id=?')->execute([$email, $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException('This email address is already in use.');
            }
            throw $e;
        }

        return $this->findById($id);
    }

    /**
     * Creates a fresh single-use token for the given purpose, invalidating
     * any prior unused token of the same purpose for that user first.
     * $payload carries purpose-specific data alongside the token itself -
     * e.g. the pending new email address for an 'email_change' token.
     */
    public function createToken(int $userId, string $purpose, int $ttlSeconds, ?string $payload = null): string
    {
        $this->deletePendingTokens($userId, $purpose);

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        $this->db->prepare(
            'INSERT INTO user_token (user_id, token, purpose, payload, expires_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $token, $purpose, $payload, $expiresAt]);

        return $token;
    }

    /**
     * Discards any unused token of the given purpose for that user, e.g. to
     * cancel a pending email change or before issuing a replacement token.
     */
    public function deletePendingTokens(int $userId, string $purpose): void
    {
        $this->db->prepare(
            'DELETE FROM user_token WHERE user_id = ? AND purpose = ? AND used_at IS NULL'
        )->execute([$userId, $purpose]);
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

    /**
     * The currently pending 'email_change' token for a user, if any - used
     * to show a persistent "confirmation pending" hint in the Preferences
     * screen even after a page reload.
     */
    public function findActivePendingEmailChange(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM user_token WHERE user_id = ? AND purpose = 'email_change'
             AND used_at IS NULL AND expires_at > NOW() LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function consumeToken(string $token): void
    {
        $this->db->prepare('UPDATE user_token SET used_at = NOW() WHERE token = ?')->execute([$token]);
    }
}
