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

    public function findPublic(): array
    {
        return $this->db->query('SELECT * FROM area WHERE public = 1')->fetchAll();
    }

    public function findByUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM area WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function findByUserWithPublic(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM area WHERE user_id = ? OR public = 1');
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
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
