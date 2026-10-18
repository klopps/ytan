<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ValidationException;

/**
 * Rain radar settings for the job in tools/radar/: how far back the
 * observations and how far ahead the forecast go, which regions it
 * computes, how often it fetches and how many processes render. Stored in
 * app_settings (SettingsRepository::radarSettings()), edited in /admin/radar,
 * and handed to the job as storage/radar/config.json (writeJobConfig()) -
 * the Python job has no database access. The job reports back in
 * storage/radar/status.json (readStatus()).
 *
 * A region is a rectangle (south/west/north/east in degrees): either one of
 * PRESETS (country boxes) or one the admin draws on a map. The job renders
 * the rectangle enclosing all regions and leaves everything outside the
 * regions transparent; its memory and runtime grow with that enclosing
 * rectangle, hence the admin setting max_mosaic_pixels.
 */
final class RadarConfig
{
    public const FORECAST_MAX_MINUTES = 120;
    public const FORECAST_STEP_MINUTES = 5;
    public const FORECAST_DEFAULT_MINUTES = 60;
    public const HISTORY_MIN_MINUTES = 5;
    public const HISTORY_MAX_MINUTES = 60;
    public const HISTORY_DEFAULT_MINUTES = 60;
    /** The cron job keeps running every 5 minutes; the job skips runs in between. */
    public const INTERVAL_MIN_MINUTES = 5;
    public const INTERVAL_MAX_MINUTES = 15;
    public const INTERVAL_DEFAULT_MINUTES = 5;
    public const WORKERS_MIN = 1;
    public const WORKERS_MAX = 12;
    public const WORKERS_DEFAULT = 4;
    /** Choices for pausing the job for a while (minutes); or for good. */
    public const PAUSE_MINUTES = [15, 30, 60];
    public const MAX_CUSTOM_REGIONS = 10;
    public const NAME_MAX_LENGTH = 60;

    /**
     * Limit for the pixels of the enclosing rectangle at the job's tile zoom
     * 8 (admin setting max_mosaic_pixels). DE+DK+SE is ~26 M; the job's
     * reprojection lookup takes about 8 bytes per pixel, so the default of
     * 120 M is about 1 GB on the server, the upper bound 500 M about 4 GB.
     */
    public const MAX_MOSAIC_PIXELS_DEFAULT = 120_000_000;
    public const MAX_MOSAIC_PIXELS_MIN = 50_000_000;
    public const MAX_MOSAIC_PIXELS_LIMIT = 500_000_000;

    /** Composite coverage (EUMETNET OPERA, roughly): regions must lie inside. */
    public const COVERAGE = ['south' => 32.0, 'west' => -30.0, 'north' => 72.0, 'east' => 45.0];

