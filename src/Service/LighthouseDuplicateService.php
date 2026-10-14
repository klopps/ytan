<?php

declare(strict_types=1);

namespace Ytan\Service;

use PDO;
use Ytan\Domain\Poi\LighthouseDuplicateRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Exception\ValidationException;

/**
 * Duplicate check for lighthouse POIs: finds lighthouses that lie close
 * together (typically a hand-drawn one and the same lighthouse imported from
 * an official list, a little apart) and merges the ones the admin says
 * describe the same lighthouse.
 */
final class LighthouseDuplicateService
{
    /** The fields of a merge: what the admin can take from which record. */
    public const FIELDS = ['name', 'description', 'position', 'characteristic', 'sector_characteristic', 'international_id', 'public'];

    private const EARTH_RADIUS_M = 6371008.8;
    public const MAX_IMAGES_PER_POI = 5; // same limit as PoiController

    public function __construct(
        private readonly PoiRepository $pois,
        private readonly LighthouseDuplicateRepository $repository,
        private readonly ImageStorageService $images,
        private readonly PDO $db,
    ) {
    }

    /**
     * Groups of lighthouses no more than $maxMeters apart from a neighbour in
     * the group (a chain of close lighthouses makes one group). Each group
     * lists its lighthouses hand-drawn ones first, then by id, with the
     * distance to the first of them. The groups are sorted by their largest
     * distance, ascending.
     *
     * With $hideDifferentIds a group is left out when it contains an imported
     * lighthouse and every lighthouse in it has an international ID, but not
     * all the same one: those are different lighthouses by the list's own
     * numbering. A group in which at least one international ID is NULL or
     * empty is always listed.
     *
     * With $onlyWithManual only groups are listed in which at least one
     * lighthouse was NOT imported (all-imported groups are duplicates among
     * the list's own entries, nothing hand-drawn to compare with).
     *
     * @return list<array{key:string, max_distance:float, pois:list<array<string,mixed>>}>
     */
    public function findGroups(float $maxMeters, bool $hideDifferentIds = false, bool $onlyWithManual = false): array
    {
        $all = $this->repository->allLighthouses();
        $count = count($all);

        // Sweep over the latitude-sorted list: only POIs within the latitude window can be close.
        $order = array_keys($all);
        usort($order, fn ($a, $b) => (float) $all[$a]['latitude'] <=> (float) $all[$b]['latitude']);
        $window = $maxMeters / 111_195.0; // degrees of latitude

        $parent = range(0, max(0, $count - 1));
        $find = function (int $i) use (&$parent, &$find): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }

