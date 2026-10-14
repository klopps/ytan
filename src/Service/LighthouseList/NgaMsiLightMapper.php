<?php

declare(strict_types=1);

namespace Ytan\Service\LighthouseList;

/**
 * Turns the rows of the NGA Maritime Safety Information web service
 * (`/api/publications/ngalol/lights-buoys?volume=N&includeRemovals=false&output=json`,
 * the structured form of the List of Lights, Pub. 110-116) into staging
 * entries (source "ngaapi", the shape LighthouseImportRepository::upsertMany()
 * takes).
 *
 * What a row looks like (checked on volume 116):
 *  - `featureNumber` "4\nC0277": list number, line break, international number
 *    (written with two decimals, "C0970.60" for the "C 0970.6" the PDF prints -
 *    trailing zeros are dropped to get YTAN's international_id);
 *  - `position` "59°03'38.11\"N \n11°09'21.8\"E" (seconds, more exact than the
 *    PDF's tenths of a minute);
 *  - `characteristic` "Fl.W.R.G.\nperiod 3s \n", `heightFeetMeters` "31\n9"
 *    (feet, metres), `range` "W. 6 ; R. 5 ; G. 4", `remarks` with the sectors
 *    ("G. 177°54`-204°42`, W.-224°42`" - the minute sign is a backtick);
 *  - the headings (`geopoliticalHeading`, `regionHeading`, `subregionHeading`)
 *    are repeated on every row;
 *  - one light can have several rows (`charNo` 1, 2, ...): a racon, or a
 *    second characteristic (e.g. the sector light of a range light);
 *  - names carry the list's quirks: trailing full stop, "-Rear, ..." for the
 *    second light of a pair, "053^" for 053°.
 * The service's data is a snapshot of the list kept by Notices to Mariners;
 * the monthly PDF can be newer (see todo.md), the positions are more exact.
 */
final class NgaMsiLightMapper
{
    public const SOURCE = 'ngaapi';

