<?php

declare(strict_types=1);

namespace Ytan\Service\LighthouseList;

/**
 * Reads the lighthouse table of the "Dansk Fyrliste" (Danish Maritime
 * Authority) from the positioned text of its PDF pages (see
 * LighthouseTableParser for how the table is read).
 *
 * Columns: Dansk nr./Int. nr., Navn/Lokalitet, Bredde/Længde, Fyrkarakter,
 * Flammehøjde, Lysevne, Fyrudseende, Yderligere oplysninger. The column
 * borders come from each page's header row. The page's region is the title
 * in its top left corner ("A. Nordsøen"); sub-region headings
 * ("Vadehavet til Grådyb") are texts in column 1 that are neither a number nor
 * an int. number.
 *
 * Pages without the title "Fortegnelse over fyr og tågesignalstationer" and
 * the header row (racon, AIS, DGPS, front matter) are skipped.
 */
final class DanishLighthouseListParser extends LighthouseTableParser
{
    private const COLUMN_SLACK = 4.0;     // data starts up to this much left of its header

    // "77", "32a", offshore structures "151 M" / "5470 C"; int. numbers "B1804.42", "C0202.2", "L5554".
    private const DK_NUMBER = '/^\d+\s?[A-Za-z]?$/u';
    private const INT_NUMBER = '/^[A-Z]\s?\d[\d.]*$/u';
    private const PAGE_TITLE = 'Fortegnelse over fyr og tågesignalstationer';

    private const COLUMN_HEADERS = [
        1 => 'Dansk nr.',
        2 => 'Navn/',
        3 => 'Bredde/',
        4 => 'Fyrkarakter',
        5 => 'Flamme-',
        6 => 'Lysevne',
        7 => 'Fyrudseende',
        8 => 'Yderligere',
    ];

    protected function preparePage(array $fragments): ?array
    {
        $titleFound = false;
        $region = '';
        $header = [];
        $headerY = null;
        foreach ($fragments as $fragment) {
            $text = trim($fragment[2]);
            if (str_starts_with($text, self::PAGE_TITLE)) {
                $titleFound = true;
            }
            if ($fragment[0] < 60 && $fragment[1] > 520 && $text !== '' && $region === '') {
                $region = $this->clean($text);
            }
            foreach (self::COLUMN_HEADERS as $number => $start) {
                if (str_starts_with($text, $start) && !isset($header[$number])) {
                    $header[$number] = $fragment[0];
                    if ($number === 1) {
                        $headerY = $fragment[1];
                    }
                }
            }
        }
        if (!$titleFound || count($header) < 8 || $headerY === null) {
            return null;
        }

        // The header block is two rows deep (the second holds "Int. nr.",
        // "Længde", ...): everything below its second row is body.
        $bodyTop = $headerY - 14.3 - self::ROW_TOLERANCE;
        $body = [];
        foreach ($fragments as $fragment) {
            if (trim($fragment[2]) !== '' && $fragment[1] < $bodyTop) {
                $body[] = $fragment;
            }
        }

        $columns = array_map(fn ($left) => $left - self::COLUMN_SLACK, $header);

        return ['region' => $region, 'columns' => $columns, 'body' => $body];
    }

    protected function splitHeadings(array $body, array $columns): array
    {
        $headings = [];
        $rest = [];
        foreach ($body as $fragment) {
            if ($this->columnOf($fragment[0], $columns) === 1 && $this->isHeadingText($fragment[2])) {
                $headings[] = ['y' => $fragment[1], 'text' => $this->clean($fragment[2]), 'kind' => 'subregion'];
            } else {
                $rest[] = $fragment;
            }
        }

        return [$headings, $rest];
    }

    protected function buildEntry(array $builder): ?array
    {
        $lines = $this->columnLines($builder);

        // Column 3: latitude row (the anchor), longitude row.
        $lat = $builder['lat'];
        $lon = $this->longitudeOf($lines[3]);
        if ($lon === null) {
            return null;
        }

        // Column 1: Danish number and international number.
        $dkNo = '';
        $intNo = '';
        foreach ($lines[1] as $line) {
            if ($dkNo === '' && preg_match(self::DK_NUMBER, $line)) {
                $dkNo = $line;
            } elseif ($intNo === '') {
                $intNo = $line;
            }
        }

        // Stored without spaces, like lighthouse.international_id.
        $intNo = preg_replace('/\s+/u', '', $intNo) ?? $intNo;

        // Column 2: the name is the lines up to and including the first one
        // that ends in a full stop (or just the first line when none does),
        // the rest is the location.
        $nameLines = [];
        $locationLines = [];
        $nameDone = false;
        foreach ($lines[2] as $line) {
            if (!$nameDone) {
                $nameLines[] = $line;
                if (str_ends_with($line, '.')) {
                    $nameDone = true;
                }
            } else {
                $locationLines[] = $line;
            }
        }
        if (!$nameDone && count($nameLines) > 1) {
            $locationLines = array_slice($nameLines, 1);
            $nameLines = [$nameLines[0]];
        }
        $name = rtrim(implode(' ', $nameLines), '. ');
        $location = implode(' ', $locationLines);

        // Column 4: the light characteristic (first line); further lines are
        // fog signals and the like.
        $characteristic = $this->normalizeCharacteristic($lines[4][0] ?? '');
        $otherSignals = implode('; ', array_slice($lines[4], 1));

        // Column 6: the range per colour ("W 6,5"), in nautical miles.
        $ranges = [];
        foreach ($lines[6] as $line) {
            if (preg_match('/^([A-Z]{1,3})\s*(\d+(?:[.,]\d+)?)(?:\s*\(.*\))?$/u', $line, $m)) {
                $range = (float) str_replace(',', '.', $m[2]);
                foreach (str_split($m[1]) as $colour) {
                    $ranges[$colour] ??= $range;
                }
            }
        }

        // Column 8: remarks, among them the sectors.
        $remarks = implode(' ', $lines[8]);
        $sector = SectorLightConverter::convert($remarks, $ranges);

        return [
            'source_key' => $this->sourceKey($dkNo, $intNo, $lat, $lon),
            'dk_no' => $dkNo,
            'international_id' => $intNo,
            'region' => $builder['region'],
            'subregion' => $builder['subregion'],
            'name' => $name,
            'location' => $location,
            'latitude' => round($lat, 7),
            'longitude' => round($lon, 7),
            'characteristic' => $characteristic,
            'other_signals' => $otherSignals,
            'flame_height' => implode(' ', $lines[5]),
            'light_range' => implode('; ', $lines[6]),
            'appearance' => implode(' ', $lines[7]),
            'remarks' => $remarks,
            'sector_characteristic' => $sector['text'],
            'sector_missing_range' => $sector['missingRange'],
        ];
    }

    /** "Oc(2)WRG.12s" -> "Oc(2) WRG 12s": the spelling YTAN's light characteristics use. */
    private function normalizeCharacteristic(string $text): string
    {
        $text = rtrim(str_replace(',', '.', $text), '. ');
        $text = preg_replace('/\)(?=[A-Za-z])/', ') ', $text) ?? $text;
        $text = preg_replace('/(?<=[A-Za-z)])\.(?=[A-Za-z0-9])/', ' ', $text) ?? $text;

        return $this->clean($text);
    }

    /** A column-1 text that is neither a number nor an int. number is a heading. */
    private function isHeadingText(string $text): bool
    {
        $text = $this->clean($text);

        return $text !== '' && !preg_match(self::DK_NUMBER, $text) && !preg_match(self::INT_NUMBER, $text);
    }
}
