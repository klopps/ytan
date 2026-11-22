<?php

declare(strict_types=1);

namespace Ytan\Service\LighthouseList;

/**
 * Turns the three layers of Väylävirasto's open WFS (Finland, CC BY 4.0) into staging entries (source "fi", the
 * shape LighthouseImportRepository::upsertMany() takes). What the layers look like (checked 2026-11-22):
 *
 *  - `turvalaitteet_vaylavirasto` = the navigation aids: `turvalaitenumero` (Väylävirasto's own number, no
 *    international one exists in the data - it goes into `dk_no`), `nimifi`/`nimisv`, `sijaintifi` (a sentence
 *    about where it stands), `turvalaitetyyppifi` (Merimajakka = sea lighthouse, Tunnusmajakka, Sektoriloisto =
 *    sector light, Apuloisto = auxiliary light; the client asks for these types only), `toimintatilakoodi`
 *    (1 = in operation; 0, 2 and 4 occur and are left out - the meaning of those isn't documented);
 *  - `loistot_uusi` = the lights of an aid (`turvalaitenumero`): `viralvalotunnuse` the characteristic in the
 *    international spelling ("Fl (2) 6 s"; `virallvalotunnus` is the Finnish one, "V-B (2) 6 s"),
 *    `korkeusvedesta` the height above water, `optinenkanto`/`maantietkanto` the optical/geographic range in
 *    nautical miles (one range for all colours - the sectors have none of their own), `laji` Yövalo = night
 *    light / Päivävalo = day light, `laatu` Pääloisto = main light / Lisäloisto = additional light, `id`;
 *  - `valosektorit_uusi` = the sectors of a light (`loisto` = its `id`): `alkukulma`/`loppukulma` start and end
 *    bearing, `vari` the colour (v white, p red, vi green, k yellow - yellow is left out like everywhere else).
 *    ASSUMPTION, not yet checked against a known light: the bearings are seen from the sea like in the official
 *    lists (Kystverket's are, measured against its sector polygons).
 *
 * An aid can have several lights (46 of 4,845 do); one entry per aid: the night light with sectors, else the main
 * light, else the one with the lowest id.
 */
final class FinnishLightMapper
{
    public const SOURCE = 'fi';

    private const COLOURS = ['v' => 'W', 'p' => 'R', 'vi' => 'G'];

    /**
     * @param list<array<string,mixed>> $aids
     * @param list<array<string,mixed>> $lights rows of loistot_uusi with `lat`/`lon`
     * @param list<array<string,mixed>> $sectors
     * @return list<array<string,mixed>> staging entries
     */
    public function map(array $aids, array $lights, array $sectors): array
    {
        $aidByNumber = [];
        foreach ($aids as $aid) {
            if ((int) ($aid['toimintatilakoodi'] ?? 0) === 1 && trim((string) ($aid['turvalaitenumero'] ?? '')) !== '') {
                $aidByNumber[(string) $aid['turvalaitenumero']] = $aid;
            }
        }

        $sectorsByLight = [];
        foreach ($sectors as $sector) {
            if (isset($sector['loisto'])) {
                $sectorsByLight[(int) $sector['loisto']][] = $sector;
            }
        }

        $lightsByAid = [];
        foreach ($lights as $light) {
            $number = (string) ($light['turvalaitenumero'] ?? '');
            if (isset($aidByNumber[$number]) && isset($light['lat'], $light['lon'])) {
                $lightsByAid[$number][] = $light;
            }
        }

        $entries = [];
        foreach ($lightsByAid as $number => $group) {
            usort($group, function (array $a, array $b) use ($sectorsByLight): int {
                return [$this->rank($b, $sectorsByLight), (int) $a['id']] <=> [$this->rank($a, $sectorsByLight), (int) $b['id']];
            });
            $main = $group[0];
            $entries[] = $this->entry($aidByNumber[$number], $main, $this->usableSectors($sectorsByLight[(int) $main['id']] ?? []));
        }

        usort($entries, fn (array $a, array $b) => strcmp($a['source_key'], $b['source_key']));

        return $entries;
    }

