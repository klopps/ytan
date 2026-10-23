<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\ShelterImport\ShelterImportRepository;
use Ytan\Service\Water\PoiWaterDistanceService;

/**
 * Turns staged shelters (table shelter_import) into POIs of type 10
 * "Unterstand / Shelter", one POI per shelter - several shelters at one place
 * stay separate POIs, each may have its own booking page. Name (or a generic
 * one), the booking link or website as the POI's link, and a description with
 * the source's facts and the source's attribution. Which entries are
 * imported is decided by the admin's filters (e.g. "sea at most 300 m");
 * the distances go to poi_water_distance (shown in the POI InfoWindow). Nothing is merged
 * automatically: possible duplicates (ShelterImportRepository::neighbours())
 * are only pointed out in the admin tool, the admin decides.
 */
final class ShelterImportService
{
    public const TYPE_SHELTER = 10;

    public const ATTRIBUTION = [
        'osm' => 'Quelle: © OpenStreetMap-Mitwirkende (ODbL 1.0)',
        'geofa' => 'Quelle: Indeholder data fra Naturstyrelsen / GeoFA (geofa.geodanmark.dk)',
        'nvv' => 'Quelle: Naturvårdsverket, Leder och friluftsanordningar (CC0)',
        'kv' => 'Quelle: Kartverket, Turrutebasen (åpne data)',
        'lipas' => 'Quelle: Lipas, Jyväskylän yliopisto (CC BY 4.0)',
    ];

    public function __construct(
        private readonly ShelterImportRepository $staging,
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
                'poitype_id' => self::TYPE_SHELTER,
                'name' => mb_substr(self::name($entry), 0, 200),
                'description' => self::description($entry),
                'public' => $public ? 1 : 0,
                'latitude' => $entry['latitude'],
                'longitude' => $entry['longitude'],
                'url' => $entry['website'] !== '' ? $entry['website'] : null,
            ]);
            $this->staging->markImported((int) $entry['id'], (int) $poi['id']);
            if ($this->waterDistances !== null) {
                if ($entry['water_checked_at'] !== null) {
                    $this->waterDistances->store($poi, ShelterImportRepository::distancesOf($entry));
                } else {
                    $this->waterDistances->afterSave($poi);
                }
            }
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => count($ids) - $imported];
    }

    /** @param array<string,mixed> $entry */
    public static function name(array $entry): string
    {
        $name = trim((string) $entry['name']);
        if ($name !== '') {
            return $name;
        }
        // No own name: "type place", e.g. "Gapahuk Femundsmarka" (place from tools/water/place_names.py).
        $generated = trim(trim((string) ($entry['type_label'] ?? '')) . ' ' . trim((string) ($entry['place'] ?? '')));
        if ($generated !== '' && trim((string) ($entry['place'] ?? '')) !== '') {
            return $generated;
        }

        return $entry['kind'] === 'hut' ? 'Hütte' : 'Unterstand';
    }

    /** @param array<string,mixed> $entry */
    public static function description(array $entry): string
    {
        $lines = [];
        if (trim((string) $entry['description']) !== '') {
            $lines[] = trim((string) $entry['description']);
        }
        // The distances to water are not repeated here: they go to
        // poi_water_distance and the POI InfoWindow shows them from there.
        $lines[] = self::ATTRIBUTION[$entry['source']] ?? 'Quelle: ' . $entry['source'];

        return implode("\n\n", $lines);
    }
}
