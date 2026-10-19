<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ValidationException;

/**
 * Settings of the wind job (tools/wind/): admin input, validation and the
 * "wind" object of storage/radar/config.json the job reads (the regions
 * come from the rain radar settings - one choice for both overlays).
 * Defaults mirror tools/wind/ytanwind/settings.py.
 */
final class WindConfig
{
    public const HORIZONS = [24, 48, 72, 120];
    public const INTERVALS = [3, 6];
    public const MAX_REGIONS = 10;

    /**
     * @return array{enabled: bool, horizon_hours: int, run_interval_hours: int, gusts: bool, fine: bool, world: bool, regions: list<array{name: string, south: float, west: float, north: float, east: float}>|null}
     */
    public static function defaults(): array
    {
        return ['enabled' => true, 'horizon_hours' => 48, 'run_interval_hours' => 3, 'gusts' => false, 'fine' => true, 'world' => true, 'regions' => null];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{enabled: bool, horizon_hours: int, run_interval_hours: int, gusts: bool, fine: bool, world: bool, regions: list<array{name: string, south: float, west: float, north: float, east: float}>|null}
     */
    public static function normalize(array $input): array
    {
        $horizon = filter_var($input['horizon_hours'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($horizon, self::HORIZONS, true)) {
            throw new ValidationException('horizon_hours must be 24, 48, 72 or 120.', 'wind.settings_invalid_horizon');
        }
        $interval = filter_var($input['run_interval_hours'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($interval, self::INTERVALS, true)) {
            throw new ValidationException('run_interval_hours must be 3 or 6.', 'wind.settings_invalid_interval');
        }
        foreach (['enabled', 'gusts', 'fine', 'world'] as $flag) {
            if (!is_bool($input[$flag] ?? null)) {
                throw new ValidationException($flag . ' must be true or false.', 'wind.settings_invalid_flag');
            }
        }

        return [
            'enabled' => $input['enabled'], 'horizon_hours' => $horizon, 'run_interval_hours' => $interval,
            'gusts' => $input['gusts'], 'fine' => $input['fine'], 'world' => $input['world'],
            'regions' => self::normalizeRegions($input['regions'] ?? null),
        ];
    }

    /**
     * Own regions anywhere on the globe, the whole of it included (null = the rain radar's).
     * Measured 2026-10-19 for the whole world and 72 h: ~570 MB download, ~480 MB written, 4-5 minutes.
     *
     * @return list<array{name: string, south: float, west: float, north: float, east: float}>|null
     */
    public static function normalizeRegions(mixed $regions): ?array
    {
        if ($regions === null) {
            return null;
        }
        if (!is_array($regions) || !array_is_list($regions) || $regions === [] || count($regions) > self::MAX_REGIONS) {
            throw new ValidationException('Own regions: 1 to ' . self::MAX_REGIONS . ' rectangles.', 'wind.settings_invalid_regions');
        }
        $out = [];
        foreach ($regions as $r) {
            if (!is_array($r)) {
                throw new ValidationException('A region needs south, west, north and east in degrees.', 'wind.settings_invalid_regions');
            }
            $rect = ['name' => mb_substr(trim((string) ($r['name'] ?? '')), 0, 60)];
            foreach (['south' => 90.0, 'west' => 180.0, 'north' => 90.0, 'east' => 180.0] as $side => $limit) {
                $v = filter_var($r[$side] ?? null, FILTER_VALIDATE_FLOAT);
                if ($v === false || !is_finite($v) || abs($v) > $limit) {
                    throw new ValidationException('A region needs south, west, north and east in degrees.', 'wind.settings_invalid_regions');
                }
                $rect[$side] = round($v, 4);
            }
            if ($rect['south'] >= $rect['north'] || $rect['west'] >= $rect['east']) {
                throw new ValidationException('A region is empty.', 'wind.settings_invalid_regions');
            }
            $out[] = $rect;
        }
        return $out;
    }

    /**
     * What the wind job reported about its last runs (storage/wind/status.json,
     * written by tools/wind/ytanwind/job.py), null before its first run.
     *
     * @return array<string, mixed>|null
     */
    public static function readStatus(string $windDir): ?array
    {
        $json = @file_get_contents($windDir . '/status.json');
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
