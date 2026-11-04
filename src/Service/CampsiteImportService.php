<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\CampsiteImport\CampsiteImportRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Service\Campsite\CampsiteOsmMapper;
use Ytan\Service\Water\PoiWaterDistanceService;

/**
 * Turns staged campsites (table campsite_import) into POIs: category
 * 'campsite' becomes type 11 "Campingplatz (kommerziell)", 'basic' type 1
 * "Zeltplatz". Name, the website as the POI's link and a description with
 * the facts from OSM plus the ODbL attribution; the distances to water go to
 * poi_water_distance (shown in the POI InfoWindow). Nothing is merged
 * automatically: possible duplicates (CampsiteImportRepository::neighbours())
 * are only pointed out in the admin tool, the admin decides.
 */
final class CampsiteImportService
{
    public const ATTRIBUTION = 'Quelle: © OpenStreetMap-Mitwirkende (ODbL 1.0)';

    public function __construct(
        private readonly CampsiteImportRepository $staging,
        private readonly PoiRepository $pois,
        private readonly ?PoiWaterDistanceService $waterDistances = null,
    ) {
    }

    /**
     * @param list<int> $ids staging ids; entries already imported are skipped
     * @return array{imported:int, skipped:int}
     */
    public function import(array $ids, int $userId, bool $public): array
    {
        $imported = 0;
        foreach ($this->staging->findOpenByIds($ids) as $entry) {
            $poi = $this->pois->create($userId, [
                'poitype_id' => self::poiType($entry),
                'name' => mb_substr((string) $entry['name'], 0, 200),
                'description' => self::description($entry),
                'public' => $public ? 1 : 0,
                'latitude' => $entry['latitude'],
                'longitude' => $entry['longitude'],
                'url' => $entry['website'] !== '' ? $entry['website'] : null,
                // Wind shelter not known from OSM: no WSI icon until somebody enters one.
                'direction' => null,
            ]);
            $this->staging->markImported((int) $entry['id'], (int) $poi['id']);
            if ($this->waterDistances !== null) {
                if ($entry['water_checked_at'] !== null) {
                    $this->waterDistances->store($poi, CampsiteImportRepository::distancesOf($entry));
                } else {
                    $this->waterDistances->afterSave($poi);
                }
            }
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => count($ids) - $imported];
    }

    /** @param array<string,mixed> $entry */
    public static function poiType(array $entry): int
    {
        return $entry['category'] === CampsiteOsmMapper::CATEGORY_BASIC
            ? CampsiteImportRepository::POI_TYPE_BASIC
            : CampsiteImportRepository::POI_TYPE_CAMPSITE;
    }

    /** @param array<string,mixed> $entry */
    public static function description(array $entry): string
    {
        $lines = [];
        if (trim((string) $entry['description']) !== '') {
            $lines[] = trim((string) $entry['description']);
        }
        $lines[] = self::ATTRIBUTION;

        return implode("\n\n", $lines);
    }
}
