<?php

declare(strict_types=1);

namespace Ytan\Service\LighthouseList;

/**
 * Turns the sector-light descriptions of the official lighthouse lists into
 * YTAN's `lighthouse.sector_characteristic` format - one sector per line,
 * "<colour> <range>M <start>-<end>" with decimal-point degrees, e.g.
 * "G 8M 350.7-352.2" (what public/lib/sectorlight-overlay reads).
 *
 * The lists write the sectors in different ways, and the numbers may use a
 * decimal comma or point:
 *   1. "G350,7°-352,2° W352,2°-354,2° R359°-20°"
 *   2. "G. 350°42-352° W. -354,2°, R.359°-20°"
 * In the second form "350°42" is degrees and minutes (350°42' = 350.7°), and
 * a missing start before the "-" means "the end of the previous sector".
 * Only the colours the overlay can draw are kept: G (green), R (red),
 * W (white).
 */
final class SectorLightConverter
{
    private const COLOURS = ['G', 'R', 'W'];

    /**
     * Range placeholder of a sector the list gives no range for ("G ?M 10-20").
     * The import replaces it with the range chosen there for that colour
     * (applyDefaultRanges()); it never reaches a POI.
     */
    public const UNKNOWN_RANGE = '?';

    // Angle: degrees (optionally decimal) with a degree sign, optionally
    // followed directly by whole minutes ("350°42", NGA: "350°42´" with a
    // minute sign). The sign is mandatory on at least one side of a sector,
    // which keeps ordinary text ("R 6", "Nr. 32a") from being mistaken for a
    // sector.
    private const ANGLE = '(\d{1,3}(?:[.,]\d+)?)\s*°(\d{1,2}(?![\d.,]))?[\'´′’]?';

    /**
     * @return list<array{colour:string, start:float, end:float}> in text order,
     *         without the unsupported colours
     */
    public static function parseSectors(string $text): array
    {
        // NGA spells the weaker parts of a sector light "G.Unintensified-239°".
        $pattern = '/(?<![\p{L}\d])([A-Z])\s*\.?\s*(?:(?:[Uu]n)?[Ii]ntensified)?\s*(?:' . self::ANGLE . ')?\s*[-–]\s*(?:' . self::ANGLE . ')?/u';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $sectors = [];
        $previousEnd = null;
        foreach ($matches as $match) {
            $hasStart = ($match[2] ?? '') !== '';
            $hasEnd = ($match[4] ?? '') !== '';
            if (!$hasStart && !$hasEnd) {
                continue;
            }

            $start = $hasStart ? self::angle($match[2], $match[3] ?? '') : $previousEnd;
            $end = $hasEnd ? self::angle($match[4], $match[5] ?? '') : null;
            if ($start === null || $end === null) {
                continue;
            }
            $previousEnd = $end;

            if (in_array($match[1], self::COLOURS, true)) {
                $sectors[] = ['colour' => $match[1], 'start' => $start, 'end' => $end];
            }
        }

        return $sectors;
    }

    /**
     * @param array<string,float> $rangesByColour range in nautical miles per colour letter
     * @return array{text: ?string, missingRange: bool} text is null when there is no sector at all;
     *         a sector without a known range gets UNKNOWN_RANGE as its range and sets missingRange
     */
    public static function convert(string $text, array $rangesByColour): array
    {
        $sectors = self::parseSectors($text);
        if ($sectors === []) {
            return ['text' => null, 'missingRange' => false];
        }

        // A single known range may stand for all colours (a light with one
        // listed range for every sector).
        $fallback = count($rangesByColour) === 1 ? reset($rangesByColour) : null;

        $lines = [];
        $missing = false;
        foreach ($sectors as $sector) {
            $range = $rangesByColour[$sector['colour']] ?? $fallback;
            if ($range === null) {
                $missing = true;
            }
            $lines[] = $sector['colour'] . ' ' . ($range === null ? self::UNKNOWN_RANGE : self::number($range)) . 'M '
                . self::number($sector['start']) . '-' . self::number($sector['end']);
        }

        return ['text' => $lines === [] ? null : implode("\n", $lines), 'missingRange' => $missing];
    }

    /**
     * Replaces the UNKNOWN_RANGE of every sector with the range given for its colour.
     *
     * @param array<string,float> $defaults range in nautical miles per colour letter (G, R, W)
     * @return array{text: string, applied: bool} applied is true if at least one range was filled in
     */
    public static function applyDefaultRanges(string $sectorCharacteristic, array $defaults): array
    {
        $applied = false;
        $text = preg_replace_callback(
            '/^([GRW]) ' . preg_quote(self::UNKNOWN_RANGE, '/') . 'M /m',
            function (array $m) use ($defaults, &$applied): string {
                $applied = true;

                return $m[1] . ' ' . self::number((float) ($defaults[$m[1]] ?? 0)) . 'M ';
            },
            $sectorCharacteristic
        ) ?? $sectorCharacteristic;

        return ['text' => $text, 'applied' => $applied];
    }

    private static function angle(string $degrees, string $minutes): float
    {
        $value = (float) str_replace(',', '.', $degrees);
        if ($minutes !== '') {
            $value += (int) $minutes / 60;
        }

        return $value;
    }

    private static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }
}
