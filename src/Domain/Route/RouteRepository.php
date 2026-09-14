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

    public function findPublic(?int $limit = null, int $offset = 0): array
    {
        return $this->db->query('SELECT * FROM route WHERE public = 1 ORDER BY id' . $this->limitSuffix($limit, $offset))->fetchAll();
    }

    public function countPublic(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM route WHERE public = 1')->fetchColumn();
    }

    public function findByUser(int $userId, ?int $limit = null, int $offset = 0): array
    {
        $stmt = $this->db->prepare('SELECT * FROM route WHERE user_id = ? ORDER BY id' . $this->limitSuffix($limit, $offset));
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
        $stmt = $this->db->prepare('SELECT * FROM route WHERE user_id = ? OR public = 1 ORDER BY id' . $this->limitSuffix($limit, $offset));
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
            'SELECT route.* FROM tour_route
             LEFT JOIN route ON route.id = tour_route.route_id
             WHERE tour_route.tour_id = ?
             ORDER BY tour_route.sort_order'
        );
        $stmt->execute([$tourId]);

        return $stmt->fetchAll();
    }

    public function findById(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM route WHERE id = ?');
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
}
