<?php

declare(strict_types=1);

namespace Ytan\Domain\Route;

use PDO;
use Ytan\Exception\NotFoundException;

final class RouteRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Every read method below joins in the recording user's username as
     * recorded_by_username - cheap (indexed FK), and needed so
     * RouteController can show "recorded by <username>" to viewers with
     * the route_view_recording right (it redacts this column, along with
     * recorded_at/recording_duration_seconds, for everyone else). SELECT *
     * would pull in unrelated/sensitive `user` columns (e.g. the password
     * hash) once joined, so every query below explicitly lists route.*
     * instead.
     */
    private const SELECT_WITH_RECORDER = 'SELECT route.*, user.username AS recorded_by_username FROM route LEFT JOIN user ON user.id = route.user_id';

    public function findPublic(?int $limit = null, int $offset = 0): array
    {
        return $this->db->query(self::SELECT_WITH_RECORDER . ' WHERE route.public = 1 ORDER BY route.id' . $this->limitSuffix($limit, $offset))->fetchAll();
    }

    public function countPublic(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM route WHERE public = 1')->fetchColumn();
    }

    public function findByUser(int $userId, ?int $limit = null, int $offset = 0): array
    {
        $stmt = $this->db->prepare(self::SELECT_WITH_RECORDER . ' WHERE route.user_id = ? ORDER BY route.id' . $this->limitSuffix($limit, $offset));
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM route WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function findByUserWithPublic(int $userId, ?int $limit = null, int $offset = 0): array
    {
        $stmt = $this->db->prepare(self::SELECT_WITH_RECORDER . ' WHERE route.user_id = ? OR route.public = 1 ORDER BY route.id' . $this->limitSuffix($limit, $offset));
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function countByUserWithPublic(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM route WHERE user_id = ? OR public = 1');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    private function limitSuffix(?int $limit, int $offset): string
    {
        return $limit !== null ? ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset) : '';
    }

    public function findByTour(int $tourId): array
    {
        $stmt = $this->db->prepare(
            'SELECT route.*, user.username AS recorded_by_username FROM tour_route
             LEFT JOIN route ON route.id = tour_route.route_id
             LEFT JOIN user ON user.id = route.user_id
             WHERE tour_route.tour_id = ?
             ORDER BY tour_route.sort_order'
        );
        $stmt->execute([$tourId]);

        return $stmt->fetchAll();
    }

    public function findById(int $id): array
    {
        $stmt = $this->db->prepare(self::SELECT_WITH_RECORDER . ' WHERE route.id = ?');
        $stmt->execute([$id]);
        $route = $stmt->fetch();

        if ($route === false) {
            throw new NotFoundException("Route $id not found.");
        }

        return $route;
    }

    public function create(int $userId, array $data): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO route (user_id, name, description, public, length, points, color, recorded_at, recording_duration_seconds)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['name'],
            $data['description'] ?? null,
            (int) ($data['public'] ?? 0),
            $data['length'] ?? null,
            $data['points'] ?? null,
            $data['color'] ?? '#BF409F',
            $data['recorded_at'] ?? null,
            $data['recording_duration_seconds'] ?? null,
        ]);

        return $this->findById((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $data): array
    {
        $this->findById($id); // 404s if missing

        // recorded_at/recording_duration_seconds are deliberately NOT
        // updatable here - they're write-once-at-creation facts about a
        // GPS-recorded route (see RouteController/track-recorder.js), and
        // saveRoute()'s edit form never resends them, so including them in
        // this SET clause would silently null them out on every routine
        // rename/re-describe of an already-recorded route.
        $stmt = $this->db->prepare(
            'UPDATE route SET name=?, description=?, public=?, length=?, points=?, color=? WHERE id=?'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            (int) ($data['public'] ?? 0),
            $data['length'] ?? null,
            $data['points'] ?? null,
            $data['color'] ?? '#BF409F',
            $id,
        ]);

        return $this->findById($id);
    }

    public function delete(int $id): void
    {
        $this->findById($id); // 404s if missing
        $this->db->prepare('DELETE FROM route WHERE id = ?')->execute([$id]);
    }

    /* ---------------------------------------------------------- Images */

    public function getImages(int $routeId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, filename, sort_order, mime_type, size_bytes, uploaded_at FROM route_image WHERE route_id = ? ORDER BY sort_order'
        );
        $stmt->execute([$routeId]);
        return $stmt->fetchAll();
    }

    public function findImage(int $routeId, int $imageId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM route_image WHERE id = ? AND route_id = ?');
        $stmt->execute([$imageId, $routeId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function countImages(int $routeId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM route_image WHERE route_id = ?');
        $stmt->execute([$routeId]);
        return (int) $stmt->fetchColumn();
    }

    public function addImage(int $routeId, string $filename, string $mimeType, int $sizeBytes): array
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM route_image WHERE route_id = ?');
        $stmt->execute([$routeId]);
        $nextOrder = (int) $stmt->fetchColumn();

        $this->db->prepare(
            'INSERT INTO route_image (route_id, filename, sort_order, mime_type, size_bytes, uploaded_at) VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$routeId, $filename, $nextOrder, $mimeType, $sizeBytes]);

        return $this->getImages($routeId);
    }

    public function removeImage(int $routeId, int $imageId): void
    {
        $this->db->prepare('DELETE FROM route_image WHERE id = ? AND route_id = ?')->execute([$imageId, $routeId]);
    }
}
