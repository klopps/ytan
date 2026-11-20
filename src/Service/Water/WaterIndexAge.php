<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

use DateTimeImmutable;
use DateTimeZone;

/**
 * How old the water index is (the admin dashboard's tile): its "version" in water-index.json is the
 * UTC time tools/water/build_index.py started building it ("YYYYMMDDHHMM"; a run that only adds the
 * covered area sets a new version too, so this is the age of the last build, not of the OpenStreetMap
 * data). Coastlines, rivers and big lakes hardly change: a yearly rebuild is enough (docs/water-index.md).
 *
 * state: 'ok' up to 12 months, 'warn' up to 13 months, 'old' beyond (the tile is green, yellow, red).
 */
final class WaterIndexAge
{
    public const OK_MONTHS = 12;
    public const WARN_MONTHS = 13;

    /**
     * @return array{built_at: string, age_days: int, age_months: int, state: string}|null null = no
     *         index or a version that is not a time
     */
    public static function describe(?string $version, DateTimeImmutable $now): ?array
    {
        if ($version === null || preg_match('/^\d{12}$/', $version) !== 1) {
            return null;
        }
        $built = DateTimeImmutable::createFromFormat('!YmdHi', $version, new DateTimeZone('UTC'));
        if ($built === false || $built->format('YmdHi') !== $version) {
            return null; // 202613400000 and the like
        }
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $age = $built->diff($now);
        $future = $built > $now; // a clock that is behind: the index is as young as it gets

        if ($future || $built->modify('+' . self::OK_MONTHS . ' months') >= $now) {
            $state = 'ok';
        } elseif ($built->modify('+' . self::WARN_MONTHS . ' months') >= $now) {
            $state = 'warn';
        } else {
            $state = 'old';
        }

        return [
            'built_at' => $built->format('Y-m-d\TH:i:s\Z'),
            'age_days' => $future ? 0 : (int) $age->days,
            'age_months' => $future ? 0 : $age->y * 12 + $age->m,
            'state' => $state,
        ];
    }
}