    /**
     * Every European country (ISO 3166 codes, lower case; Kosovo xk) as a box
     * around mainland and nearby islands, inside COVERAGE (Russia cut at
     * 45 E, overseas parts such as the Azores or the Canaries left out).
     * Names: i18n admin.settings.radar_region.{code}. Not every country
     * delivers radar data to OPERA - for some the map stays (nearly) empty.
     */
    public const PRESETS = [
        'ad' => ['south' => 42.4, 'west' => 1.4, 'north' => 42.7, 'east' => 1.8],
        'al' => ['south' => 39.6, 'west' => 19.2, 'north' => 42.7, 'east' => 21.1],
        'at' => ['south' => 46.3, 'west' => 9.5, 'north' => 49.1, 'east' => 17.2],
        'ba' => ['south' => 42.5, 'west' => 15.7, 'north' => 45.3, 'east' => 19.7],
        'be' => ['south' => 49.4, 'west' => 2.5, 'north' => 51.6, 'east' => 6.5],
        'bg' => ['south' => 41.2, 'west' => 22.3, 'north' => 44.3, 'east' => 28.7],
        'by' => ['south' => 51.2, 'west' => 23.1, 'north' => 56.2, 'east' => 32.8],
        'ch' => ['south' => 45.8, 'west' => 5.9, 'north' => 47.8, 'east' => 10.5],
        'cy' => ['south' => 34.5, 'west' => 32.2, 'north' => 35.8, 'east' => 34.7],
        'cz' => ['south' => 48.5, 'west' => 12.0, 'north' => 51.1, 'east' => 18.9],
        'de' => ['south' => 47.2, 'west' => 5.8, 'north' => 55.1, 'east' => 15.1],
        'dk' => ['south' => 54.5, 'west' => 7.9, 'north' => 57.8, 'east' => 15.3],
        'ee' => ['south' => 57.4, 'west' => 21.6, 'north' => 59.8, 'east' => 28.3],
        'es' => ['south' => 35.9, 'west' => -9.4, 'north' => 43.8, 'east' => 4.4],
        'fi' => ['south' => 59.7, 'west' => 20.5, 'north' => 70.1, 'east' => 31.6],
        'fr' => ['south' => 41.3, 'west' => -5.3, 'north' => 51.2, 'east' => 9.7],
        'gb' => ['south' => 49.8, 'west' => -8.7, 'north' => 60.9, 'east' => 1.8],
        'gr' => ['south' => 34.7, 'west' => 19.3, 'north' => 41.8, 'east' => 29.7],
        'hr' => ['south' => 42.3, 'west' => 13.4, 'north' => 46.6, 'east' => 19.5],
        'hu' => ['south' => 45.7, 'west' => 16.1, 'north' => 48.6, 'east' => 22.9],
        'ie' => ['south' => 51.4, 'west' => -10.7, 'north' => 55.4, 'east' => -5.9],
        'is' => ['south' => 63.2, 'west' => -24.6, 'north' => 66.6, 'east' => -13.4],
        'it' => ['south' => 35.4, 'west' => 6.6, 'north' => 47.1, 'east' => 18.6],
        'li' => ['south' => 47.0, 'west' => 9.4, 'north' => 47.3, 'east' => 9.7],
        'lt' => ['south' => 53.8, 'west' => 20.9, 'north' => 56.5, 'east' => 26.9],
        'lu' => ['south' => 49.4, 'west' => 5.7, 'north' => 50.2, 'east' => 6.6],
        'lv' => ['south' => 55.6, 'west' => 20.9, 'north' => 58.1, 'east' => 28.3],
        'mc' => ['south' => 43.7, 'west' => 7.4, 'north' => 43.8, 'east' => 7.5],
        'md' => ['south' => 45.4, 'west' => 26.6, 'north' => 48.5, 'east' => 30.2],
        'me' => ['south' => 41.8, 'west' => 18.4, 'north' => 43.6, 'east' => 20.4],
        'mk' => ['south' => 40.8, 'west' => 20.4, 'north' => 42.4, 'east' => 23.1],
        'mt' => ['south' => 35.8, 'west' => 14.1, 'north' => 36.1, 'east' => 14.6],
        'nl' => ['south' => 50.7, 'west' => 3.3, 'north' => 53.6, 'east' => 7.3],
        'no' => ['south' => 57.8, 'west' => 4.4, 'north' => 71.2, 'east' => 31.2],
        'pl' => ['south' => 49.0, 'west' => 14.1, 'north' => 54.9, 'east' => 24.2],
        'pt' => ['south' => 36.9, 'west' => -9.6, 'north' => 42.2, 'east' => -6.1],
        'ro' => ['south' => 43.6, 'west' => 20.2, 'north' => 48.3, 'east' => 29.8],
        'rs' => ['south' => 42.2, 'west' => 18.8, 'north' => 46.2, 'east' => 23.0],
        'ru' => ['south' => 41.1, 'west' => 27.3, 'north' => 72.0, 'east' => 45.0],
        'se' => ['south' => 55.2, 'west' => 10.8, 'north' => 69.1, 'east' => 24.2],
        'si' => ['south' => 45.4, 'west' => 13.4, 'north' => 46.9, 'east' => 16.6],
        'sk' => ['south' => 47.7, 'west' => 16.8, 'north' => 49.6, 'east' => 22.6],
        'sm' => ['south' => 43.9, 'west' => 12.4, 'north' => 44.0, 'east' => 12.6],
        'tr' => ['south' => 35.8, 'west' => 26.0, 'north' => 42.1, 'east' => 44.8],
        'ua' => ['south' => 44.3, 'west' => 22.1, 'north' => 52.4, 'east' => 40.2],
        'va' => ['south' => 41.9, 'west' => 12.44, 'north' => 41.91, 'east' => 12.46],
        'xk' => ['south' => 41.8, 'west' => 20.0, 'north' => 43.3, 'east' => 21.8],
    ];

