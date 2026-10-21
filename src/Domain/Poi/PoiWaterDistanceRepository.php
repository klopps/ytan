<?php

declare(strict_types=1);

namespace Ytan\Domain\Poi;

use PDO;

/**
 * Table poi_water_distance: per POI the distances in metres to the nearest
 * sea, river/canal and large lake (WaterDistanceService); null = farther
 * than WaterDistanceService::MAX_METERS.
 */
final class PoiWaterDistanceRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array{sea:?int, river:?int, lake:?int} $distances */
    public function save(int $poiId, array $distances, ?string $indexVersion): void
    {
        $this->db->prepare(
            'INSERT INTO poi_water_distance (poi_id, sea_m, river_m, lake_m, index_version, computed_at) VALUES (?, ?, ?, ?, ?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE sea_m = VALUES(sea_m), river_m = VALUES(river_m), lake_m = VALUES(lake_m), '
            . 'index_version = VALUES(index_version), computed_at = VALUES(computed_at)'
        )->execute([$poiId, $distances['sea'], $distances['river'], $distances['lake'], $indexVersion]);
    }

    public function delete(int $poiId): void
    {
        $this->db->prepare('DELETE FROM poi_water_distance WHERE poi_id = ?')->execute([$poiId]);
    }

    /** @return array{sea_m:?int, river_m:?int, lake_m:?int, index_version:?string, computed_at:string}|null */
    public function find(int $poiId): ?array
    {
        $stmt = $this->db->prepare('SELECT sea_m, river_m, lake_m, index_version, computed_at FROM poi_water_distance WHERE poi_id = ?');
        $stmt->execute([$poiId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        foreach (['sea_m', 'river_m', 'lake_m'] as $column) {
            $row[$column] = $row[$column] === null ? null : (int) $row[$column];
        }

        return $row;
    }

    /**
     * POIs of the given types (id, poitype_id, latitude, longitude), only
     * those without distances or computed with another index unless $all.
     *
     * @param list<int> $poiTypes
     * @return list<array<string,mixed>>
     */
    public function poisToCompute(array $poiTypes, ?string $indexVersion, bool $all): array
    {
        if ($poiTypes === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($poiTypes), '?'));
        $sql = 'SELECT p.id, p.poitype_id, p.latitude, p.longitude FROM poi p '
            . 'LEFT JOIN poi_water_distance w ON w.poi_id = p.id '
            . 'WHERE p.poitype_id IN (' . $placeholders . ')';
        $params = array_map('intval', $poiTypes);
        if (!$all) {
            $sql .= ' AND (w.poi_id IS NULL OR NOT (w.index_version <=> ?))';
            $params[] = $indexVersion;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY p.latitude, p.longitude');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