    /** Higher = preferred: night light (not a day light), with sectors, a main light. */
    private function rank(array $light, array $sectorsByLight): int
    {
        return (($light['laji'] ?? '') !== 'Päivävalo' ? 4 : 0)
            + (isset($sectorsByLight[(int) $light['id']]) ? 2 : 0)
            + (($light['laatu'] ?? '') === 'Pääloisto' ? 1 : 0);
    }

    /**
     * @param list<array<string,mixed>> $sectors
     * @return list<array{colour:string, start:float, end:float}>
     */
    private function usableSectors(array $sectors): array
    {
        usort($sectors, fn (array $a, array $b) => (int) $a['id'] <=> (int) $b['id']);
        $usable = [];
        foreach ($sectors as $sector) {
            $colour = self::COLOURS[(string) ($sector['vari'] ?? '')] ?? null;
            if ($colour !== null && is_numeric($sector['alkukulma'] ?? null) && is_numeric($sector['loppukulma'] ?? null)) {
                $usable[] = ['colour' => $colour, 'start' => (float) $sector['alkukulma'], 'end' => (float) $sector['loppukulma']];
            }
        }

        return $usable;
    }

    /**
     * @param array<string,mixed> $aid
     * @param array<string,mixed> $light
     * @param list<array{colour:string, start:float, end:float}> $sectors
     * @return array<string,mixed>
     */
    private function entry(array $aid, array $light, array $sectors): array
    {
        $range = null;
        foreach (['optinenkanto', 'maantietkanto'] as $field) {
            if (is_numeric($light[$field] ?? null) && (float) $light[$field] > 0) {
                $range = (float) $light[$field];
                break;
            }
        }

        $lines = [];
        $missing = false;
        foreach ($sectors as $sector) {
            $lines[] = SectorLightConverter::line($sector['colour'], $range, $sector['start'], $sector['end']);
            $missing = $missing || $range === null;
        }

        $fi = trim((string) ($aid['nimifi'] ?? ''), " .\t");
        $sv = trim((string) ($aid['nimisv'] ?? ''), " .\t");
        $name = $sv !== '' && mb_strtolower($sv) !== mb_strtolower($fi) ? ($fi !== '' ? $fi . ' / ' . $sv : $sv) : $fi;

        $remarks = array_filter([
            (string) ($aid['turvalaitetyyppifi'] ?? ''),
            (string) ($light['valoominaisuusfi'] ?? ''),
            is_string($light['tarkkavalotunnus'] ?? null) ? 'Tarkka tunnus: ' . $light['tarkkavalotunnus'] : '',
        ], fn ($part) => trim($part) !== '');

        return [
            'source_key' => 'light/' . (int) $light['id'],
            'source_ref' => 'light/' . (int) $light['id'],
            'dk_no' => mb_substr((string) $aid['turvalaitenumero'], 0, 20),
            'international_id' => '',
            'region' => 'Suomi / Finland',
            'subregion' => mb_substr((string) ($aid['turvalaitetyyppifi'] ?? ''), 0, 150),
            'name' => mb_substr($name !== '' ? $name : 'Finland ' . $aid['turvalaitenumero'], 0, 200),
            'location' => mb_substr(rtrim(trim((string) ($aid['sijaintifi'] ?? '')), '.'), 0, 300),
            'latitude' => round((float) $light['lat'], 7),
            'longitude' => round((float) $light['lon'], 7),
            'characteristic' => mb_substr($this->characteristic((string) ($light['viralvalotunnuse'] ?? '')), 0, 50),
            'other_signals' => '',
            'flame_height' => is_numeric($light['korkeusvedesta'] ?? null) ? SectorLightConverter::formatNumber((float) $light['korkeusvedesta']) : '',
            'light_range' => $range === null ? '' : SectorLightConverter::formatNumber($range) . ' M',
            'appearance' => '',
            'remarks' => implode("\n", $remarks),
            'sector_characteristic' => $lines === [] ? null : implode("\n", $lines),
            'sector_missing_range' => $missing,
        ];
    }

    /** "Fl (2) 6 s" -> "Fl(2) 6s": the spelling of the other sources (group without a space, period without a space). */
    private function characteristic(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $text = preg_replace('/\s+\(/', '(', $text) ?? $text;

        return preg_replace('/(\d)\s+s\b/', '$1s', $text) ?? $text;
    }
}
