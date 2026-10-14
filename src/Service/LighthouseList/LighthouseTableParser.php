<?php

declare(strict_types=1);

namespace Ytan\Service\LighthouseList;

/**
 * Common part of the parsers that read the lighthouse table of an official
 * list (DanishLighthouseListParser, NgaLighthouseListParser) from the
 * POSITIONED text of its PDF pages.
 *
 * Both lists are 8-column tables whose entries span several lines; the plain
 * text order of the PDF is not reliable (an entry's number can float above its
 * name), so this works on the x/y position of every text fragment
 * ([x, y, text], y grows upwards):
 *
 *  - a page's column borders come from its header row (preparePage()),
 *  - an entry is anchored by its latitude line ("55 ° 27,49' N") in column 3,
 *  - every other fragment belongs to the nearest anchor above it (a fragment
 *    up to LINE_TOLERANCE above the anchor line still counts as that line),
 *  - headings (region / sub-region titles between the entries, found by
 *    splitHeadings()) are not part of any entry and set the region of the
 *    entries below them,
 *  - fragments above a page's first anchor continue the previous page's last
 *    entry.
 *
 * A subclass supplies what differs between the lists: how a table page and its
 * columns are recognised, which fragments are headings and what an entry's
 * columns mean (buildEntry()).
 */
abstract class LighthouseTableParser
{
    protected const LINE_TOLERANCE = 4.0;   // points a fragment may sit above its anchor line
    protected const ROW_TOLERANCE = 2.0;    // fragments within this y distance form one row

    // "55 ° 27,49' N" / "55° 15.4´ N": degrees, minutes (comma or point, with any minute sign), hemisphere.
    private const LATITUDE = '/^(\d{1,2})\s*°\s*(\d{1,2}(?:[.,]\d+)?)\s*[\'´′’]?\s*([NS])$/u';
    private const LONGITUDE = '/^(\d{1,3})\s*°\s*(\d{1,2}(?:[.,]\d+)?)\s*[\'´′’]?\s*([EW])$/u';

    /**
     * @param iterable<list<array{0:float,1:float,2:string}>> $pages
     * @return list<array<string,mixed>> the entries in list order
     */
    public function parse(iterable $pages): array
    {
        $builders = [];       // entry builders, in list order
        $region = '';
        $subregion = '';
        $last = null;         // index in $builders of the previous page's last entry

        foreach ($pages as $fragments) {
            $page = $this->preparePage($fragments);
            if ($page === null) {
                continue;
            }
            $region = $page['region'] !== '' ? $page['region'] : $region;
            $columns = $page['columns'];

            [$headings, $body] = $this->splitHeadings($page['body'], $columns);

            $anchors = $this->findAnchors($body, $columns);
            usort($anchors, fn ($a, $b) => $b['y'] <=> $a['y']);

            // Group the fragments by anchor: the lowest anchor that is not
            // more than LINE_TOLERANCE below the fragment.
            $perAnchor = array_fill(0, count($anchors), []);
            $leading = [];
            foreach ($body as $fragment) {
                $index = null;
                foreach ($anchors as $i => $anchor) {
                    if ($anchor['y'] >= $fragment[1] - self::LINE_TOLERANCE) {
                        $index = $i;
                    }
                }
                if ($index === null) {
                    $leading[] = $fragment;
                } else {
                    $perAnchor[$index][] = $fragment;
                }
            }

            // Fragments above the first anchor continue the previous entry.
            if ($leading !== [] && $last !== null) {
                foreach ($leading as $fragment) {
                    $builders[$last]['fragments'][] = [$fragment[0], $fragment[1] - 10000, $fragment[2], $columns];
                }
            }

            // Headings apply to the entries below them, top to bottom.
            usort($headings, fn ($a, $b) => $b['y'] <=> $a['y']);
            $applyHeadings = function (float $from, float $to) use (&$region, &$subregion, $headings): void {
                foreach ($headings as $heading) {
                    if ($heading['y'] <= $from && $heading['y'] > $to) {
                        if ($heading['kind'] === 'region') {
                            $region = $heading['text'];
                            $subregion = '';
                        } else {
                            $subregion = $heading['text'];
                        }
                    }
                }
            };

            $previousY = PHP_FLOAT_MAX;
            foreach ($anchors as $i => $anchor) {
                $applyHeadings($previousY, $anchor['y']);
                $previousY = $anchor['y'];

                $fragments = [];
                foreach ($perAnchor[$i] as $fragment) {
                    $fragments[] = [$fragment[0], $fragment[1], $fragment[2], $columns];
                }
                $builders[] = [
                    'region' => $region,
                    'subregion' => $subregion,
                    'fragments' => $fragments,
                    'lat' => $anchor['lat'],
                ];
                $last = count($builders) - 1;
            }
            // Headings below the last anchor (or on a page without entries) count for the next page.
            $applyHeadings($previousY, -PHP_FLOAT_MAX);
        }

        $entries = [];
        $seen = [];
        foreach ($builders as $builder) {
            $entry = $this->buildEntry($builder);
            if ($entry === null) {
                continue;
            }
            // Two identical keys (the same light listed twice) must not
            // collapse into one staged entry.
            $seen[$entry['source_key']] = ($seen[$entry['source_key']] ?? 0) + 1;
            if ($seen[$entry['source_key']] > 1) {
                $entry['source_key'] .= '#' . $seen[$entry['source_key']];
            }
            $entries[] = $entry;
        }

        return $this->finish($entries);
    }

