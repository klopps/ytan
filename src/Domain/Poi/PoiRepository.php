<?php

declare(strict_types=1);

namespace Ytan\Domain\Poi;

use PDO;
use Ytan\Exception\ConflictException;
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
            p.latitude, p.longitude, p.url, p.updated_at,
            wsi.direction,
            por.path AS portage_path,
            lh.characteristic, lh.sector_characteristic, lh.international_id, lh.imported_at
        FROM poi p
        LEFT JOIN windshelter wsi ON wsi.poi_id = p.id
        LEFT JOIN portage por ON por.poi_id = p.id
        LEFT JOIN lighthouse lh ON lh.poi_id = p.id
    SQL;

    public function __construct(private readonly PDO $db)
    {
    }

    public function findPublic(?int $limit = null, int $offset = 0): array
    {
        $stmt = $this->db->query(self::BASE_SELECT . ' WHERE p.public = 1 ORDER BY p.id' . $this->limitSuffix($limit, $offset));

        return $stmt->fetchAll();
    }

    public function countPublic(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM poi WHERE public = 1')->fetchColumn();
    }

    public function findByUser(int $userId, ?int $limit = null, int $offset = 0): array
    {
        $stmt = $this->db->prepare(self::BASE_SELECT . ' WHERE p.user_id = ? ORDER BY p.id' . $this->limitSuffix($limit, $offset));
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM poi WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function findByUserWithPublic(int $userId, ?int $limit = null, int $offset = 0): array
    {
        $stmt = $this->db->prepare(self::BASE_SELECT . ' WHERE p.user_id = ? OR p.public = 1 ORDER BY p.id' . $this->limitSuffix($limit, $offset));
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    public function countByUserWithPublic(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM poi WHERE user_id = ? OR public = 1');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function findAll(?int $limit = null, int $offset = 0): array
    {
        $stmt = $this->db->query(self::BASE_SELECT . ' ORDER BY p.id' . $this->limitSuffix($limit, $offset));

        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM poi')->fetchColumn();
    }

    /**
     * `$limit`/`$offset` are always cast to int by the caller before reaching
     * here, so interpolating them directly is safe - PDO's LIMIT/OFFSET
     * placeholder binding is a well-known source of driver-specific quirks,
     * this sidesteps it entirely.
     */
    private function limitSuffix(?int $limit, int $offset): string
    {
        return $limit !== null ? ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset) : '';
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
            'INSERT INTO poi (poitype_id, user_id, name, description, public, latitude, longitude, url, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
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
        $existing = $this->findById($id); // 404s if missing
        $this->assertTypeSpecificFields($data);
        $this->assertNotStale($existing, $data['expected_updated_at'] ?? null);

        $stmt = $this->db->prepare(
            'UPDATE poi SET poitype_id=?, name=?, description=?, public=?, latitude=?, longitude=?, url=?, updated_at=NOW() WHERE id=?'
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

    public function delete(int $id, ?string $expectedUpdatedAt = null): void
    {
        $existing = $this->findById($id); // 404s if missing
        $this->assertNotStale($existing, $expectedUpdatedAt);

        $this->db->prepare('DELETE FROM windshelter WHERE poi_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM lighthouse WHERE poi_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM poi WHERE id = ?')->execute([$id]);
    }

    private function assertNotStale(array $existing, ?string $expectedUpdatedAt): void
    {
        if ($expectedUpdatedAt === null) {
            return;
        }

        if ((string) $existing['updated_at'] !== $expectedUpdatedAt) {
            throw new ConflictException($existing);
        }
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

    /**
     * The oldest lighthouse POI that carries this international ID (spaces
     * ignored), or null. The ID is not unique: the same lighthouse may exist
     * more than once (an admin decides at import, see LighthouseImportService).
     */
    public function findPoiIdByInternationalId(string $internationalId): ?int
    {
        $stmt = $this->db->prepare('SELECT poi_id FROM lighthouse WHERE international_id = ? ORDER BY poi_id LIMIT 1');
        $stmt->execute([$this->normalizeInternationalId($internationalId)]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** How many lighthouse POIs carry this international ID (spaces ignored). */
    public function countPoisByInternationalId(string $internationalId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM lighthouse WHERE international_id = ?');
        $stmt->execute([$this->normalizeInternationalId($internationalId)]);

        return (int) $stmt->fetchColumn();
    }

    /** Marks a lighthouse as (just now) created or overwritten by the lighthouse list import. */
    public function markLighthouseImported(int $poiId): void
    {
        $this->db->prepare('UPDATE lighthouse SET imported_at = NOW() WHERE poi_id = ?')->execute([$poiId]);
    }

    /** The lists print the number with a space here and there ("B 1804.42"); it is stored without any. */
    private function normalizeInternationalId(mixed $value): ?string
    {
        $value = preg_replace('/\s+/u', '', (string) $value) ?? '';

        return $value === '' ? null : $value;
    }

    private function syncTypeSpecificFields(int $poiId, array $data): void
    {
        $type = (int) ($data['poitype_id'] ?? 0);

        if (in_array($type, [self::TYPE_CAMP, self::TYPE_CAMPSITE], true)) {
            $stmt = $this->db->prepare('REPLACE INTO windshelter (poi_id, direction) VALUES (?, ?)');
            $stmt->execute([$poiId, $data['direction']]);
        }

        if ($type === self::TYPE_LIGHTHOUSE) {
            // international_id and imported_at are not part of the map's POI
            // form: a request that doesn't mention them (an ordinary edit)
            // must keep the stored values, since the row is rewritten below.
            $existing = $this->db->prepare('SELECT international_id, imported_at FROM lighthouse WHERE poi_id = ? LIMIT 1');
            $existing->execute([$poiId]);
            $stored = $existing->fetch() ?: ['international_id' => null, 'imported_at' => null];

            $internationalId = array_key_exists('international_id', $data)
                ? $this->normalizeInternationalId($data['international_id'])
                : ($stored['international_id'] ?: null);
            $importedAt = array_key_exists('imported_at', $data)
                ? ($data['imported_at'] === null || $data['imported_at'] === '' ? null : (string) $data['imported_at'])
                : $stored['imported_at'];

            // Delete + insert rather than REPLACE INTO: lighthouse has no unique
            // key on poi_id (and international_id is not unique), so REPLACE
            // would add a second row for the same POI on every edit.
            $this->db->prepare('DELETE FROM lighthouse WHERE poi_id = ?')->execute([$poiId]);
            $stmt = $this->db->prepare(
                'INSERT INTO lighthouse (poi_id, characteristic, sector_characteristic, international_id, imported_at) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $poiId,
                $data['characteristic'] ?? null,
                $data['sector_characteristic'] ?? null,
                $internationalId,
                $importedAt,
            ]);
        }
    }

    /* ---------------------------------------------------------- Images */

    public function getImages(int $poiId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, filename, sort_order, mime_type, size_bytes, uploaded_at FROM poi_image WHERE poi_id = ? ORDER BY sort_order'
        );
        $stmt->execute([$poiId]);
        return $stmt->fetchAll();
    }

    /**
     * Every poi_image row across every POI, for
     * ImageReconciliationService's admin cleanup tool - unlike getImages(),
     * not scoped to one POI.
     *
     * @return list<array{id:int, entity_id:int, filename:string, size_bytes:int}>
     */
    public function getAllImages(): array
    {
        return $this->db->query('SELECT id, poi_id AS entity_id, filename, size_bytes FROM poi_image')->fetchAll();
    }

    public function findImage(int $poiId, int $imageId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM poi_image WHERE id = ? AND poi_id = ?');
        $stmt->execute([$imageId, $poiId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function countImages(int $poiId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM poi_image WHERE poi_id = ?');
        $stmt->execute([$poiId]);
        return (int) $stmt->fetchColumn();
    }

    public function addImage(int $poiId, string $filename, string $mimeType, int $sizeBytes): array
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM poi_image WHERE poi_id = ?');
        $stmt->execute([$poiId]);
        $nextOrder = (int) $stmt->fetchColumn();

        $this->db->prepare(
            'INSERT INTO poi_image (poi_id, filename, sort_order, mime_type, size_bytes, uploaded_at) VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$poiId, $filename, $nextOrder, $mimeType, $sizeBytes]);

        return $this->getImages($poiId);
    }

    public function removeImage(int $poiId, int $imageId): void
    {
        $this->db->prepare('DELETE FROM poi_image WHERE id = ? AND poi_id = ?')->execute([$imageId, $poiId]);
    }
}
