<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Service\LighthouseList\SectorLightConverter;

/**
 * Turns staged lighthouse list entries into lighthouse POIs.
 *
 * lighthouse.international_id is not unique, but importing a number that
 * already belongs to a lighthouse (in the database, or twice in one selection)
 * is a conflict the admin is warned about and decides on PER ENTRY: add it as
 * another lighthouse, overwrite the existing one, or skip it - see the
 * CONFLICT_* actions of import().
 */
final class LighthouseImportService
{
    /** Create nothing, report the conflicts so the admin can decide. */
    public const CONFLICT_ASK = 'ask';
    /** Add the entry as a lighthouse of its own, next to the existing one. */
    public const CONFLICT_ADD = 'add';
    /** Overwrite the (oldest) existing lighthouse with the list entry. */
    public const CONFLICT_OVERWRITE = 'overwrite';
    /** Leave the existing lighthouse alone and don't import the entry. */
    public const CONFLICT_SKIP = 'skip';

    public const ACTIONS = [self::CONFLICT_ADD, self::CONFLICT_OVERWRITE, self::CONFLICT_SKIP];

    /** Name of each list as it appears in a POI's description. */
    private const SOURCE_NAMES = ['dk' => 'Dansk Fyrliste', 'nga' => 'NGA List of Lights'];

    /** Ranges (nautical miles) assumed for sectors the list gives none for, per colour. */
    public const DEFAULT_RANGES = ['W' => 3.0, 'R' => 2.0, 'G' => 2.0];

    public function __construct(
        private readonly LighthouseImportRepository $staging,
        private readonly PoiRepository $pois,
    ) {
    }

    /**
     * Imports the selected entries that are not imported yet (already
     * imported ones are skipped, so nothing is created twice).
     *
     * What happens to a conflicting entry is $decisions[its id] (an action),
     * else $onConflict. With CONFLICT_ASK as that fallback nothing is imported
     * as long as any conflicting entry has no decision yet; the result then
     * lists the conflicts and the caller asks again with a decision for each.
     *
     * @param list<int> $ids staging ids
     * @param array<string,float> $defaultRanges range per colour (W, R, G) for sectors without one in the list
     * @param array<int,string> $decisions staging id => one of ACTIONS
     * @return array{imported:int, overwritten:int, skipped:int, conflicts:list<array<string,mixed>>}
     */
    public function import(
        array $ids,
        int $userId,
        bool $public,
        string $onConflict = self::CONFLICT_ASK,
        array $defaultRanges = self::DEFAULT_RANGES,
        array $decisions = []
    ): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $entries = $this->staging->findOpenByIds($ids);
        $alreadyImported = count($ids) - count($entries);

        if ($onConflict === self::CONFLICT_ASK) {
            $conflicts = array_values(array_filter(
                $this->conflictsOf($entries),
                fn ($conflict) => !isset($decisions[$conflict['id']])
            ));
            if ($conflicts !== []) {
                return ['imported' => 0, 'overwritten' => 0, 'skipped' => 0, 'conflicts' => $conflicts];
            }
        }

        $imported = 0;
        $overwritten = 0;
        $skipped = $alreadyImported;
        foreach ($entries as $entry) {
            // Looked up per entry, not once up front: two selected entries
            // may share a number, and the second then conflicts with the
            // POI the first one just created.
            $existingPoiId = $entry['international_id'] !== ''
                ? $this->pois->findPoiIdByInternationalId($entry['international_id'])
                : null;

            $action = $existingPoiId === null ? self::CONFLICT_ADD : ($decisions[(int) $entry['id']] ?? $onConflict);

            if ($action === self::CONFLICT_ADD) {
                $poi = $this->pois->create($userId, $this->poiData($entry, $public, $defaultRanges));
                $imported++;
            } elseif ($action === self::CONFLICT_OVERWRITE) {
                // The POI keeps its owner and visibility - only the content comes from the list.
                $existing = $this->pois->findById($existingPoiId);
                $poi = $this->pois->update($existingPoiId, $this->poiData($entry, (bool) $existing['public'], $defaultRanges));
                $overwritten++;
            } else {
                $skipped++;
                continue;
            }
            $this->pois->markLighthouseImported((int) $poi['id']);
            $this->staging->markImported((int) $entry['id'], (int) $poi['id']);
        }