    public const DEFAULT_PRESETS = ['de', 'dk', 'se'];

    /**
     * Validates and normalizes the admin's input.
     *
     * @param array<string, mixed> $input {history_minutes, forecast_minutes, fetch_interval_minutes, workers,
     *                                    presets: string[], custom: [{name, south, west, north, east}]}
     * @return array{history_minutes: int, forecast_minutes: int, fetch_interval_minutes: int, workers: int,
     *               presets: list<string>, custom: list<array{name: string, south: float, west: float, north: float, east: float}>}
     */
    public static function normalize(array $input): array
    {
        $history = self::steppedInt($input['history_minutes'] ?? null, self::HISTORY_MIN_MINUTES, self::HISTORY_MAX_MINUTES, 5,
            'history_minutes must be 5 to 60 in steps of 5.', 'radar.settings_invalid_history');
        $minutes = self::steppedInt($input['forecast_minutes'] ?? null, 0, self::FORECAST_MAX_MINUTES, self::FORECAST_STEP_MINUTES,
            'forecast_minutes must be 0 to 120 in steps of 5.', 'radar.settings_invalid_forecast');
        $interval = self::steppedInt($input['fetch_interval_minutes'] ?? null, self::INTERVAL_MIN_MINUTES, self::INTERVAL_MAX_MINUTES, 5,
            'fetch_interval_minutes must be 5, 10 or 15.', 'radar.settings_invalid_interval');
        $workers = self::steppedInt($input['workers'] ?? null, self::WORKERS_MIN, self::WORKERS_MAX, 1,
            'workers must be 1 to 12.', 'radar.settings_invalid_workers');
        $maxPixels = self::steppedInt($input['max_mosaic_pixels'] ?? null, self::MAX_MOSAIC_PIXELS_MIN,
            self::MAX_MOSAIC_PIXELS_LIMIT, 1, 'max_mosaic_pixels must be 50 to 500 million.',
            'radar.settings_invalid_max_mosaic');

        $presets = [];
        foreach ((array) ($input['presets'] ?? []) as $code) {
            if (!is_string($code) || !isset(self::PRESETS[$code])) {
                throw new ValidationException('Unknown radar region.', 'radar.settings_unknown_region');
            }
            $presets[$code] = true;
        }
        // keep the PRESETS order, whatever order the client sent
        $presets = array_values(array_filter(array_keys(self::PRESETS), fn ($code) => isset($presets[$code])));

        $customInput = $input['custom'] ?? [];
        if (!is_array($customInput) || count($customInput) > self::MAX_CUSTOM_REGIONS) {
            throw new ValidationException('At most 10 own regions.', 'radar.settings_too_many_regions');
        }
        $custom = [];
        foreach ($customInput as $region) {
            $custom[] = self::normalizeRect(is_array($region) ? $region : []);
        }

        $config = [
            'history_minutes' => $history, 'forecast_minutes' => $minutes,
            'fetch_interval_minutes' => $interval, 'workers' => $workers,
            'max_mosaic_pixels' => $maxPixels,
            'presets' => $presets, 'custom' => $custom,
        ];
        $regions = self::regions($config);
        if ($regions === []) {
            throw new ValidationException('Choose at least one region.', 'radar.settings_no_region');
        }
        if (self::mosaicPixels(self::enclosing($regions)) > $maxPixels) {
            throw new ValidationException('The regions together are too large.', 'radar.settings_too_large');
        }

        return $config;
    }

    private static function steppedInt(mixed $value, int $min, int $max, int $step, string $message, string $code): int
    {
        $v = filter_var($value, FILTER_VALIDATE_INT);
        if ($v === false || $v < $min || $v > $max || ($v - $min) % $step !== 0) {
            throw new ValidationException($message, $code);
        }

        return $v;
    }

