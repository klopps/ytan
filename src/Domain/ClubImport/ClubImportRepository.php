<?php

declare(strict_types=1);

namespace Ytan\Domain\ClubImport;

use PDO;

/**
 * The staging table behind the admin tool "Club import": canoe and rowing
 * clubs read from OpenStreetMap, waiting to be filtered, selected and turned
 * into POIs.
 */
final class ClubImportRepository
{
    private const COLUMNS = ['name', 'kind', 'country', 'city', 'address', 'website', 'latitude', 'longitude'];

    private const TEXT_FILTERS = ['name' => 'name', 'city' => 'city', 'website' => 'website'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Inserts the entries or refreshes the ones already there (matched by
     * osm_ref); whether an entry was imported already is kept.
     *
     * @param list<array<string,mixed>> $entries ClubOsmMapper output
     * @return array{inserted:int, updated:int}
     */
    public function upsertMany(array $entries): array
    {
        $columns = array_merge(['osm_ref'], self::COLUMNS);
        $assignments = implode(', ', array_map(fn ($c) => "$c = VALUES($c)", self::COLUMNS));
        $stmt = $this->db->prepare(
            'INSERT INTO club_import (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ') ON DUPLICATE KEY UPDATE ' . $assignments
        );

        $inserted = 0;
        $updated = 0;
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($entries as $entry) {
                $stmt->execute([
                    $entry['osm_ref'],
                    mb_substr((string) $entry['name'], 0, 200),
                    $entry['kind'],
                    $entry['country'],
                    mb_substr((string) $entry['city'], 0, 150),
                    mb_substr((string) $entry['address'], 0, 300),
                    mb_substr((string) $entry['website'], 0, 500),
                    $entry['latitude'],
                    $entry['longitude'],
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
     * @param array<string,string> $filters name, city, website (substring), country (exactly),
     *        kind (canoe/rowing/both), status (open/imported)
     * @return list<array<string,mixed>>
     */
    public function search(array $filters, ?int $limit, int $offset = 0): array
    {
        [$where, $params] = $this->whereFor($filters);
        $sql = 'SELECT * FROM club_import' . $where . ' ORDER BY country, name, id';
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
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM club_import' . $where);
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
        $stmt = $this->db->prepare('SELECT id FROM club_import' . $where . ' ORDER BY country, name, id');
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
            'SELECT * FROM club_import WHERE poi_id IS NULL AND id IN ('
            . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id'
        );
        $stmt->execute(array_values($ids));

        return $stmt->fetchAll();
    }

    public function markImported(int $id, int $poiId): void
    {
        $this->db->prepare('UPDATE club_import SET poi_id = ?, imported_at = NOW() WHERE id = ?')->execute([$poiId, $id]);
    }

    /** @return list<array<string,mixed>> every staged entry, for the transfer file */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM club_import ORDER BY id')->fetchAll();
    }

    /** @return list<string> the distinct country codes, for the filter's drop-down */
    public function countries(): array
    {
        return $this->db->query("SELECT DISTINCT country FROM club_import WHERE country <> '' ORDER BY country")
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
        foreach (['country' => 'country', 'kind' => 'kind'] as $filter => $column) {
            $value = trim((string) ($filters[$filter] ?? ''));
            if ($value !== '') {
                $conditions[] = "$column = ?";
                $params[] = $value;
            }
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