            return $i;
        };

        for ($a = 0; $a < $count; $a++) {
            $i = $order[$a];
            for ($b = $a + 1; $b < $count; $b++) {
                $j = $order[$b];
                if ((float) $all[$j]['latitude'] - (float) $all[$i]['latitude'] > $window) {
                    break;
                }
                if ($this->distance($all[$i], $all[$j]) <= $maxMeters) {
                    $parent[$find($i)] = $find($j);
                }
            }
        }

        $components = [];
        foreach (array_keys($all) as $i) {
            $components[$find($i)][] = $i;
        }

        $groups = [];
        foreach ($components as $members) {
            if (count($members) < 2) {
                continue;
            }
            $pois = array_map(fn ($i) => $all[$i], $members);
            // Hand-drawn lighthouses first (their positions are usually the better ones), then by id.
            usort($pois, fn ($x, $y) => [!$this->isManual($x), (int) $x['id']] <=> [!$this->isManual($y), (int) $y['id']]);

            $maxDistance = 0.0;
            foreach ($pois as $x => $poi) {
                $pois[$x]['manual'] = $this->isManual($poi);
                $pois[$x]['distance_m'] = round($this->distance($pois[0], $poi), 1);
                foreach ($pois as $other) {
                    $maxDistance = max($maxDistance, $this->distance($poi, $other));
                }
            }
            if ($hideDifferentIds && $this->hasDifferentInternationalIds($pois)) {
                continue;
            }
            if ($onlyWithManual && !in_array(true, array_column($pois, 'manual'), true)) {
                continue;
            }
            $groups[] = [
                'key' => implode('-', array_map(fn ($p) => (int) $p['id'], $pois)),
                'max_distance' => round($maxDistance, 1),
                'pois' => $pois,
            ];
        }

        // Closest first (the likeliest duplicates); equal distances by the first record's id.
        usort($groups, fn ($x, $y) => [$x['max_distance'], (int) $x['pois'][0]['id']] <=> [$y['max_distance'], (int) $y['pois'][0]['id']]);

        return $groups;
    }

    /**
     * Merges lighthouses: each merge keeps one record ($keepId), takes the
     * chosen fields from the chosen records, takes over the photos of the
     * others (up to MAX_IMAGES_PER_POI), hands their list-import entries over
     * and deletes them.
     *
     * @param list<array{keep_id:int, ids:list<int>, fields?:array<string,int>}> $merges
     *        ids = all records of the merge, keep_id among them; fields: field => id of the record to take it from
     *        (default: the kept record's own)
     * @return array{merged:int, deleted:int, images_moved:int, images_dropped:int}
     */
    public function merge(array $merges): array
    {
        $result = ['merged' => 0, 'deleted' => 0, 'images_moved' => 0, 'images_dropped' => 0];

        foreach ($merges as $merge) {
            $single = $this->mergeOne($merge);
            foreach ($single as $key => $value) {
                $result[$key] += $value;
            }
            $result['merged']++;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $merge
     * @return array{deleted:int, images_moved:int, images_dropped:int}
     */
    private function mergeOne(array $merge): array
    {
        $keepId = (int) ($merge['keep_id'] ?? 0);
        $ids = array_values(array_unique(array_map('intval', (array) ($merge['ids'] ?? []))));
        if (count($ids) < 2 || !in_array($keepId, $ids, true)) {
            throw new ValidationException('A merge needs at least two lighthouses, the kept one among them.');
        }

        // Everything must really be a lighthouse, and the field sources must belong to the merge.
        $records = [];
        foreach ($ids as $id) {
            $poi = $this->pois->findById($id); // 404s if missing
            if ((int) $poi['poitype_id'] !== PoiRepository::TYPE_LIGHTHOUSE) {
                throw new ValidationException("POI $id is not a lighthouse.");
            }
            $records[$id] = $poi;
        }
        $sources = [];
        foreach ((array) ($merge['fields'] ?? []) as $field => $sourceId) {
            if (!in_array($field, self::FIELDS, true) || !isset($records[(int) $sourceId])) {
                throw new ValidationException("Invalid field choice for $field.");
            }
            $sources[$field] = $records[(int) $sourceId];
        }

        $keep = $records[$keepId];
        $from = fn (string $field) => $sources[$field] ?? $keep;
        $data = [
            'poitype_id' => PoiRepository::TYPE_LIGHTHOUSE,
            'name' => $from('name')['name'],
            'description' => $from('description')['description'],
            'public' => (int) $from('public')['public'],
            'latitude' => $from('position')['latitude'],
            'longitude' => $from('position')['longitude'],
            'characteristic' => $from('characteristic')['characteristic'],
            'sector_characteristic' => $from('sector_characteristic')['sector_characteristic'],
            'international_id' => $from('international_id')['international_id'],
            // A kept hand-drawn lighthouse that absorbs an imported one counts as imported from then on (the latest time).
            'imported_at' => $this->latestImportTime($records, $keep),
        ];
        $deleteIds = array_values(array_diff($ids, [$keepId]));

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        $copied = [];   // [poiId, filename] of photo files copied for the kept record, removed again if this fails
        $toDelete = []; // [poiId, filename] of the photo files of the deleted records
        $moved = 0;
        $dropped = 0;
        try {
            $this->pois->update($keepId, $data);

            $room = self::MAX_IMAGES_PER_POI - $this->pois->countImages($keepId);
            foreach ($deleteIds as $deleteId) {
                foreach ($this->pois->getImages($deleteId) as $image) {
                    $toDelete[] = [$deleteId, $image['filename']];
                    if ($room <= 0) {
                        $dropped++;
                        continue;
                    }
                    $newName = $this->images->copy($deleteId, $image['filename'], $keepId);
                    if ($newName === null) {
                        $dropped++;
                        continue;
                    }
                    $copied[] = [$keepId, $newName];
                    $this->pois->addImage($keepId, $newName, (string) $image['mime_type'], (int) $image['size_bytes']);
                    $room--;
                    $moved++;
                }
            }

            $this->repository->repointImports($deleteIds, $keepId);
            foreach ($deleteIds as $deleteId) {
                $this->pois->delete($deleteId);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            foreach ($copied as [$poiId, $filename]) {
                $this->images->delete($poiId, $filename);
            }
            throw $e;
        }

        foreach ($toDelete as [$poiId, $filename]) {
            $this->images->delete($poiId, $filename);
        }

        return ['deleted' => count($deleteIds), 'images_moved' => $moved, 'images_dropped' => $dropped];
    }

    /**
     * True for a group with an imported lighthouse where every lighthouse has
     * an international ID and at least two of them differ.
     *
     * @param list<array<string,mixed>> $pois
     */
    private function hasDifferentInternationalIds(array $pois): bool
    {
        $ids = [];
        $anyImported = false;
        foreach ($pois as $poi) {
            $id = trim((string) ($poi['international_id'] ?? ''));
            if ($id === '') {
                return false;
            }
            $ids[$id] = true;
            $anyImported = $anyImported || !$this->isManual($poi);
        }

        return $anyImported && count($ids) > 1;
    }

    /** Not created by the list import: no import time and no staged entry pointing at it. */
    private function isManual(array $poi): bool
    {
        return ($poi['imported_at'] ?? null) === null && (int) ($poi['import_count'] ?? 0) === 0;
    }

    /**
     * @param array<int,array<string,mixed>> $records the records of a merge
     * @param array<string,mixed> $keep
     */
    private function latestImportTime(array $records, array $keep): ?string
    {
        $latest = null;
        foreach ($records as $record) {
            $time = $record['imported_at'] ?? null;
            if ($time !== null && ($latest === null || (string) $time > $latest)) {
                $latest = (string) $time;
            }
        }

        return $latest ?? ($keep['imported_at'] ?? null);
    }

    /**
     * Distance in metres between two POIs (haversine).
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function distance(array $a, array $b): float
    {
        $lat1 = deg2rad((float) $a['latitude']);
        $lat2 = deg2rad((float) $b['latitude']);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad((float) $b['longitude'] - (float) $a['longitude']);
        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($h)));
    }
}
