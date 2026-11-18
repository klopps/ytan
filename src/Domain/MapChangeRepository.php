<?php

declare(strict_types=1);

namespace Ytan\Domain;

use PDO;

/**
 * The live map's change feed (GET /map/changes, MapChangesController): which
 * POIs/routes/areas were written or deleted since a point in time.
 *
 * Writes are found by the tables' own `changed_at` (migration 077, bumped by
 * the database on every write); deletions leave a tombstone in `map_deletion`,
 * written by the repositories' delete(). Times are the DATABASE's clock
 * (NOW(3)) on both sides, so a skewed web server or client clock can't make a
 * change slip through.
 */
final class MapChangeRepository
{
    public const ENTITIES = ['poi', 'route', 'area'];

    /** Tombstones are only needed for as long as a client might poll with an old cursor. */
    private const TOMBSTONE_DAYS = 1;

    public function __construct(private readonly PDO $db)
    {
    }

    /** Remembers that a row was deleted; prunes old tombstones on the way. */
    public function recordDeletion(string $entity, int $id): void
    {
        $this->db->prepare('INSERT INTO map_deletion (entity, entity_id) VALUES (?, ?)')->execute([$entity, $id]);
        $this->db->exec('DELETE FROM map_deletion WHERE deleted_at < NOW(3) - INTERVAL ' . self::TOMBSTONE_DAYS . ' DAY');
    }

    /** The database's current time, the format `changed_at` compares with. */
    public function now(): string
    {
        return (string) $this->db->query('SELECT NOW(3)')->fetchColumn();
    }

    /** Number of rows of `$entity` ('poi'|'route'|'area') written since `$since`. */
    public function countChanged(string $entity, string $since): int
    {
        $this->assertEntity($entity);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `$entity` WHERE changed_at >= ?");
        $stmt->execute([$since]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Ids of `$entity` rows deleted since `$since`.
     *
     * @return list<int>
     */
    public function deletedSince(string $entity, string $since): array
    {
        $this->assertEntity($entity);
        $stmt = $this->db->prepare('SELECT DISTINCT entity_id FROM map_deletion WHERE entity = ? AND deleted_at >= ? ORDER BY entity_id');
        $stmt->execute([$entity, $since]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assertEntity(string $entity): void
    {
        if (!in_array($entity, self::ENTITIES, true)) {
            throw new \InvalidArgumentException("Unknown map entity $entity.");
        }
    }
}
