<?php

declare(strict_types=1);

namespace Ytan\Domain\Area;

use PDO;
use Ytan\Exception\NotFoundException;

final class AreaRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findPublic(?int $limit = null, int $offset = 0): array
    {
        return $this->db->query('SELECT * FROM area WHERE public = 1 ORDER BY id' . $this->limitSuffix($limit, $offset))->fetchAll();
    }

    public function countPublic(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM area WHERE public = 1')->fetchColumn();
    }

    public function findByUser(int $userId, ?int $limit = null, int $offset = 0): array
    {
        $stmt = $this->db->prepare('SELECT * FROM area WHERE user_id = ? ORDER BY id' . $this->limitSuffix($limit, $offset));
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM area WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function findByUserWithPublic(int $userId, ?int $limit = null, int $offset = 0): array
    {
        $stmt = $this->db->prepare('SELECT * FROM area WHERE user_id = ? OR public = 1 ORDER BY id' . $this->limitSuffix($limit, $offset));
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function countByUserWithPublic(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM area WHERE user_id = ? OR public = 1');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    private function limitSuffix(?int $limit, int $offset): string
    {
        return $limit !== null ? ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset) : '';
    }

    public function findById(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM area WHERE id = ?');
        $stmt->execute([$id]);
        $area = $stmt->fetch();

        if ($area === false) {
            throw new NotFoundException("Area $id not found.");
        }

        return $area;
    }

    public function create(int $userId, array $data): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO area (user_id, name, description, public, points, color, opacity, zindex)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['name'],
            $data['description'] ?? null,
            (int) ($data['public'] ?? 0),
            $data['points'] ?? null,
            $data['color'] ?? '#00FF30',
            $data['opacity'] ?? 0.10,
            $data['zindex'] ?? 1,
        ]);

        return $this->findById((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $data): array
    {
        $this->findById($id); // 404s if missing

        $stmt = $this->db->prepare(
            'UPDATE area SET name=?, description=?, public=?, points=?, color=?, opacity=?, zindex=? WHERE id=?'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            (int) ($data['public'] ?? 0),
            $data['points'] ?? null,
            $data['color'] ?? '#00FF30',
            $data['opacity'] ?? 0.10,
            $data['zindex'] ?? 1,
            $id,
        ]);

        return $this->findById($id);
    }

    public function delete(int $id): void
    {
        $this->findById($id); // 404s if missing
        $this->db->prepare('DELETE FROM area WHERE id = ?')->execute([$id]);
    }
}
