<?php

declare(strict_types=1);

namespace Ytan\Service\LighthouseList;

/**
 * Reads the lights table of the NGA "List of Lights" (Publication 116 and its
 * sister volumes) from the positioned text of its PDF pages (see
 * LighthouseTableParser for how the table is read).
 *
 * Columns: (1) No. over the international number ("C 0969"), (2) Name and
 * Location, (3) Position, (4) Characteristic, (5) Height (feet over metres),
 * (6) Range per colour, (7) Structure, (8) Remarks (sectors among them).
 *
 * Unlike the Danish list the header labels are centred over their columns, so
 * the column borders are fixed (BORDERS, measured on the standard page) and
 * only shifted by what the row of column numbers "(1)" ... "(8)" says about a
 * page's offset. Pages whose number row doesn't fit (the radiobeacon table,
 * for one) are skipped. Headings: country/sea-area titles centred over the
 * table ("DENMARK-KATTEGAT AND BELTS") are the region, upper-case place names
 * at the left edge of the name column ("FJALLBACKA") the sub-region.
 *
 * Entries that are no lights are dropped: racons (their name carries the word
 * RACON, their "characteristic" is a Morse code like "M( - - )").
 */
final class NgaLighthouseListParser extends LighthouseTableParser
{
    /** x of the column-number labels on the standard page. */
    private const REFERENCE_NUMBERS = [1 => 82.0, 2 => 151.0, 3 => 217.6, 4 => 271.6, 5 => 318.1, 6 => 347.7, 7 => 412.0, 8 => 505.6];
    /** Left border of each column on the standard page. */
    private const BORDERS = [1 => 0.0, 2 => 100.0, 3 => 207.0, 4 => 245.0, 5 => 320.0, 6 => 345.0, 7 => 380.0, 8 => 473.0];
    private const NUMBER_TOLERANCE = 3.5;   // points a page's number row may deviate from the standard page's (after the shift)
    private const FOOTER_Y = 40.0;          // below this: the page number

    // Region title: capitals, spaces, hyphens, brackets; no digits and no dots (those are light codes like "Q.W.R.G.").
    private const REGION = '/^[A-Z][A-Z ,\-()\'\/&]{8,}$/u';
    private const SUBREGION = '/^[A-Z(][A-Z0-9 ,\-()\'\/&:.]{2,}$/u';

    protected function preparePage(array $fragments): ?array
    {
        $numbers = [];
        $numberRowY = null;
        $hasCharacteristic = false;
        $hasHeight = false;
        foreach ($fragments as $fragment) {
            $text = trim($fragment[2]);
            if (preg_match('/^\(([1-8])\)$/', $text, $m) && !isset($numbers[(int) $m[1]])) {
                $numbers[(int) $m[1]] = $fragment[0];
                $numberRowY = $fragment[1];
            }
            $hasCharacteristic = $hasCharacteristic || $text === 'Characteristic';
            $hasHeight = $hasHeight || $text === 'Height';
        }
        if (count($numbers) < 8 || $numberRowY === null || !$hasCharacteristic || !$hasHeight) {
            return null;
        }

        $offset = 0.0;
        foreach (self::REFERENCE_NUMBERS as $column => $x) {
            $offset += $numbers[$column] - $x;
        }
        $offset /= 8;
        foreach (self::REFERENCE_NUMBERS as $column => $x) {
            if (abs($numbers[$column] - $x - $offset) > self::NUMBER_TOLERANCE) {
                return null;
            }
        }

        // The labels ("No.", "Name and Location", ...) sit one row below the numbers.
        $bodyTop = $numberRowY - 7.0 - self::ROW_TOLERANCE;
        $body = [];
        foreach ($fragments as $fragment) {
            if (trim($fragment[2]) !== '' && $fragment[1] < $bodyTop && $fragment[1] > self::FOOTER_Y) {
                $body[] = $fragment;
            }
        }

        return [
            'region' => '',
            'columns' => array_map(fn ($border) => $border + $offset, self::BORDERS),
            'body' => $body,
        ];
    }

    protected function splitHeadings(array $body, array $columns): array
    {
        $offset = $columns[2] - self::BORDERS[2];
        $headings = [];
        $taken = [];

        // A heading stands alone in its row.
        $alone = function (int $index) use ($body): bool {
            foreach ($body as $other => $fragment) {
                if ($other !== $index && abs($fragment[1] - $body[$index][1]) <= 2.5) {
                    return false;
                }
            }

            return true;
        };

        foreach ($body as $index => $fragment) {
            $text = $this->clean($fragment[2]);
            $x = $fragment[0] - $offset;

            if ($x >= 200 && $x <= 330 && preg_match(self::REGION, $text) === 1 && $alone($index)) {
                $headings[] = ['y' => $fragment[1], 'text' => $text, 'kind' => 'region'];
                $taken[$index] = true;
            } elseif ($x >= 112 && $x <= 119 && preg_match(self::SUBREGION, $text) === 1 && $alone($index)) {
                // A long place name wraps onto indented upper-case lines right below ("HJARTOFJORD (HIERTO" / "FJORD):").
                $y = $fragment[1];
                foreach ($body as $next => $continuation) {
                    $nextText = $this->clean($continuation[2]);
                    $nextX = $continuation[0] - $offset;
                    if ($continuation[1] < $y && $y - $continuation[1] <= 9.5 && $nextX >= 123 && $nextX <= 130
                        && preg_match(self::SUBREGION, $nextText) === 1 && $alone($next) && !isset($taken[$next])) {
                        $text .= ' ' . $nextText;
                        $y = $continuation[1];
                        $taken[$next] = true;
                    }
                }
                $headings[] = ['y' => $fragment[1], 'text' => rtrim($text, ': '), 'kind' => 'subregion'];
                $taken[$index] = true;
            }
        }

        $rest = [];
        foreach ($body as $index => $fragment) {
            if (!isset($taken[$index])) {
                $rest[] = $fragment;
            }
        }

        return [$headings, $rest];
    }

