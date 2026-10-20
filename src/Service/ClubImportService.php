<?php

declare(strict_types=1);

namespace Ytan\Service;

use PDO;
use Ytan\Domain\ClubImport\ClubImportRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Service\ClubList\ClubOsmMapper;

/**
 * Turns staged clubs (table club_import) into POIs of type 13 "Clubs /
 * Institutions": name, the club's website as the POI's link, and a
 * description with the kind of club, the address and the OpenStreetMap
 * attribution (ODbL). A club that already exists as a POI of that type
 * (same name, within DUPLICATE_RADIUS_DEGREES) is not created a second time -
 * the staged entry is linked to the existing POI instead.
 */
final class ClubImportService
{
    public const TYPE_CLUB = 13;
    /** About 100 m in latitude; the longitude window is widened by the latitude's cosine. */
    private const DUPLICATE_RADIUS_DEGREES = 0.001;

    public function __construct(
        private readonly ClubImportRepository $staging,
        private readonly PoiRepository $pois,
        private readonly PDO $db,
    ) {
    }

    /**
     * @param list<int> $ids staging ids; entries already imported are ignored
     * @return array{imported:int, linked:int, skipped:int}
     */
    public function import(array $ids, int $userId, bool $public): array
    {
        $entries = $this->staging->findOpenByIds($ids);
        $imported = 0;
        $linked = 0;
        foreach ($entries as $entry) {
            $existing = $this->findExisting((string) $entry['name'], (float) $entry['latitude'], (float) $entry['longitude']);
            if ($existing !== null) {
                $this->staging->markImported((int) $entry['id'], $existing);
                $linked++;
                continue;
            }

            $poi = $this->pois->create($userId, [
                'poitype_id' => self::TYPE_CLUB,
                'name' => mb_substr((string) $entry['name'], 0, 200),
                'description' => self::description($entry),
                'public' => $public ? 1 : 0,
                'latitude' => $entry['latitude'],
                'longitude' => $entry['longitude'],
                'url' => $entry['website'] !== '' ? $entry['website'] : null,
            ]);
            $this->staging->markImported((int) $entry['id'], (int) $poi['id']);
            $imported++;
        }

        return ['imported' => $imported, 'linked' => $linked, 'skipped' => count($ids) - count($entries)];
    }

    /** @param array<string,mixed> $entry */
    public static function description(array $entry): string
    {
        $kind = match ($entry['kind']) {
            ClubOsmMapper::KIND_ROWING => 'Ruderverein',
            ClubOsmMapper::KIND_BOTH => 'Kanu- und Ruderverein',
            default => 'Kanu-/Kajakverein',
        };
        $lines = [$kind];
        if ((string) $entry['address'] !== '') {
            $lines[] = 'Adresse: ' . $entry['address'];
        }
        $lines[] = 'Quelle: © OpenStreetMap-Mitwirkende (ODbL 1.0)';

        return implode("\n\n", $lines);
    }

    private function findExisting(string $name, float $lat, float $lng): ?int
    {
        $lngWindow = self::DUPLICATE_RADIUS_DEGREES / max(0.1, cos(deg2rad($lat)));
        $stmt = $this->db->prepare(
            'SELECT id FROM poi WHERE poitype_id = ? AND name = ? AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([
            self::TYPE_CLUB, $name,
            $lat - self::DUPLICATE_RADIUS_DEGREES, $lat + self::DUPLICATE_RADIUS_DEGREES,
            $lng - $lngWindow, $lng + $lngWindow,
        ]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