    /**
     * @param list<array<string,mixed>> $rows the rows of one volume, in list order
     * @return list<array<string,mixed>> staging entries
     */
    public function mapVolume(array $rows): array
    {
        // Rows of one light follow each other (same featureNumber).
        $groups = [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['featureNumber'] ?? '')) === '') {
                continue;
            }
            $key = (string) $row['featureNumber'];
            if ($groups !== [] && array_key_last($groups) === $key) {
                $groups[$key][] = $row;
            } else {
                // The same number again later (not directly after) starts a group of its own.
                $unique = isset($groups[$key]) ? $key . "\0" . count($groups) : $key;
                $groups[$unique] = [$row];
            }
        }

        $entries = [];
        $seen = [];
        foreach ($groups as $group) {
            $entry = $this->mapLight($group);
            if ($entry === null) {
                continue;
            }
            $seen[$entry['source_key']] = ($seen[$entry['source_key']] ?? 0) + 1;
            if ($seen[$entry['source_key']] > 1) {
                $entry['source_key'] .= '#' . $seen[$entry['source_key']];
            }
            $entries[] = $entry;
        }

        return NgaLightText::completeSiblingNames($entries);
    }

    /**
     * @param list<array<string,mixed>> $group the rows of one light
     * @return array<string,mixed>|null
     */
    private function mapLight(array $group): ?array
    {
        usort($group, fn ($a, $b) => (int) ($a['charNo'] ?? 1) <=> (int) ($b['charNo'] ?? 1));

        // Racons are no lights.
        $lights = [];
        foreach ($group as $row) {
            [$characteristic] = NgaLightText::characteristic($this->lines((string) ($row['characteristic'] ?? '')));
            if (!NgaLightText::isRacon(trim((string) ($row['name'] ?? '')), $characteristic)) {
                $lights[] = $row + ['_characteristic' => $characteristic];
            }
        }
        if ($lights === []) {
            return null;
        }
        $main = $lights[0];

        $position = null;
        foreach ($lights as $row) {
            $position = $this->position((string) ($row['position'] ?? ''));
            if ($position !== null) {
                break;
            }
        }
        if ($position === null) {
            return null;
        }

        // List number and international number.
        $numberLines = $this->lines((string) $main['featureNumber']);
        $number = $numberLines[0] ?? '';
        $internationalId = $this->internationalId($numberLines[1] ?? '');

        // Sectors from every row that has any (a range light's sector light is a second row);
        // characteristic and ranges come from the first row with sectors, else from the main row.
        $sectorLines = [];
        $missingRange = false;
        $sectorRow = null;
        foreach ($lights as $row) {
            $sector = SectorLightConverter::convert($this->normalizeText((string) ($row['remarks'] ?? '')), $this->ranges((string) ($row['range'] ?? '')));
            if ($sector['text'] !== null) {
                $sectorRow ??= $row;
                foreach (explode("\n", $sector['text']) as $line) {
                    $sectorLines[$line] = true;
                }
                $missingRange = $missingRange || $sector['missingRange'];
            }
        }
        $described = $sectorRow ?? $main;
        [$characteristic, $otherSignals] = NgaLightText::characteristic($this->lines((string) ($described['characteristic'] ?? '')));

        $remarks = [];
        foreach ($lights as $row) {
            $text = trim($this->normalizeText((string) ($row['remarks'] ?? '')));
            if ($text !== '') {
                $remarks[] = $text;
            }
        }

        $volume = preg_replace('/\D+/', '', (string) ($main['volumeNumber'] ?? '')) ?? '';
        $key = $volume . '|' . $number . '|' . $internationalId;
        $heights = $this->lines((string) ($main['heightFeetMeters'] ?? ''));
        $subregion = trim((string) ($main['regionHeading'] ?? ''), ': ');
        if (trim((string) ($main['subregionHeading'] ?? '')) !== '') {
            $subregion .= ($subregion !== '' ? ' / ' : '') . trim((string) $main['subregionHeading'], ': ');
        }

        return [
            'source_key' => mb_substr($key, 0, 190),
            'source_ref' => mb_substr('pub' . $volume . '/' . $number, 0, 60),
            'dk_no' => mb_substr($number, 0, 20),
            'international_id' => $internationalId,
            'region' => mb_substr(trim((string) ($main['geopoliticalHeading'] ?? '')), 0, 100),
            'subregion' => mb_substr($subregion, 0, 150),
            // A name can run over two lines ("...362 meters\n116^42' from front.").
            'name' => rtrim(trim(preg_replace('/\s+/u', ' ', $this->normalizeText((string) ($main['name'] ?? ''))) ?? ''), '. '),
            'location' => '',
            'latitude' => round($position[0], 7),
            'longitude' => round($position[1], 7),
            'characteristic' => mb_substr($characteristic, 0, 50),
            'other_signals' => mb_substr($otherSignals, 0, 200),
            'flame_height' => $heights[1] ?? '',
            'light_range' => mb_substr(implode('; ', array_map('trim', explode(';', (string) ($described['range'] ?? '')))), 0, 120),
            'appearance' => mb_substr(implode(' ', $this->lines((string) ($main['structure'] ?? ''))), 0, 300),
            'remarks' => implode("\n", $remarks),
            'sector_characteristic' => $sectorLines === [] ? null : implode("\n", array_keys($sectorLines)),
            'sector_missing_range' => $missingRange,
        ];
    }

    /**
     * "59°03'38.11\"N \n11°09'21.8\"E" -> [59.0605..., 11.1560...] (south and west negative).
     *
     * @return array{0:float, 1:float}|null
     */
    private function position(string $text): ?array
    {
        if (preg_match_all('/(\d{1,3})\D+(\d{1,2})\D+(\d{1,2}(?:\.\d+)?)\D*([NSEW])/u', $text, $matches, PREG_SET_ORDER) < 2) {
            return null;
        }

        $values = [];
        foreach (array_slice($matches, 0, 2) as $m) {
            $value = (int) $m[1] + (int) $m[2] / 60 + (float) $m[3] / 3600;
            $values[] = in_array($m[4], ['S', 'W'], true) ? -$value : $value;
        }

        return [$values[0], $values[1]];
    }

    /** "C0970.60" -> "C0970.6", "C 0317.01" -> "C0317.01"; anything else that isn't a list number -> "". */
    private function internationalId(string $text): string
    {
        $id = preg_replace('/\s+/', '', $text) ?? '';
        if (str_contains($id, '.')) {
            $id = rtrim(rtrim($id, '0'), '.');
        }

        return preg_match('/^[A-Z]{1,2}\d{3,5}(?:\.\d+)?$/', $id) === 1 ? $id : '';
    }

    /**
     * Range cell "W. 6 ; R. 5 ; G. 4" (or "6") -> ['W' => 6.0, 'R' => 5.0, 'G' => 4.0] (a bare number: key 'ANY').
     *
     * @return array<string,float>
     */
    private function ranges(string $text): array
    {
        $ranges = [];
        foreach (explode(';', $text) as $part) {
            $part = trim($part);
            if (preg_match('/^([WRGYB])\.?\s*(\d+(?:\.\d+)?)/', $part, $m) === 1) {
                $ranges[$m[1]] ??= (float) $m[2];
            } elseif (preg_match('/^(\d+(?:\.\d+)?)$/', $part, $m) === 1) {
                $ranges['ANY'] ??= (float) $m[1];
            }
        }

        return $ranges;
    }

    /** The list writes 053° as "053^" in names and notes. */
    private function normalizeText(string $text): string
    {
        return preg_replace('/(\d)\^/', '$1°', $text) ?? $text;
    }

    /** @return list<string> the non-empty trimmed lines of a multi-line cell */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($line) => $line !== ''));
    }
}
