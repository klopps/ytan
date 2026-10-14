<?php

declare(strict_types=1);

namespace Ytan\Service\LighthouseList;

/**
 * Turns an OpenStreetMap node tagged with the OpenSeaMap seamark scheme
 * (`seamark:type=light_major|light_minor|light_vessel|light_float`) into a
 * staging entry of the lighthouse list import (the shape
 * LighthouseImportRepository::upsertMany() takes, source "osm").
 *
 * What the tags look like (checked against real data from Bornholm):
 *  - a light is described either by plain keys `seamark:light:character` /
 *    `:colour` / `:period` / `:range` / `:height` / `:group`, or - for sector
 *    lights - by numbered lights `seamark:light:1:...`, `seamark:light:2:...`,
 *    each with `sector_start`/`sector_end` (degrees, clockwise, bearings from
 *    seaward - the same convention as the official lists) and its own colour;
 *    plain keys are defaults for the numbered ones;
 *  - the list number ("C 2556") is in `seamark:light:reference`, in some places
 *    `seamark:reference` or `ref` - the same number YTAN stores as
 *    `international_id`, so it is the join key against the PDF lists;
 *  - the name is `seamark:name` (or plain `name`), a free text copy of the
 *    sectors may sit in `seamark:information`, `source` says where the
 *    mapper got the data from (often "US NGA Pub. 116. 2011").
 * Lights that are no navigation lights (aero, air obstruction, fog detector,
 * floodlight, strip light) and nodes without any light data are left out.
 */
final class OsmLightMapper
{
    public const SOURCE = 'osm';

    private const LIGHT_TYPES = ['light_major', 'light_minor', 'light_vessel', 'light_float'];
    private const OTHER_CATEGORIES = ['aero', 'air_obstruction', 'fog_detector', 'floodlight', 'strip_light'];
    /** OSM colour name => letter of the light characteristic; the overlay can draw W, R and G sectors only. */
    private const COLOURS = ['white' => 'W', 'red' => 'R', 'green' => 'G', 'yellow' => 'Y', 'amber' => 'Y', 'blue' => 'B'];
    private const SECTOR_COLOURS = ['W', 'R', 'G'];

    /** The seamark types an Overpass query for lights asks for. */
    public static function lightTypes(): array
    {
        return self::LIGHT_TYPES;
    }

    /**
     * @param array<string,mixed> $element one element of Overpass' answer (a node with `tags`, `lat`, `lon` or `center`)
     * @param string $region what to store as the entry's region (the area the admin asked for)
     * @return array<string,mixed>|null the staging entry, null for anything that isn't a navigation light
     */
    public function map(array $element, string $region): ?array
    {
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        if (!in_array($tags['seamark:type'] ?? '', self::LIGHT_TYPES, true)) {
            return null;
        }

        $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
        $lon = $element['lon'] ?? $element['center']['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon) || !isset($element['id'])) {
            return null;
        }

        $lights = $this->lights($tags);
        if ($lights === [] || $this->onlyOtherLights($lights)) {
            return null;
        }

        $ref = (($element['type'] ?? 'node') . '/' . $element['id']);
        [$sectors, $missingRange] = $this->sectors($lights);

        return [
            'source_key' => $ref,
            'source_ref' => $ref,
            'dk_no' => '',
            'international_id' => $this->internationalId($tags),
            'region' => mb_substr($region, 0, 100),
            'subregion' => '',
            'name' => trim((string) ($tags['seamark:name'] ?? $tags['name'] ?? '')),
            'location' => '',
            'latitude' => round((float) $lat, 7),
            'longitude' => round((float) $lon, 7),
            'characteristic' => $this->characteristic($lights),
            'other_signals' => $this->fogSignal($tags),
            'flame_height' => $this->firstNumber($lights, 'height'),
            'light_range' => $this->rangeText($lights),
            'appearance' => '',
            'remarks' => $this->remarks($tags),
            'sector_characteristic' => $sectors,
            'sector_missing_range' => $missingRange,
        ];
    }

    /**
     * The lights of a node, in numbered order; a node without numbered lights
     * has one (its plain keys), or none.
     *
     * @param array<string,mixed> $tags
     * @return list<array<string,string>>
     */
    private function lights(array $tags): array
    {
        $numbered = [];
        $plain = [];
        foreach ($tags as $key => $value) {
            if (preg_match('/^seamark:light:(\d+):([a-z_]+)$/', (string) $key, $m) === 1) {
                $numbered[(int) $m[1]][$m[2]] = trim((string) $value);
            } elseif (preg_match('/^seamark:light:([a-z_]+)$/', (string) $key, $m) === 1 && $m[1] !== 'reference') {
                $plain[$m[1]] = trim((string) $value);
            }
        }

        if ($numbered === []) {
            return $plain === [] ? [] : [$plain];
        }
        ksort($numbered);

        // Plain keys are defaults for every numbered light.
        return array_map(fn ($light) => $light + $plain, array_values($numbered));
    }

    /** @param list<array<string,string>> $lights */
    private function onlyOtherLights(array $lights): bool
    {
        foreach ($lights as $light) {
            $categories = array_filter(array_map('trim', explode(';', $light['category'] ?? '')));
            if ($categories === [] || array_diff($categories, self::OTHER_CATEGORIES) !== []) {
                return false;
            }
        }

        return true;
    }

