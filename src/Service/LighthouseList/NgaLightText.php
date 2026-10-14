<?php

declare(strict_types=1);

namespace Ytan\Service\LighthouseList;

/**
 * Text conventions of the NGA List of Lights that are the same whether the
 * list is read from its PDF (NgaLighthouseListParser) or from the MSI web
 * service's JSON (NgaMsiLightMapper): how a characteristic is written, how
 * the second light of a pair is named, what is a racon.
 */
final class NgaLightText
{
    /**
     * "Iso.W.R.G." + "period 2s" -> "Iso WRG 2s", the spelling YTAN's light
     * characteristics use (same as the Danish list's "Oc(2) WRG 6s"). Further
     * lines ("fl. 1s, ec. 4s") are returned separately.
     *
     * @param list<string> $lines the lines of the characteristic cell
     * @return array{0:string, 1:string} characteristic, the other lines joined with "; "
     */
    public static function characteristic(array $lines): array
    {
        $code = '';
        $period = '';
        $other = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
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

    /** A racon is no light: its name carries the word RACON, or its "characteristic" is a Morse code like "M( - - )". */
    public static function isRacon(string $name, string $characteristic): bool
    {
        return preg_match('/RACON/', $name) === 1 || preg_match('/^[A-Z]\(\s*[–\-•]/u', $characteristic) === 1;
    }

    /**
     * Names of the list are indented by leading dashes: "Kattholmen." is a
     * place, "-Rear, 120 meters ..." or "-S. mole, head." belongs to the entry
     * above it (one dash less), "--Rear, ..." to that one in turn. Such an
     * entry gets the stem of its parent's name in front (the part before the
     * parent's first comma: "Bojden Ferry Harbor, N. mole, head." -> "Bojden
     * Ferry Harbor, S. mole, head"). Entries must be in list order.
     *
     * @param list<array<string,mixed>> $entries entries with a 'name'
     * @return list<array<string,mixed>>
     */
    public static function completeSiblingNames(array $entries): array
    {
        $own = []; // depth => the entry's own text (without dashes) of the latest entry at that depth
        foreach ($entries as $i => $entry) {
            preg_match('/^(-*)\s*(.*)$/us', (string) $entry['name'], $m);
            $depth = strlen($m[1]);
            $text = $depth === 0 ? (string) $entry['name'] : rtrim($m[2], '. ');

            foreach (array_keys($own) as $d) {
                if ($d >= $depth) {
                    unset($own[$d]);
                }
            }
            $own[$depth] = $text;

            if ($depth > 0) {
                $parents = array_filter(array_keys($own), fn ($d) => $d < $depth);
                $stem = $parents === [] ? '' : (function (string $parent): string {
                    return strstr($parent, ', ', true) ?: rtrim($parent, '. ');
                })($own[max($parents)]);
                $entries[$i]['name'] = $stem === '' ? $text : $stem . ', ' . $text;
            }
        }

        return $entries;
    }
}
