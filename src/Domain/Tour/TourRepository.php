<?php

declare(strict_types=1);

namespace Ytan\Domain\Tour;

use PDO;
use Ytan\Exception\NotFoundException;

final class TourRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findPublic(): array
    {
        return $this->db->query('SELECT * FROM tour WHERE public = 1')->fetchAll();
    }

    public function findByUser(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM tour WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function findByUserWithPublic(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM tour WHERE user_id = ? OR public = 1');
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function findById(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM tour WHERE id = ?');
        $stmt->execute([$id]);
        $tour = $stmt->fetch();

        if ($tour === false) {
            throw new NotFoundException("Tour $id not found.");
        }

        return $tour;
    }
}