    /** "C 2556" / "C2556" -> "C2556"; anything that isn't a list number is dropped. */
    private function internationalId(array $tags): string
    {
        foreach (['seamark:light:reference', 'seamark:reference', 'ref'] as $key) {
            $value = preg_replace('/\s+/u', '', (string) ($tags[$key] ?? '')) ?? '';
            if (preg_match('/^[A-Z]{1,2}\d{3,5}(?:\.\d+)?$/', $value) === 1) {
                return $value;
            }
        }

        return '';
    }

    /**
     * "Oc WRG 5s", "Fl(2) W 10s": rhythm (with group), the colours of all
     * lights and the period, as YTAN writes light characteristics.
     *
     * @param list<array<string,string>> $lights
     */
    private function characteristic(array $lights): string
    {
        $first = $lights[0];
        $rhythm = str_replace(' ', '', $first['character'] ?? '');
        $group = trim($first['group'] ?? '', '() ');
        if ($group !== '' && !str_contains($rhythm, '(')) {
            $rhythm .= '(' . $group . ')';
        }

        $present = [];
        foreach ($lights as $light) {
            foreach ($this->colourLetters($light['colour'] ?? '') as $letter) {
                $present[$letter] = true;
            }
        }
        $colours = implode('', array_filter(str_split('WRGYB'), fn ($letter) => isset($present[$letter])));

        $period = $this->firstNumber($lights, 'period');

        return mb_substr(trim(preg_replace('/\s+/', ' ', $rhythm . ' ' . $colours . ' ' . ($period !== '' ? $period . 's' : '')) ?? ''), 0, 50);
    }

    /**
     * Sector lines "G 8M 350.7-352.2" for every numbered light with both
     * limits and a colour the overlay can draw; a light without range gets
     * SectorLightConverter's placeholder (the import fills in a default).
     *
     * @param list<array<string,string>> $lights
     * @return array{0: ?string, 1: bool} the lines (null if none) and whether a placeholder was used
     */
    private function sectors(array $lights): array
    {
        $lines = [];
        $missing = false;
        foreach ($lights as $light) {
            $start = $light['sector_start'] ?? '';
            $end = $light['sector_end'] ?? '';
            $letters = $this->colourLetters($light['colour'] ?? '');
            if (!is_numeric($start) || !is_numeric($end) || count($letters) !== 1 || !in_array($letters[0], self::SECTOR_COLOURS, true)) {
                continue;
            }

            $range = is_numeric($light['range'] ?? null) ? (float) $light['range'] : null;
            $missing = $missing || $range === null;
            $lines[] = $letters[0] . ' ' . ($range === null ? SectorLightConverter::UNKNOWN_RANGE : SectorLightConverter::formatNumber($range)) . 'M '
                . SectorLightConverter::formatNumber((float) $start) . '-' . SectorLightConverter::formatNumber((float) $end);
        }

        return [$lines === [] ? null : implode("\n", $lines), $missing];
    }

    /** @return list<string> letters of "white" / "white;red" (unknown colours dropped) */
    private function colourLetters(string $colour): array
    {
        $letters = [];
        foreach (explode(';', strtolower($colour)) as $name) {
            $letter = self::COLOURS[trim($name)] ?? null;
            if ($letter !== null) {
                $letters[] = $letter;
            }
        }

        return $letters;
    }

    /** The first numeric value of a key over the lights, as a short number ("29", "7.5"), else "". */
    private function firstNumber(array $lights, string $key): string
    {
        foreach ($lights as $light) {
            if (is_numeric($light[$key] ?? null)) {
                return SectorLightConverter::formatNumber((float) $light[$key]);
            }
        }

        return '';
    }

    /** "W 7; R 4; G 4" - the range of each coloured light - or the bare range of a single light. */
    private function rangeText(array $lights): string
    {
        $parts = [];
        $seen = [];
        foreach ($lights as $light) {
            if (!is_numeric($light['range'] ?? null)) {
                continue;
            }
            $letters = $this->colourLetters($light['colour'] ?? '');
            $label = count($letters) === 1 ? $letters[0] : '';
            $range = SectorLightConverter::formatNumber((float) $light['range']);
            $text = $label === '' ? $range : $label . ' ' . $range;
            if (!isset($seen[$text])) {
                $seen[$text] = true;
                $parts[] = $text;
            }
        }

        return mb_substr(implode('; ', $parts), 0, 120);
    }

    private function fogSignal(array $tags): string
    {
        $parts = array_filter([
            trim((string) ($tags['seamark:fog_signal:category'] ?? '')),
            isset($tags['seamark:fog_signal:group']) ? 'group ' . trim((string) $tags['seamark:fog_signal:group']) : '',
            isset($tags['seamark:fog_signal:period']) ? trim((string) $tags['seamark:fog_signal:period']) . 's' : '',
        ], fn ($part) => $part !== '');

        return $parts === [] ? '' : mb_substr('Nebelsignal: ' . implode(', ', $parts), 0, 200);
    }

    private function remarks(array $tags): string
    {
        $parts = [];
        $information = trim((string) ($tags['seamark:information'] ?? ''));
        if ($information !== '') {
            $parts[] = $information;
        }
        $source = trim((string) ($tags['source'] ?? ''));
        if ($source !== '') {
            $parts[] = 'Quelle laut OpenStreetMap: ' . $source;
        }

        return implode("\n", $parts);
    }
}
