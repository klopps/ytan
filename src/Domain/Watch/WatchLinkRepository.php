<?php

declare(strict_types=1);

namespace Ytan\Domain\Watch;

use PDO;

/**
 * watch_link: per-user pairing for the Garmin watch data field (watch/).
 * The device token itself is only stored as its SHA-256 hash (what the device
 * endpoint looks it up by) and, so the settings file can be offered again,
 * encrypted (token_enc, WatchKeyVault).
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
     * @return array|null the link row plus the owning user's is_admin flag and
     *                    default_speed_kmh
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT watch_link.*, user.is_admin, user.default_speed_kmh FROM watch_link JOIN user ON user.id = watch_link.user_id WHERE watch_link.token_hash = ?'
        );
        $stmt->execute([self::hashToken($token)]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Like findByToken(), but by user id (the signed-in app instead of the
     * device's key). Always a row for an existing user: a user without a
     * watch_link yet gets NULL link columns (user_id included).
     */
    public function findWithUserDataByUser(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT watch_link.route_id, watch_link.unit, watch_link.colors, watch_link.updated_at, user.id AS user_id, user.is_admin, user.default_speed_kmh FROM user LEFT JOIN watch_link ON watch_link.user_id = user.id WHERE user.id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Replaces any previous token - an old sideloaded build stops working.
     *
     * @param string|null $tokenEnc the token encrypted by WatchKeyVault, kept
     *                              so the watch's settings file can be offered
     *                              for download until the next new key
     */
    public function setToken(int $userId, string $token, ?string $tokenEnc = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO watch_link (user_id, token_hash, token_enc) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), token_enc = VALUES(token_enc)'
        );
        $stmt->execute([$userId, self::hashToken($token), $tokenEnc]);
    }

    public function clearToken(int $userId): void
    {
        $this->db->prepare('UPDATE watch_link SET token_hash = NULL, token_enc = NULL WHERE user_id = ?')->execute([$userId]);
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

    /**
     * Stores the colors JSON (NULL = defaults). updated_at is deliberately
     * kept as it is: it is part of the route version the watch compares, and
     * a color change must not restart its navigation.
     */
    public function setColors(int $userId, ?string $colorsJson): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO watch_link (user_id, colors) VALUES (?, ?) ON DUPLICATE KEY UPDATE colors = VALUES(colors), updated_at = updated_at'
        );
        $stmt->execute([$userId, $colorsJson]);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
