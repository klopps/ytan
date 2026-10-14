<?php

declare(strict_types=1);

namespace Ytan\Domain\Poi;

use PDO;

/**
 * Queries for the lighthouse duplicate check (LighthouseDuplicateService):
 * every lighthouse POI with what a comparison side by side needs, and the
 * hand-over of the lighthouse list import's bookkeeping when two lighthouses
 * are merged.
 */
final class LighthouseDuplicateRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Every lighthouse POI, with its owner's name, the number of photos, when
     * it was imported (lighthouse.imported_at) and how many staged list entries
     * point at it (lighthouse_import.poi_id) - a lighthouse with neither is
     * "manual".
     *
     * @return list<array<string,mixed>>
     */
    public function allLighthouses(): array
    {
        return $this->db->query(
            'SELECT p.id, p.name, p.description, p.public, p.latitude, p.longitude, p.user_id, p.updated_at,
                    lh.characteristic, lh.sector_characteristic, lh.international_id, lh.imported_at,
                    u.username,
                    (SELECT COUNT(*) FROM poi_image pi WHERE pi.poi_id = p.id) AS image_count,
                    (SELECT COUNT(*) FROM lighthouse_import li WHERE li.poi_id = p.id) AS import_count
             FROM poi p
             JOIN lighthouse lh ON lh.poi_id = p.id
             LEFT JOIN user u ON u.id = p.user_id
             WHERE p.poitype_id = ' . PoiRepository::TYPE_LIGHTHOUSE . '
             ORDER BY p.id'
        )->fetchAll();
    }

    /**
     * Staged list entries that were imported as one of the POIs about to be
     * deleted now point at the POI that survives - otherwise they would turn
     * "open" again (the foreign key clears them) and could be imported a
     * second time.
     *
     * @param list<int> $fromPoiIds
     */
    public function repointImports(array $fromPoiIds, int $toPoiId): void
    {
        if ($fromPoiIds === []) {
            return;
        }
        $stmt = $this->db->prepare(
            'UPDATE lighthouse_import SET poi_id = ? WHERE poi_id IN (' . implode(', ', array_fill(0, count($fromPoiIds), '?')) . ')'
        );
        $stmt->execute(array_merge([$toPoiId], array_values($fromPoiIds)));
    }
}
