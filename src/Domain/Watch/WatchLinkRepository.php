<?php

declare(strict_types=1);

namespace Ytan\Domain\Watch;

use PDO;

/**
 * watch_link: per-user pairing for the Garmin watch data field (watch/).
 * The device token itself is never stored, only its SHA-256 hash.
 */
final class WatchLinkRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findByUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM watch_link WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array|null the link row plus the owning user's is_admin flag
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT watch_link.*, user.is_admin FROM watch_link JOIN user ON user.id = watch_link.user_id WHERE watch_link.token_hash = ?'
        );
        $stmt->execute([self::hashToken($token)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Replaces any previous token - an old sideloaded build stops working.
     */
    public function setToken(int $userId, string $token): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO watch_link (user_id, token_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash)'
        );
        $stmt->execute([$userId, self::hashToken($token)]);
    }

    public function clearToken(int $userId): void
    {
        $this->db->prepare('UPDATE watch_link SET token_hash = NULL WHERE user_id = ?')->execute([$userId]);
    }

    public function setRoute(int $userId, int $routeId, string $unit): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO watch_link (user_id, route_id, unit) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE route_id = VALUES(route_id), unit = VALUES(unit)'
        );
        $stmt->execute([$userId, $routeId, $unit]);
    }

    public function clearRoute(int $userId): void
    {
        $this->db->prepare('UPDATE watch_link SET route_id = NULL WHERE user_id = ?')->execute([$userId]);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