    /**
     * @param array<string, mixed> $r
     * @return array{name: string, south: float, west: float, north: float, east: float}
     */
    private static function normalizeRect(array $r): array
    {
        $out = ['name' => mb_substr(trim((string) ($r['name'] ?? '')), 0, self::NAME_MAX_LENGTH)];
        foreach (['south', 'west', 'north', 'east'] as $side) {
            $v = filter_var($r[$side] ?? null, FILTER_VALIDATE_FLOAT);
            if ($v === false || !is_finite($v)) {
                throw new ValidationException('A region needs south, west, north and east in degrees.', 'radar.settings_invalid_region');
            }
            $out[$side] = round($v, 4);
        }
        $c = self::COVERAGE;
        if ($out['south'] >= $out['north'] || $out['west'] >= $out['east']
            || $out['south'] < $c['south'] || $out['north'] > $c['north']
            || $out['west'] < $c['west'] || $out['east'] > $c['east']) {
            throw new ValidationException('A region lies outside the radar coverage or is empty.', 'radar.settings_invalid_region');
        }

        return $out;
    }

    /** Settings as stored before anything was saved. */
    public static function defaults(): array
    {
        return [
            'history_minutes' => self::HISTORY_DEFAULT_MINUTES,
            'forecast_minutes' => self::FORECAST_DEFAULT_MINUTES,
            'fetch_interval_minutes' => self::INTERVAL_DEFAULT_MINUTES,
            'workers' => self::WORKERS_DEFAULT,
            'max_mosaic_pixels' => self::MAX_MOSAIC_PIXELS_DEFAULT,
            'paused_until' => null,
            'paused_forever' => false,
            'presets' => self::DEFAULT_PRESETS,
            'custom' => [],
        ];
    }

    /**
     * The rectangles the job computes, presets first.
     *
     * @return list<array{name: string, south: float, west: float, north: float, east: float}>
     */
    public static function regions(array $config): array
    {
        $regions = [];
        foreach ($config['presets'] as $code) {
            $regions[] = ['name' => $code] + self::PRESETS[$code];
        }
        foreach ($config['custom'] as $rect) {
            $regions[] = $rect;
        }

        return $regions;
    }

    /** @return array{south: float, west: float, north: float, east: float} */
    public static function enclosing(array $regions): array
    {
        return [
            'south' => min(array_column($regions, 'south')),
            'west' => min(array_column($regions, 'west')),
            'north' => max(array_column($regions, 'north')),
            'east' => max(array_column($regions, 'east')),
        ];
    }

    /** Size of a rectangle in Web Mercator pixels at the job's tile zoom 8. */
    public static function mosaicPixels(array $box): float
    {
        $world = 256 * 2 ** 8;
        $y = fn (float $lat) => (0.5 - log((1 + sin(deg2rad($lat))) / (1 - sin(deg2rad($lat)))) / (4 * M_PI)) * $world;
        $width = ($box['east'] - $box['west']) / 360 * $world;
        $height = $y($box['south']) - $y($box['north']);

        return $width * $height;
    }

    /**
     * storage/radar/config.json for the jobs, written atomically. The wind
     * job (tools/wind/) reads the same regions plus its own "wind" object.
     *
     * @param array<string, mixed>|null $wind WindConfig settings; null = the defaults
     */
    public static function writeJobConfig(string $radarDir, array $config, ?array $wind = null): void
    {
        if (!is_dir($radarDir) && !mkdir($radarDir, 0775, true) && !is_dir($radarDir)) {
            throw new \RuntimeException('Cannot create ' . $radarDir);
        }
        $json = json_encode([
            'history_minutes' => $config['history_minutes'],
            'forecast_minutes' => $config['forecast_minutes'],
            'fetch_interval_minutes' => $config['fetch_interval_minutes'],
            'workers' => $config['workers'],
            'paused_until' => $config['paused_until'] ?? null,
            'paused_forever' => (bool) ($config['paused_forever'] ?? false),
            'regions' => self::regions($config),
            'wind' => $wind ?? WindConfig::defaults(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $radarDir . '/config.json.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $radarDir . '/config.json')) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write the radar job configuration.');
        }
    }

    /** Whether the job is paused right now (until a time still ahead, or for good). */
    public static function isPaused(array $config, ?int $now = null): bool
    {
        if (!empty($config['paused_forever'])) {
            return true;
        }
        $until = $config['paused_until'] ?? null;

        return $until !== null && strtotime($until) > ($now ?? time());
    }

    /**
     * What the job reported about its last runs (storage/radar/status.json,
     * written by tools/radar/ytanradar/job.py), null before its first run.
     *
     * @return array<string, mixed>|null
     */
    public static function readStatus(string $radarDir): ?array
    {
        $json = @file_get_contents($radarDir . '/status.json');
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
