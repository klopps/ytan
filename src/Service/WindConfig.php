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

    /**
     * @return array{enabled: bool, horizon_hours: int, run_interval_hours: int, gusts: bool, fine: bool}
     */
    public static function defaults(): array
    {
        return ['enabled' => true, 'horizon_hours' => 48, 'run_interval_hours' => 3, 'gusts' => false, 'fine' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{enabled: bool, horizon_hours: int, run_interval_hours: int, gusts: bool, fine: bool}
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
        foreach (['enabled', 'gusts', 'fine'] as $flag) {
            if (!is_bool($input[$flag] ?? null)) {
                throw new ValidationException($flag . ' must be true or false.', 'wind.settings_invalid_flag');
            }
        }

        return [
            'enabled' => $input['enabled'], 'horizon_hours' => $horizon, 'run_interval_hours' => $interval,
            'gusts' => $input['gusts'], 'fine' => $input['fine'],
        ];
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
