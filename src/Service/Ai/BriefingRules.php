<?php

declare(strict_types=1);

namespace Ytan\Service\Ai;

/**
 * The Routencheck's traffic-light thresholds (routencheck.md, "Entscheidungsmodell
 * Ampel"): stored as JSON in app_settings.briefing_rules (NULL = defaults()),
 * edited on /admin/ai. normalize() is the one place that decides what a valid
 * rule set is - every value is clamped to RANGES, a yellow threshold that is
 * not below its red one is moved below it.
 *
 * Units: wind and gusts m/s, waves m, water temperature degrees Celsius, fetch
 * m / km, slowdown km/h per m/s of headwind, current km/h (the 'current'
 * pair rates the current AGAINST the course; river_current_kmh is the assumed
 * river current, which the paddler can change in the sheet; river_max_m = how
 * far from a river's centre line a position still counts as on it). A level
 * factor multiplies the wind, gust, wave and current thresholds (beginners
 * get lower ones).
 */
final class BriefingRules
{
    public const LEVELS = ['beginner', 'experienced', 'expert'];

    /** [min, max] per scalar setting; the threshold pairs use 'wind', 'gust', 'wave', 'current'. */
    public const RANGES = [
        'wind' => [1.0, 25.0],
        'gust' => [1.0, 35.0],
        'wave' => [0.1, 4.0],
        'current' => [0.5, 15.0],
        'dusk_minutes' => [0, 120],
        'cold_water_c' => [0.0, 25.0],
        'lee_fetch_m' => [0, 5000],
        'lee_factor' => [1.0, 2.0],
        'exposed_fetch_km' => [0.5, 30.0],
        'exposed_factor' => [0.3, 1.0],
        'offshore_land_km' => [0.2, 10.0],
        'offshore_open_km' => [0.5, 30.0],
        'beam_min_deg' => [0, 90],
        'beam_max_deg' => [90, 180],
        'headwind_slowdown' => [0.0, 1.0],
        'river_current_kmh' => [0.0, 10.0],
        'river_max_m' => [20, 1000],
        'level_factor' => [0.3, 2.0],
        'window_from' => [0, 23],
        'window_to' => [0, 23],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'wind' => ['yellow' => 5.0, 'red' => 8.0],
            'gust' => ['yellow' => 8.0, 'red' => 11.0],
            'wave' => ['yellow' => 0.5, 'red' => 1.0],
            'current' => ['yellow' => 2.0, 'red' => 4.0],
            'dusk_minutes' => 30,
            'cold_water_c' => 10.0,
            'lee_fetch_m' => 500,
            'lee_factor' => 1.2,
            'exposed_fetch_km' => 5.0,
            'exposed_factor' => 0.8,
            'offshore_land_km' => 2.0,
            'offshore_open_km' => 5.0,
            'beam_min_deg' => 60,
            'beam_max_deg' => 120,
            'headwind_slowdown' => 0.3,
            'river_current_kmh' => 2.0,
            'river_max_m' => 150,
            'levels' => ['beginner' => 0.75, 'experienced' => 1.0, 'expert' => 1.25],
            'window_from' => 6,
            'window_to' => 20,
        ];
    }

    /**
     * @param array<string, mixed>|null $input
     * @return array<string, mixed>
     */
    public static function normalize(?array $input): array
    {
        $defaults = self::defaults();
        $input ??= [];
        $rules = [];

        foreach (['wind', 'gust', 'wave', 'current'] as $key) {
            [$min, $max] = self::RANGES[$key];
            $pair = is_array($input[$key] ?? null) ? $input[$key] : [];
            $yellow = self::clamp($pair['yellow'] ?? null, $defaults[$key]['yellow'], $min, $max);
            $red = self::clamp($pair['red'] ?? null, $defaults[$key]['red'], $min, $max);
            if ($yellow >= $red) {
                $yellow = max($min, round($red * 0.8, 2));
            }
            $rules[$key] = ['yellow' => round($yellow, 2), 'red' => round($red, 2)];
        }

        $integers = ['dusk_minutes', 'lee_fetch_m', 'beam_min_deg', 'beam_max_deg', 'window_from', 'window_to', 'river_max_m'];
        foreach (['dusk_minutes', 'cold_water_c', 'lee_fetch_m', 'lee_factor', 'exposed_fetch_km', 'exposed_factor',
            'offshore_land_km', 'offshore_open_km', 'beam_min_deg', 'beam_max_deg', 'headwind_slowdown', 'river_current_kmh', 'river_max_m',
            'window_from', 'window_to'] as $key) {
            [$min, $max] = self::RANGES[$key];
            $value = self::clamp($input[$key] ?? null, $defaults[$key], $min, $max);
            $rules[$key] = in_array($key, $integers, true) ? (int) round($value) : round($value, 2);
        }
        if ($rules['window_to'] < $rules['window_from']) {
            $rules['window_to'] = $rules['window_from'];
        }

        $levels = is_array($input['levels'] ?? null) ? $input['levels'] : [];
        [$min, $max] = self::RANGES['level_factor'];
        foreach (self::LEVELS as $level) {
            $rules['levels'][$level] = round(self::clamp($levels[$level] ?? null, $defaults['levels'][$level], $min, $max), 2);
        }

        return $rules;
    }

    public static function isLevel(mixed $level): bool
    {
        return is_string($level) && in_array($level, self::LEVELS, true);
    }

    /** Stable fingerprint of a rule set (part of the AI text cache key). */
    public static function hash(array $rules): string
    {
        return substr(hash('sha256', json_encode(self::normalize($rules))), 0, 16);
    }

    private static function clamp(mixed $value, float $fallback, float $min, float $max): float
    {
        $number = is_numeric($value) && is_finite((float) $value) ? (float) $value : $fallback;

        return max($min, min($max, $number));
    }
}