    /**
     * Recognises a table page and returns its region (if the page carries one
     * outside the table), the left border of each column 1-8 and the table's
     * body fragments (header and footer left out); null for any other page.
     *
     * @param list<array{0:float,1:float,2:string}> $fragments
     * @return array{region:string, columns:array<int,float>, body:list<array{0:float,1:float,2:string}>}|null
     */
    abstract protected function preparePage(array $fragments): ?array;

    /**
     * Takes the headings out of a page's body.
     *
     * @param list<array{0:float,1:float,2:string}> $body
     * @param array<int,float> $columns
     * @return array{0: list<array{y:float, text:string, kind:string}>, 1: list<array{0:float,1:float,2:string}>}
     *         the headings (kind "region" or "subregion") and the body without them
     */
    abstract protected function splitHeadings(array $body, array $columns): array;

    /**
     * Turns the fragments of one entry into the entry's record (the shape of
     * LighthouseImportRepository::upsertMany()), or null if there is none.
     *
     * @param array{region:string, subregion:string, fragments:list<array>, lat:float} $builder
     * @return array<string,mixed>|null
     */
    abstract protected function buildEntry(array $builder): ?array;

    /**
     * Last step over the finished entries in list order (entries that depend
     * on their neighbours, entries to drop).
     *
     * @param list<array<string,mixed>> $entries
     * @return list<array<string,mixed>>
     */
    protected function finish(array $entries): array
    {
        return $entries;
    }

    /** @param array<int,float> $columns left border of each column */
    protected function columnOf(float $x, array $columns): int
    {
        $column = 1;
        foreach ($columns as $number => $left) {
            if ($x >= $left) {
                $column = max($column, $number);
            }
        }

        return $column;
    }

    /**
     * @param list<array{0:float,1:float,2:string}> $body
     * @param array<int,float> $columns
     * @return list<array{y:float, lat:float}>
     */
    protected function findAnchors(array $body, array $columns): array
    {
        $rows = $this->rows(array_values(array_filter(
            $body,
            fn ($f) => $this->columnOf($f[0], $columns) === 3
        )));

        $anchors = [];
        foreach ($rows as $row) {
            if (preg_match(self::LATITUDE, $row['text'], $m)) {
                $value = (int) $m[1] + (float) str_replace(',', '.', $m[2]) / 60;
                $anchors[] = ['y' => $row['y'], 'lat' => $m[3] === 'S' ? -$value : $value];
            }
        }

        return $anchors;
    }

    /**
     * Groups fragments into rows (same y within ROW_TOLERANCE), top to bottom,
     * each row's fragments joined left to right with single spaces.
     *
     * @param list<array{0:float,1:float,2:string}> $fragments
     * @return list<array{y:float, text:string}>
     */
    protected function rows(array $fragments): array
    {
        usort($fragments, fn ($a, $b) => $b[1] <=> $a[1]);
        $groups = [];
        foreach ($fragments as $fragment) {
            $placed = false;
            foreach ($groups as &$group) {
                if (abs($group['y'] - $fragment[1]) <= self::ROW_TOLERANCE) {
                    $group['items'][] = $fragment;
                    $placed = true;
                    break;
                }
            }
            unset($group);
            if (!$placed) {
                $groups[] = ['y' => $fragment[1], 'items' => [$fragment]];
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            usort($group['items'], fn ($a, $b) => $a[0] <=> $b[0]);
            $text = $this->clean(implode(' ', array_map(fn ($f) => $f[2], $group['items'])));
            if ($text !== '') {
                $rows[] = ['y' => $group['y'], 'text' => $text];
            }
        }

        return $rows;
    }

    /**
     * The text lines of an entry's eight columns.
     *
     * @param array{fragments:list<array>} $builder
     * @return array<int, list<string>> column number 1-8 => its rows, top to bottom
     */
    protected function columnLines(array $builder): array
    {
        $byColumn = [];
        foreach ($builder['fragments'] as $fragment) {
            $byColumn[$this->columnOf($fragment[0], $fragment[3])][] = [$fragment[0], $fragment[1], $fragment[2]];
        }
        $lines = [];
        for ($column = 1; $column <= 8; $column++) {
            $lines[$column] = array_map(fn ($row) => $row['text'], $this->rows($byColumn[$column] ?? []));
        }

        return $lines;
    }

    /**
     * Longitude (decimal degrees, west negative) from the rows of column 3.
     *
     * @param list<string> $lines
     */
    protected function longitudeOf(array $lines): ?float
    {
        $lon = null;
        foreach ($lines as $line) {
            if (preg_match(self::LONGITUDE, $line, $m)) {
                $value = (int) $m[1] + (float) str_replace(',', '.', $m[2]) / 60;
                $lon = $m[3] === 'W' ? -$value : $value;
            }
        }

        return $lon;
    }

    /**
     * Key of an entry: keeps it apart from a same-numbered one elsewhere
     * (the lists restart their numbering in some sections).
     */
    protected function sourceKey(string $number, string $internationalId, float $lat, float $lon): string
    {
        return $number . '|' . $internationalId . '|' . number_format($lat, 4, '.', '') . '|' . number_format($lon, 4, '.', '');
    }

    protected function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)) ?? $text);
    }
}
