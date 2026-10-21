<?php

declare(strict_types=1);

namespace Ytan\Domain\ShelterImport;

use PDO;
use Ytan\Service\Water\WaterDistanceService;

/**
 * The staging table behind the admin tool "Shelter import": shelters and huts
 * from free sources, waiting to be filtered, selected and turned into POIs.
 */
final class ShelterImportRepository
{
    private const COLUMNS = ['name', 'kind', 'type_label', 'country', 'description', 'website', 'latitude', 'longitude'];

    /** SQL: the name shown and imported - the own name, else "type_label place". */
    public const DISPLAY_NAME = "COALESCE(NULLIF(s.name, ''), NULLIF(TRIM(CONCAT(s.type_label, ' ', s.place)), ''), '')";

    private const TEXT_FILTERS = ['name' => self::DISPLAY_NAME, 'description' => 's.description'];

    /**
     * Half the side of the square in which an existing shelter POI makes an
     * entry a possible duplicate: about 33 m in latitude; the longitude side
     * is widened by the latitude's cosine. Small on purpose - several
     * shelters often stand at one place, each its own POI.
     */
    public const DUPLICATE_DEGREES = 0.0003;

    /**
     * SQL: entry `s` has a shelter POI (not its own) close by - e.g. the same
     * shelter imported from another source before, or drawn by hand. Entries
     * of other sources that are not imported yet do NOT count: before the
     * first import every shelter would otherwise be "doubtful".
     */
    private const DUPLICATE_CONDITION = '(EXISTS (SELECT 1 FROM poi p WHERE p.poitype_id = ' . self::POI_TYPE_SHELTER . ' AND p.id <> COALESCE(s.poi_id, 0)'
        . ' AND p.latitude BETWEEN s.latitude - ' . self::DUPLICATE_DEGREES . ' AND s.latitude + ' . self::DUPLICATE_DEGREES
        . ' AND p.longitude BETWEEN s.longitude - ' . self::DUPLICATE_DEGREES . ' / COS(RADIANS(s.latitude))'
        . ' AND s.longitude + ' . self::DUPLICATE_DEGREES . ' / COS(RADIANS(s.latitude))))';

    private const POI_TYPE_SHELTER = 10;

    /** The three distance columns, in WaterDistanceService's key order (sea, river, lake). */
    public const DISTANCE_COLUMNS = ['sea' => 'sea_m', 'river' => 'river_m', 'lake' => 'lake_m'];

    public function __construct(private readonly PDO $db, private readonly ?WaterDistanceService $water = null)
    {
    }

    /**
     * Inserts the entries or refreshes the ones already there (matched by
     * source + source_ref); whether an entry was imported already is kept.
     * The distances to water: taken from the entry when it brings them
     * (`water_checked` true, the transfer file), kept for an entry that did
     * not move and was computed before, otherwise computed here from the
     * water index (left NULL = not computed when there is no index).
     *
     * @param list<array<string,mixed>> $entries mapper output
     * @return array{inserted:int, updated:int}
     */
    public function upsertMany(array $entries): array
    {
        $columns = array_merge(['source', 'source_ref'], self::COLUMNS, ['sea_m', 'river_m', 'lake_m', 'water_checked_at', 'place']);
        $assignments = implode(', ', array_map(fn ($c) => "$c = VALUES($c)", array_slice($columns, 2)));
        $stmt = $this->db->prepare(
            'INSERT INTO shelter_import (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ') ON DUPLICATE KEY UPDATE ' . $assignments
        );
        $stored = $this->storedPositions($entries);
        $canCompute = $this->water !== null && $this->water->available();

