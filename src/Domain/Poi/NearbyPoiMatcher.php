<?php

declare(strict_types=1);

namespace Ytan\Domain\Poi;

use PDO;

/**
 * Which rows of an import staging table (shelter_import, campsite_import)
 * have a POI of the given types - not their own `poi_id` - close by: within
 * $degrees in latitude and $degrees / cos(latitude) in longitude.
 *
 * This used to be a correlated `EXISTS (SELECT 1 FROM poi ...)` per staging
 * row inside the list query. MariaDB ran it as a nested loop over every POI
 * of the type for every staging row, with or without a position index
 * (measured 2026-11-15: 6-16 s for the shelter list's "duplicate" filter on
 * 31,000 entries). Here both sides are read once and matched through a
 * latitude grid in PHP (~0.1 s), with the same rule as the old SQL.
 */
final class NearbyPoiMatcher
{
    /**
     * @param list<int> $poiTypes
     * @return list<int> staging ids
     */
    public static function stagedIdsNearPoi(PDO $db, string $stagingTable, array $poiTypes, float $degrees): array
    {
        if ($poiTypes === [] || !preg_match('/^[a-z_]+$/', $stagingTable)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($poiTypes), '?'));
        $stmt = $db->prepare("SELECT id, latitude, longitude FROM poi WHERE poitype_id IN ($placeholders)");
        $stmt->execute(array_map('intval', $poiTypes));

        /** @var array<int, list<array{0:int,1:float,2:float}>> $cells latitude cell => POIs */
        $cells = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$id, $lat, $lng]) {
            $cells[(int) floor((float) $lat / $degrees)][] = [(int) $id, (float) $lat, (float) $lng];
        }
        if ($cells === []) {
            return [];
        }

        $ids = [];
        $rows = $db->query("SELECT id, poi_id, latitude, longitude FROM $stagingTable")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as [$id, $ownPoi, $lat, $lng]) {
            $lat = (float) $lat;
            $lng = (float) $lng;
            $ownPoi = (int) ($ownPoi ?? 0);
            $lngTolerance = $degrees / cos(deg2rad($lat));
            $from = (int) floor(($lat - $degrees) / $degrees);
            $to = (int) floor(($lat + $degrees) / $degrees);
            for ($cell = $from; $cell <= $to; $cell++) {
                foreach ($cells[$cell] ?? [] as [$poiId, $poiLat, $poiLng]) {
                    if ($poiId !== $ownPoi
                        && abs($poiLat - $lat) <= $degrees + 1e-9
                        && abs($poiLng - $lng) <= $lngTolerance + 1e-9) {
                        $ids[] = (int) $id;
                        continue 3;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * SQL condition on alias `s` for the list filters: duplicate "yes" or "no".
     *
     * @param list<int> $ids from stagedIdsNearPoi()
     */
    public static function condition(array $ids, bool $near): ?string
    {
        if ($ids === []) {
            return $near ? '0 = 1' : null;
        }

        return 's.id ' . ($near ? 'IN' : 'NOT IN') . ' (' . implode(',', array_map('intval', $ids)) . ')';
    }
}
