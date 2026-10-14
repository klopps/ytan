<?php

declare(strict_types=1);

namespace Ytan\Domain\LighthouseImport;

use PDO;

/**
 * The staging table behind the admin tool "Lighthouse import": entries read
 * from an official lighthouse list (bin/import-lighthouse-list.php), waiting
 * to be filtered, selected and turned into POIs.
 */
final class LighthouseImportRepository
{
    /** Columns the list file fills (everything except id, poi_id, imported_at). */
    private const LIST_COLUMNS = [
        'dk_no', 'international_id', 'region', 'subregion', 'name', 'location', 'latitude', 'longitude',
        'characteristic', 'other_signals', 'flame_height', 'light_range', 'appearance', 'remarks',
        'sector_characteristic', 'sector_missing_range',
    ];

    /** Filter name => column, for the text filters (substring match, case-insensitive). */
    private const TEXT_FILTERS = [
        'dk_no' => 'dk_no',
        'international_id' => 'international_id',
        'name' => 'name',
        'location' => 'location',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Inserts the entries of one list, or refreshes the ones already there
     * (matched by source + source_key); whether an entry was imported already
     * is kept.
     *
     * @param list<array<string,mixed>> $entries parser output (DanishLighthouseListParser)
     * @return array{inserted:int, updated:int}
     */
    public function upsertMany(string $source, array $entries): array
    {
        $columns = array_merge(['source', 'source_key'], self::LIST_COLUMNS);
        $assignments = implode(', ', array_map(fn ($c) => "$c = VALUES($c)", self::LIST_COLUMNS));
        $stmt = $this->db->prepare(
            'INSERT INTO lighthouse_import (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ') ON DUPLICATE KEY UPDATE ' . $assignments
        );

        $inserted = 0;
        $updated = 0;
        // Already inside a transaction when a caller (a test) wrapped this in one.
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($entries as $entry) {
                $stmt->execute([
                    $source,
                    $entry['source_key'],
                    $entry['dk_no'],
                    $entry['international_id'],
                    $entry['region'],
                    $entry['subregion'],
                    mb_substr((string) $entry['name'], 0, 200),
                    mb_substr((string) $entry['location'], 0, 300),
                    $entry['latitude'],
                    $entry['longitude'],
                    mb_substr((string) $entry['characteristic'], 0, 50),
                    mb_substr((string) $entry['other_signals'], 0, 200),
                    mb_substr((string) $entry['flame_height'], 0, 60),
                    mb_substr((string) $entry['light_range'], 0, 120),
                    mb_substr((string) $entry['appearance'], 0, 300),
                    $entry['remarks'],
                    $entry['sector_characteristic'],
                    $entry['sector_missing_range'] ? 1 : 0,
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
     * @param array<string,string> $filters dk_no, international_id, name, location (text, substring),
     *        region (matches region or sub-region), source (exactly, e.g. "dk"/"nga"),
     *        status ("open" = not imported yet, "imported")
     * @return list<array<string,mixed>>
     */
    public function search(array $filters, ?int $limit, int $offset = 0): array
    {
        [$where, $params] = $this->whereFor($filters);
        $sql = 'SELECT * FROM lighthouse_import' . $where . ' ORDER BY id';
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
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM lighthouse_import' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Ids of every entry matching the filters ("select all", across pages).
     *
     * @param array<string,string> $filters
     * @return list<int>
     */
    public function ids(array $filters): array
    {
        [$where, $params] = $this->whereFor($filters);
        $stmt = $this->db->prepare('SELECT id FROM lighthouse_import' . $where . ' ORDER BY id');
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
            'SELECT * FROM lighthouse_import WHERE poi_id IS NULL AND id IN ('
            . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id'
        );
        $stmt->execute(array_values($ids));

        return $stmt->fetchAll();
    }

    public function markImported(int $id, int $poiId): void
    {
        $this->db->prepare('UPDATE lighthouse_import SET poi_id = ?, imported_at = NOW() WHERE id = ?')
            ->execute([$poiId, $id]);
    }

    /**
     * Every staged entry, for the transfer file (see LighthouseImportFile).
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM lighthouse_import ORDER BY source, id')->fetchAll();
    }

    /** @return list<string> the distinct regions, for the filter's drop-down */
    public function regions(): array
    {
        return $this->db->query("SELECT DISTINCT region FROM lighthouse_import WHERE region <> '' ORDER BY region")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param array<string,string> $filters
     * @return array{0:string, 1:list<string>} WHERE clause (with leading space, or empty) and its parameters
     */
    private function whereFor(array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach (self::TEXT_FILTERS as $filter => $column) {
            $value = trim((string) ($filters[$filter] ?? ''));
            if ($value === '') {
                continue;
            }
            // The international number is stored without spaces, so a search
            // for "B 1804" has to match "B1804" too.
            if ($filter === 'international_id') {
                $value = preg_replace('/\s+/u', '', $value) ?? $value;
            }
            $conditions[] = "$column LIKE ?";
            $params[] = '%' . addcslashes($value, '%_\\') . '%';
        }

        $region = trim((string) ($filters['region'] ?? ''));
        if ($region !== '') {
            $conditions[] = '(region LIKE ? OR subregion LIKE ?)';
            $like = '%' . addcslashes($region, '%_\\') . '%';
            array_push($params, $like, $like);
        }

        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '') {
            $conditions[] = 'source = ?';
            $params[] = $source;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'open') {
            $conditions[] = 'poi_id IS NULL';
        } elseif ($status === 'imported') {
            $conditions[] = 'poi_id IS NOT NULL';
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }
}