        $inserted = 0;
        $updated = 0;
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($entries as $entry) {
                [$distances, $checkedAt] = $this->distancesFor($entry, $stored[$entry['source'] . "\n" . $entry['source_ref']] ?? null, $canCompute);
                $stmt->execute([
                    $entry['source'],
                    $entry['source_ref'],
                    mb_substr((string) $entry['name'], 0, 200),
                    $entry['kind'],
                    mb_substr((string) ($entry['type_label'] ?? ''), 0, 60),
                    $entry['country'],
                    mb_substr((string) $entry['description'], 0, 1000),
                    mb_substr((string) $entry['website'], 0, 500),
                    $entry['latitude'],
                    $entry['longitude'],
                    $distances['sea'],
                    $distances['river'],
                    $distances['lake'],
                    $checkedAt,
                    $this->placeFor($entry, $stored[$entry['source'] . "\n" . $entry['source_ref']] ?? null),
                ]);
                // MySQL reports 1 affected row for an insert, 2 for a changed update.
                if ($stmt->rowCount() === 1) {
                    $inserted++;
                } else {
                    $updated++;
                }
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed>|null $stored the stored row (position and distances), null for a new entry
     * @return array{0: array{sea:?int, river:?int, lake:?int}, 1: ?string}
     */
    private function distancesFor(array $entry, ?array $stored, bool $canCompute): array
    {
        if (($entry['water_checked'] ?? false) === true) {
            return [[
                'sea' => $entry['sea_m'] ?? null,
                'river' => $entry['river_m'] ?? null,
                'lake' => $entry['lake_m'] ?? null,
            ], gmdate('Y-m-d H:i:s')];
        }
        if ($stored !== null && $stored['water_checked_at'] !== null
            && (float) $stored['latitude'] === round((float) $entry['latitude'], 7)
            && (float) $stored['longitude'] === round((float) $entry['longitude'], 7)) {
            return [self::distancesOf($stored), (string) $stored['water_checked_at']];
        }
        if ($canCompute) {
            return [$this->water->distances((float) $entry['latitude'], (float) $entry['longitude']), gmdate('Y-m-d H:i:s')];
        }

        return [['sea' => null, 'river' => null, 'lake' => null], null];
    }

    /**
     * The place for a generated name: the entry's own (transfer file), else
     * the stored one while the shelter did not move (found by the places step).
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed>|null $stored
     */
    private function placeFor(array $entry, ?array $stored): string
    {
        if (isset($entry['place']) && $entry['place'] !== '') {
            return mb_substr((string) $entry['place'], 0, 120);
        }
        if ($stored !== null
            && (float) $stored['latitude'] === round((float) $entry['latitude'], 7)
            && (float) $stored['longitude'] === round((float) $entry['longitude'], 7)) {
            return (string) $stored['place'];
        }

        return '';
    }

    /**
     * Entries that need a place for their name (no own name; without $all
     * only those without a place yet): id, latitude, longitude.
     *
     * @return list<array<string,mixed>>
     */
    public function findWithoutName(bool $all): array
    {
        return $this->db->query(
            "SELECT id, latitude, longitude FROM shelter_import WHERE name = ''" . ($all ? '' : " AND place = ''") . ' ORDER BY id'
        )->fetchAll();
    }

    /** @param array<int,string> $places entry id => place */
    public function setPlaces(array $places): void
    {
        $stmt = $this->db->prepare('UPDATE shelter_import SET place = ? WHERE id = ?');
        foreach ($places as $id => $place) {
            $stmt->execute([mb_substr($place, 0, 120), (int) $id]);
        }
    }

    /**
     * The stored rows of the given entries' sources, keyed "source\nsource_ref".
     *
     * @param list<array<string,mixed>> $entries
     * @return array<string, array<string,mixed>>
     */
    private function storedPositions(array $entries): array
    {
        $result = [];
        $stmt = $this->db->prepare('SELECT source, source_ref, latitude, longitude, sea_m, river_m, lake_m, water_checked_at, place FROM shelter_import WHERE source = ?');
        foreach (array_unique(array_column($entries, 'source')) as $source) {
            $stmt->execute([$source]);
            foreach ($stmt->fetchAll() as $row) {
                $result[$row['source'] . "\n" . $row['source_ref']] = $row;
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $row a shelter_import row
     * @return array{sea:?int, river:?int, lake:?int}
     */
    public static function distancesOf(array $row): array
    {
        $result = [];
        foreach (self::DISTANCE_COLUMNS as $kind => $column) {
            $result[$kind] = ($row[$column] ?? null) === null ? null : (int) $row[$column];
        }

        return $result;
    }

    /**
     * Computes the distances to water again from the index: for the entries
     * never computed, or for all ($all - after a new index). Returns how many
     * were computed; 0 without an index.
     *
     * @param callable(int):void|null $progress called with the number done so far
     */
    public function recomputeDistances(bool $all, ?callable $progress = null): int
    {
        if ($this->water === null || !$this->water->available()) {
            return 0;
        }
        $rows = $this->db->query(
            'SELECT id, latitude, longitude FROM shelter_import' . ($all ? '' : ' WHERE water_checked_at IS NULL') . ' ORDER BY latitude, longitude'
        )->fetchAll();
        $update = $this->db->prepare('UPDATE shelter_import SET sea_m = ?, river_m = ?, lake_m = ?, water_checked_at = UTC_TIMESTAMP() WHERE id = ?');
        foreach ($rows as $i => $row) {
            $d = $this->water->distances((float) $row['latitude'], (float) $row['longitude']);
            $update->execute([$d['sea'], $d['river'], $d['lake'], (int) $row['id']]);
            if ($progress !== null && ($i + 1) % 1000 === 0) {
                $progress($i + 1);
            }
        }

        return count($rows);
    }

    /** Entries whose distances to water are not computed yet (no index when they were loaded). */
    public function countUnchecked(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM shelter_import WHERE water_checked_at IS NULL')->fetchColumn();
    }

    /**
     * @param array<string,string> $filters name, description (substring), country, source, kind (exactly),
     *        max_sea / max_river / max_lake (metres, at most), max_any (the nearest of the three, at most),
     *        water (checked/unchecked: distances computed or not), status (open/imported),
     *        duplicate (yes/no: a shelter POI - not the entry's own - within DUPLICATE_DEGREES)
     * @return list<array<string,mixed>>
     */
    public function search(array $filters, ?int $limit, int $offset = 0): array
    {
        [$where, $params] = $this->whereFor($filters);
        $sql = 'SELECT s.*, ' . self::DISPLAY_NAME . ' AS display_name FROM shelter_import s' . $where . ' ORDER BY s.country, display_name, s.id';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param array<string,string> $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->whereFor($filters);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM shelter_import s' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,string> $filters */
    public function countOpen(array $filters): int
    {
        return ($filters['status'] ?? '') === 'imported' ? 0 : $this->count(array_merge($filters, ['status' => 'open']));
    }

    /**
     * @param array<string,string> $filters
     * @return list<int>
     */
    public function ids(array $filters): array
    {
        [$where, $params] = $this->whereFor($filters);
        $stmt = $this->db->prepare('SELECT s.id FROM shelter_import s' . $where . ' ORDER BY s.country, ' . self::DISPLAY_NAME . ', s.id');
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>> the entries with these ids that are not imported yet
     */
    public function findOpenByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM shelter_import WHERE poi_id IS NULL AND id IN ('
            . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id'
        );
        $stmt->execute(array_values($ids));

        return $stmt->fetchAll();
    }

    public function markImported(int $id, int $poiId): void
    {
        $this->db->prepare('UPDATE shelter_import SET poi_id = ?, imported_at = NOW() WHERE id = ?')->execute([$poiId, $id]);
    }

    /**
     * The neighbours of the given entries within DUPLICATE_DEGREES: shelter
     * POIs (not the entry's own) - the possible duplicates - and, as a hint
     * only, other sources' entries (`imported`: whether that one is a POI already).
     *
     * @param list<array<string,mixed>> $rows staged entries (a page of the list)
     * @return array<int, list<array{kind:string, id:int, source:string, name:string, imported:bool}>> keyed by entry id
     */
    public function neighbours(array $rows): array
    {
        $staged = $this->db->prepare(
            'SELECT id, source, name, poi_id FROM shelter_import WHERE source <> ? AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ? ORDER BY id LIMIT 10'
        );
        $pois = $this->db->prepare(
            'SELECT id, name FROM poi WHERE poitype_id = ? AND id <> ? AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ? ORDER BY id LIMIT 10'
        );
        $result = [];
        foreach ($rows as $row) {
            $lat = (float) $row['latitude'];
            $lng = (float) $row['longitude'];
            $dLng = self::DUPLICATE_DEGREES / max(0.1, cos(deg2rad($lat)));
            $box = [$lat - self::DUPLICATE_DEGREES, $lat + self::DUPLICATE_DEGREES, $lng - $dLng, $lng + $dLng];

            $found = [];
            $staged->execute(array_merge([$row['source']], $box));
            foreach ($staged->fetchAll() as $other) {
                $found[] = ['kind' => 'staged', 'id' => (int) $other['id'], 'source' => (string) $other['source'], 'name' => (string) $other['name'], 'imported' => $other['poi_id'] !== null];
            }
            $pois->execute(array_merge([self::POI_TYPE_SHELTER, (int) ($row['poi_id'] ?? 0)], $box));
            foreach ($pois->fetchAll() as $poi) {
                $found[] = ['kind' => 'poi', 'id' => (int) $poi['id'], 'source' => '', 'name' => (string) $poi['name'], 'imported' => true];
            }
            if ($found !== []) {
                $result[(int) $row['id']] = $found;
            }
        }

        return $result;
    }

    /** @return list<array<string,mixed>> every staged entry, for the transfer file */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM shelter_import ORDER BY id')->fetchAll();
    }

    /** @return list<string> the distinct country codes, for the filter's drop-down */
    public function countries(): array
    {
        return $this->db->query("SELECT DISTINCT country FROM shelter_import WHERE country <> '' ORDER BY country")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param array<string,string> $filters
     * @return array{0:string, 1:list<string>}
     */
    private function whereFor(array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach (self::TEXT_FILTERS as $filter => $column) {
            $value = trim((string) ($filters[$filter] ?? ''));
            if ($value !== '') {
                $conditions[] = "$column LIKE ?";
                $params[] = '%' . addcslashes($value, '%_\\') . '%';
            }
        }
        foreach (['country' => 's.country', 'kind' => 's.kind', 'source' => 's.source'] as $filter => $column) {
            $value = trim((string) ($filters[$filter] ?? ''));
            if ($value !== '') {
                $conditions[] = "$column = ?";
                $params[] = $value;
            }
        }
        foreach (['max_sea' => 's.sea_m', 'max_river' => 's.river_m', 'max_lake' => 's.lake_m'] as $filter => $column) {
            $max = (int) ($filters[$filter] ?? 0);
            if ($max > 0) {
                $conditions[] = "$column <= ?";
                $params[] = $max;
            }
        }
        $maxAny = (int) ($filters['max_any'] ?? 0);
        if ($maxAny > 0) {
            $conditions[] = '(s.sea_m <= ? OR s.river_m <= ? OR s.lake_m <= ?)';
            array_push($params, $maxAny, $maxAny, $maxAny);
        }
        $water = (string) ($filters['water'] ?? '');
        if ($water === 'checked') {
            $conditions[] = 's.water_checked_at IS NOT NULL';
        } elseif ($water === 'unchecked') {
            $conditions[] = 's.water_checked_at IS NULL';
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'open') {
            $conditions[] = 's.poi_id IS NULL';
        } elseif ($status === 'imported') {
            $conditions[] = 's.poi_id IS NOT NULL';
        }
        $duplicate = (string) ($filters['duplicate'] ?? '');
        if ($duplicate === 'yes') {
            $conditions[] = self::DUPLICATE_CONDITION;
        } elseif ($duplicate === 'no') {
            $conditions[] = 'NOT ' . self::DUPLICATE_CONDITION;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }
}
