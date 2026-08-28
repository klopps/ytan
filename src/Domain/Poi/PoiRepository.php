<?php

declare(strict_types=1);

namespace Ytan\Domain\Poi;

use PDO;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;

final class PoiRepository
{
    public const TYPE_CAMP = 1;
    public const TYPE_CAMPSITE = 11;
    public const TYPE_LIGHTHOUSE = 14;

    private const BASE_SELECT = <<<'SQL'
        SELECT
            p.id, p.poitype_id, p.user_id, p.name, p.description, p.public,
            p.latitude, p.longitude, p.url,
            wsi.direction,
            por.path AS portage_path,
            lh.characteristic, lh.sector_characteristic
        FROM poi p
        LEFT JOIN windshelter wsi ON wsi.poi_id = p.id
        LEFT JOIN portage por ON por.poi_id = p.id
        LEFT JOIN lighthouse lh ON lh.poi_id = p.id
    SQL;

    public function __construct(private readonly PDO $db)
    {
    }

    public function findPublic(): array
    {
        $stmt = $this->db->query(self::BASE_SELECT . ' WHERE p.public = 1');

        return $stmt->fetchAll();
    }

    public function findByUser(int $userId): array
    {
        $stmt = $this->db->prepare(self::BASE_SELECT . ' WHERE p.user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function findByUserWithPublic(int $userId): array
    {
        $stmt = $this->db->prepare(self::BASE_SELECT . ' WHERE p.user_id = ? OR p.public = 1');
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function findAll(): array
    {
        $stmt = $this->db->query(self::BASE_SELECT);

        return $stmt->fetchAll();
    }

    public function findById(int $id): array
    {
        $stmt = $this->db->prepare(self::BASE_SELECT . ' WHERE p.id = ?');
        $stmt->execute([$id]);
        $poi = $stmt->fetch();

        if ($poi === false) {
            throw new NotFoundException("POI $id not found.");
        }

        return $poi;
    }

    public function bounds(): array
    {
        $stmt = $this->db->query(
            'SELECT MAX(latitude) AS max_lat, MIN(latitude) AS min_lat,
                    MAX(longitude) AS max_lng, MIN(longitude) AS min_lng
             FROM poi'
        );

        return $stmt->fetch();
    }

    public function create(int $userId, array $data): array
    {
        $this->assertTypeSpecificFields($data);

        $stmt = $this->db->prepare(
            'INSERT INTO poi (poitype_id, user_id, name, description, public, latitude, longitude, url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['poitype_id'],
            $userId,
            $data['name'],
            $data['description'] ?? null,
            (int) ($data['public'] ?? 0),
            $data['latitude'],
            $data['longitude'],
            $data['url'] ?? null,
        ]);

        $id = (int) $this->db->lastInsertId();
        $this->syncTypeSpecificFields($id, $data);

        return $this->findById($id);
    }

    public function update(int $id, array $data): array
    {
        $this->findById($id); // 404s if missing
        $this->assertTypeSpecificFields($data);

        $stmt = $this->db->prepare(
            'UPDATE poi SET poitype_id=?, name=?, description=?, public=?, latitude=?, longitude=?, url=? WHERE id=?'
        );
        $stmt->execute([
            $data['poitype_id'],
            $data['name'],
            $data['description'] ?? null,
            (int) ($data['public'] ?? 0),
            $data['latitude'],
            $data['longitude'],
            $data['url'] ?? null,
            $id,
        ]);

        $this->syncTypeSpecificFields($id, $data);

        return $this->findById($id);
    }

    public function delete(int $id): void
    {
        $this->findById($id); // 404s if missing

        $this->db->prepare('DELETE FROM windshelter WHERE poi_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM lighthouse WHERE poi_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM poi WHERE id = ?')->execute([$id]);
    }

    private function assertTypeSpecificFields(array $data): void
    {
        $type = (int) ($data['poitype_id'] ?? 0);

        if (in_array($type, [self::TYPE_CAMP, self::TYPE_CAMPSITE], true)) {
            $direction = $data['direction'] ?? '';
            if (preg_match('/^[012]{16}$/', (string) $direction) !== 1) {
                throw new ValidationException('direction must be a 16-character string of 0/1/2.');
            }
        }
    }

    private function syncTypeSpecificFields(int $poiId, array $data): void
    {
        $type = (int) ($data['poitype_id'] ?? 0);

        if (in_array($type, [self::TYPE_CAMP, self::TYPE_CAMPSITE], true)) {
            $stmt = $this->db->prepare('REPLACE INTO windshelter (poi_id, direction) VALUES (?, ?)');
            $stmt->execute([$poiId, $data['direction']]);
        }

        if ($type === self::TYPE_LIGHTHOUSE) {
            $stmt = $this->db->prepare(
                'REPLACE INTO lighthouse (poi_id, characteristic, sector_characteristic) VALUES (?, ?, ?)'
            );
            $stmt->execute([
                $poiId,
                $data['characteristic'] ?? null,
                $data['sector_characteristic'] ?? null,
            ]);
        }
    }
}