    protected function buildEntry(array $builder): ?array
    {
        $lines = $this->columnLines($builder);

        $lat = $builder['lat'];
        $lon = $this->longitudeOf($lines[3]);
        if ($lon === null) {
            return null;
        }

        // Column 1: list number, international number ("C 0969" -> "C0969").
        $number = '';
        $intNo = '';
        foreach ($lines[1] as $line) {
            if ($number === '' && preg_match('/^\d[\d.]*\s?[A-Za-z]?$/u', $line)) {
                $number = $line;
            } elseif ($intNo === '' && preg_match('/^[A-Z]\s?\d[\d.]*$/u', $line)) {
                $intNo = preg_replace('/\s+/u', '', $line) ?? $line;
            }
        }

        // Column 2 is name and location in one: kept whole as the name.
        $name = rtrim(implode(' ', $lines[2]), '. ');

        [$characteristic, $otherSignals] = $this->characteristic($lines[4]);

        // Column 5: height in feet over metres - the metres are stored.
        $heights = array_values(array_filter($lines[5], fn ($l) => preg_match('/^\d+(?:\.\d+)?$/', $l) === 1));
        $height = $heights === [] ? '' : (string) ($heights[1] ?? $heights[0]);

        // Column 6: range per colour ("W. 11", "R. 8"), or one plain number.
        $ranges = [];
        foreach ($lines[6] as $line) {
            if (preg_match('/^([WRGYB])\.?\s*(\d+(?:\.\d+)?)/', $line, $m)) {
                $ranges[$m[1]] ??= (float) $m[2];
            } elseif (preg_match('/^(\d+(?:\.\d+)?)$/', $line, $m)) {
                $ranges['ANY'] ??= (float) $m[1];
            }
        }

        // Column 8: remarks, among them the sectors.
        $remarks = implode(' ', $lines[8]);
        $sector = SectorLightConverter::convert($remarks, $ranges);

        return [
            'source_key' => $this->sourceKey($number, $intNo, $lat, $lon),
            'dk_no' => $number,
            'international_id' => $intNo,
            'region' => $builder['region'],
            'subregion' => $builder['subregion'],
            'name' => $name,
            'location' => '',
            'latitude' => round($lat, 7),
            'longitude' => round($lon, 7),
            'characteristic' => $characteristic,
            'other_signals' => $otherSignals,
            'flame_height' => $height,
            'light_range' => implode('; ', $lines[6]),
            'appearance' => implode(' ', $lines[7]),
            'remarks' => $remarks,
            'sector_characteristic' => $sector['text'],
            'sector_missing_range' => $sector['missingRange'],
        ];
    }

    /**
     * Racons are dropped, and the second light of a pair ("-Rear, 120 meters
     * ...", "-S. mole, head.") gets the name of its first one in front.
     */
    protected function finish(array $entries): array
    {
        $result = [];
        $baseName = '';
        foreach ($entries as $entry) {
            if (preg_match('/RACON/', $entry['name']) === 1 || preg_match('/^[A-Z]\(\s*[–\-•]/u', $entry['characteristic']) === 1) {
                continue;
            }

            if (preg_match('/^-+\s*(.*)$/u', $entry['name'], $m) === 1) {
                $own = rtrim($m[1], '. ');
                // The part of the first light's name before its first comma ("Bojden Ferry Harbor, N. mole, head." -> "Bojden Ferry Harbor").
                $stem = $baseName === '' ? '' : (strstr($baseName, ', ', true) ?: rtrim($baseName, '. '));
                $entry['name'] = $stem === '' ? $own : $stem . ', ' . $own;
            } else {
                $baseName = $entry['name'];
            }
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * "Iso.W.R.G." + "period 2s" -> "Iso WRG 2s", the spelling YTAN's light
     * characteristics use (same as the Danish list's "Oc(2) WRG 6s"). Further
     * lines ("fl. 1s, ec. 4s") are kept separately.
     *
     * @param list<string> $lines the rows of column 4
     * @return array{0:string, 1:string} characteristic, other lines
     */
    private function characteristic(array $lines): array
    {
        $code = '';
        $period = '';
        $other = [];
        foreach ($lines as $line) {
            // "period 2s" is a line of its own, or glued to the code ("Iso.W.period 10s").
            if (preg_match('/^(.*?)\s*period\s*(.+)$/i', $line, $m) === 1) {
                if ($code === '' && $m[1] !== '') {
                    $code = $m[1];
                }
                $period = rtrim(preg_replace('/\s+/', '', $m[2]) ?? '', '.');
            } elseif ($code === '') {
                $code = $line;
            } else {
                $other[] = $line;
            }
        }

        $compact = str_replace('.', '', preg_replace('/\s+/', '', $code) ?? '');
        // A trailing word in brackets ("(vert)": lights in a vertical line) stays behind the colours.
        $suffix = '';
        if (preg_match('/^(.*?)(\([a-z]+\))$/', $compact, $m) === 1) {
            $compact = $m[1];
            $suffix = $m[2];
        }
        if (preg_match('/^(.*\))([A-Za-z]*)$/', $compact, $m) !== 1) {
            preg_match('/^(.*?)([WRGYB]*)$/', $compact, $m);
        }
        $characteristic = trim(preg_replace('/\s+/', ' ', ($m[1] ?? '') . ' ' . ($m[2] ?? '') . ' ' . $suffix . ' ' . $period) ?? '');

        return [$characteristic, implode('; ', $other)];
    }
}
