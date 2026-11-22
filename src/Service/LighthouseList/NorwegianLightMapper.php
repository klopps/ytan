<?php

declare(strict_types=1);

namespace Ytan\Service\LighthouseList;

/**
 * Turns the lights and sectors of Kystverket's navigation installations WFS (Norway, NLOD 2.0) into staging
 * entries (source "no", the shape LighthouseImportRepository::upsertMany() takes). What the layers look like
 * (checked 2026-11-22):
 *
 *  - `Lys` = a light: `sjomerke_nr` (Kystverket's own number, a string with leading zeros - it goes into `dk_no`;
 *    no international number exists in the data), `navn`, `sted`, `beliggenhet`, `klasse` ("FS - Fyrstasjon" =
 *    lighthouse station, "FL - Fyrlykt" = lighthouse lantern, i.e. the fairway sector lights; the client asks for
 *    these two), `karakter` the characteristic in Kystverket's spelling ("Oc (2) WRG 8s", "Fast R lys"),
 *    `lys_hoyde` height in metres, `rekkevidde_hvit/_rod/_gronn/_gul` range per colour in nautical miles,
 *    `beskrivelse`, `globalid` (a GUID, the stable key);
 *  - `Sektorflate` = a sector of a light: `fknavinst` = the light's `globalid`, `lys_farge` (Hvit/Rød/Grønn/Gul),
 *    `retning_sektorlinje_1/_2` = start and end bearing. These are bearings as seen from the sea towards the
 *    light, like the official lists (verified 2026-11-22 on Sklinna: the drawn sector polygons lie exactly 180
 *    degrees from them), clockwise from line 1 to line 2; `sektor_nr` is the order.
 */
final class NorwegianLightMapper
{
    public const SOURCE = 'no';

    private const COLOURS = ['Hvit' => 'W', 'Rød' => 'R', 'Grønn' => 'G'];
    private const RANGE_FIELDS = ['W' => 'rekkevidde_hvit', 'R' => 'rekkevidde_rod', 'G' => 'rekkevidde_gronn'];

    /**
     * @param list<array<string,mixed>> $lights rows of Lys with `lat`/`lon`
     * @param list<array<string,mixed>> $sectors rows of Sektorflate
     * @return list<array<string,mixed>> staging entries
     */
    public function map(array $lights, array $sectors): array
    {
        $sectorsByLight = [];
        foreach ($sectors as $sector) {
            $guid = $this->guid((string) ($sector['fknavinst'] ?? ''));
            if ($guid !== '') {
                $sectorsByLight[$guid][] = $sector;
            }
        }

        $entries = [];
        foreach ($lights as $light) {
            $guid = $this->guid((string) ($light['globalid'] ?? ''));
            $class = substr((string) ($light['klasse'] ?? ''), 0, 2);
            if ($guid === '' || !in_array($class, ['FS', 'FL'], true) || !isset($light['lat'], $light['lon'])) {
                continue;
            }
            if (($light['nfs_status'] ?? 'Operativ') !== 'Operativ') {
                continue;
            }
            $entries[] = $this->entry($light, $guid, $sectorsByLight[$guid] ?? []);
        }

        usort($entries, fn (array $a, array $b) => strcmp($a['source_key'], $b['source_key']));

        return $entries;
    }

    /**
     * @param array<string,mixed> $light
     * @param list<array<string,mixed>> $sectors
     * @return array<string,mixed>
     */
    private function entry(array $light, string $guid, array $sectors): array
    {
        $ranges = [];
        foreach (self::RANGE_FIELDS as $colour => $field) {
            if (is_numeric($light[$field] ?? null) && (float) $light[$field] > 0) {
                $ranges[$colour] = (float) $light[$field];
            }
        }

        usort($sectors, fn (array $a, array $b) => (int) ($a['sektor_nr'] ?? 0) <=> (int) ($b['sektor_nr'] ?? 0));
        $lines = [];
        $missing = false;
        foreach ($sectors as $sector) {
            $colour = self::COLOURS[(string) ($sector['lys_farge'] ?? '')] ?? null;
            if ($colour === null || !is_numeric($sector['retning_sektorlinje_1'] ?? null) || !is_numeric($sector['retning_sektorlinje_2'] ?? null)) {
                continue;
            }
            $range = $ranges[$colour] ?? null;
            $missing = $missing || $range === null;
            $lines[] = SectorLightConverter::line($colour, $range, (float) $sector['retning_sektorlinje_1'], (float) $sector['retning_sektorlinje_2']);
        }

        $place = trim((string) ($light['sted'] ?? ''));
        $position = trim((string) ($light['beliggenhet'] ?? ''));
        $name = trim((string) ($light['navn'] ?? ''));
        $class = trim((string) preg_replace('/^[A-Z]{2}\s*-\s*/', '', (string) $light['klasse']));

        $rangeText = [];
        foreach ($ranges as $colour => $range) {
            $rangeText[] = $colour . ' ' . SectorLightConverter::formatNumber($range);
        }

        return [
            'source_key' => 'lys/' . $guid,
            'source_ref' => 'lys/' . $guid,
            'dk_no' => mb_substr(trim((string) ($light['sjomerke_nr'] ?? '')), 0, 20),
            'international_id' => '',
            'region' => 'Norge / Norway',
            'subregion' => mb_substr($class, 0, 150),
            'name' => mb_substr($name !== '' ? $name : ($place !== '' ? $place : 'Norge ' . ($light['sjomerke_nr'] ?? '')), 0, 200),
            'location' => mb_substr(implode(', ', array_filter([$place, $position !== '' && $position !== $place ? $position : ''])), 0, 300),
            'latitude' => round((float) $light['lat'], 7),
            'longitude' => round((float) $light['lon'], 7),
            'characteristic' => mb_substr($this->characteristic((string) ($light['karakter'] ?? '')), 0, 50),
            'other_signals' => '',
            'flame_height' => is_numeric($light['lys_hoyde'] ?? null) ? SectorLightConverter::formatNumber((float) $light['lys_hoyde']) : '',
            'light_range' => mb_substr(implode('; ', $rangeText), 0, 120),
            'appearance' => '',
            'remarks' => trim((string) ($light['beskrivelse'] ?? '')),
            'sector_characteristic' => $lines === [] ? null : implode("\n", $lines),
            'sector_missing_range' => $missing,
        ];
    }

    /** "{0011ADFF-...}" and "0011adff-..." -> the same lower-case GUID without braces; anything else -> "". */
    private function guid(string $text): string
    {
        $guid = strtolower(trim($text, " {}\t"));

        return preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $guid) === 1 ? $guid : '';
    }

    /** "Oc (2) WRG 8s" -> "Oc(2) WRG 8s", "Fast R lys" -> "F R": the spelling of the other sources. */
    private function characteristic(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $text = preg_replace('/\s+\(/', '(', $text) ?? $text;
        $text = preg_replace('/\bFast\b/u', 'F', $text) ?? $text;
        $text = preg_replace('/\s+lys$/iu', '', $text) ?? $text;

        return $text;
    }
}