        return ['imported' => $imported, 'overwritten' => $overwritten, 'skipped' => $skipped, 'conflicts' => []];
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return list<array<string,mixed>> per conflicting entry: its id/name/number and the existing lighthouse
     *         (existing_poi_id null when the number only occurs more than once in the selection)
     */
    private function conflictsOf(array $entries): array
    {
        $conflicts = [];
        $claimed = []; // number => staging id of the first selected entry using it
        foreach ($entries as $entry) {
            $number = (string) $entry['international_id'];
            if ($number === '') {
                continue;
            }

            $existingPoiId = $this->pois->findPoiIdByInternationalId($number);
            if ($existingPoiId !== null) {
                $existing = $this->pois->findById($existingPoiId);
                $conflicts[] = [
                    'id' => (int) $entry['id'],
                    'international_id' => $number,
                    'name' => $entry['name'],
                    'existing_poi_id' => $existingPoiId,
                    'existing_name' => $existing['name'],
                    'existing_count' => $this->pois->countPoisByInternationalId($number),
                ];
            } elseif (isset($claimed[$number])) {
                $conflicts[] = [
                    'id' => (int) $entry['id'],
                    'international_id' => $number,
                    'name' => $entry['name'],
                    'existing_poi_id' => null,
                    'existing_name' => null,
                    'existing_count' => 0,
                ];
            }
            $claimed[$number] ??= (int) $entry['id'];
        }

        return $conflicts;
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,float> $defaultRanges
     * @return array<string,mixed> POI payload
     */
    private function poiData(array $entry, bool $public, array $defaultRanges): array
    {
        // Sectors the list gives no range for get the default of their colour,
        // and the description says so.
        $sector = $entry['sector_characteristic'];
        $assumed = false;
        if ($sector !== null) {
            ['text' => $sector, 'applied' => $assumed] = SectorLightConverter::applyDefaultRanges($sector, $defaultRanges);
        }

        return [
            'poitype_id' => PoiRepository::TYPE_LIGHTHOUSE,
            'name' => $this->poiName($entry),
            'description' => $this->description($entry, $assumed ? $defaultRanges : null),
            'public' => $public ? 1 : 0,
            'latitude' => $entry['latitude'],
            'longitude' => $entry['longitude'],
            'characteristic' => $entry['characteristic'] !== '' ? $entry['characteristic'] : null,
            'sector_characteristic' => $sector,
            'international_id' => $entry['international_id'],
        ];
    }

    private function formatRange(float $range): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($range, 2, '.', ''), '0'), '.'));
    }

    /** @param array<string,mixed> $entry */
    private function poiName(array $entry): string
    {
        $name = trim((string) $entry['name']);

        return $name !== '' ? $name : (self::SOURCE_NAMES[$entry['source'] ?? 'dk'] ?? 'Leuchtfeuer') . ' ' . $entry['dk_no'];
    }

    /**
     * Location, appearance and the list's remarks (sector angles included -
     * they are also in sector_characteristic, but the original wording is the
     * reference when a conversion looks odd), then where the entry is from.
     *
     * @param array<string,mixed> $entry
     * @param array<string,float>|null $assumedRanges the ranges that were assumed for sectors without one, if any
     */
    private function description(array $entry, ?array $assumedRanges): string
    {
        $parts = [];
        foreach (['location', 'appearance', 'remarks'] as $field) {
            $text = trim((string) $entry[$field]);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        if ($assumedRanges !== null) {
            $parts[] = sprintf(
                'Es sind keine Leuchtweiten bekannt. Es wurden %s, %s und %s Seemeilen für Weiß, Rot und Grün angenommen.',
                $this->formatRange($assumedRanges['W']),
                $this->formatRange($assumedRanges['R']),
                $this->formatRange($assumedRanges['G'])
            );
        }

        $list = (string) ($entry['source'] ?? 'dk');
        $source = [];
        if ($entry['dk_no'] !== '') {
            $source[] = ($list === 'dk' ? 'Dansk nr. ' : 'Nr. ') . $entry['dk_no'];
        }
        if ($entry['international_id'] !== '') {
            $source[] = 'Int. nr. ' . $entry['international_id'];
        }
        if ($source !== []) {
            $parts[] = '(' . (self::SOURCE_NAMES[$list] ?? $list) . ': ' . implode(', ', $source) . ')';
        }

        return implode("
", $parts);
    }
}
