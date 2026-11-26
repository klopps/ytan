<?php

declare(strict_types=1);

namespace Ytan\Domain\Settings;

use PDO;

/**
 * The single app_settings row (id = 1). The row is read once per instance
 * (= per request) and kept: a page like / asks for half a dozen settings,
 * which used to be one SELECT each. Every setter drops the kept copy, so a
 * read after a write sees the new value.
 */
final class SettingsRepository
{
    /** @var array<string, mixed>|false|null null = not read yet, false = no row */
    private array|false|null $row = null;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string, mixed>|false */
    private function row(): array|false
    {
        if ($this->row === null) {
            $this->row = $this->db->query('SELECT * FROM app_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        }

        return $this->row;
    }

    /** One column of the row, false when there is no row (like PDOStatement::fetchColumn()). */
    private function column(string $name): mixed
    {
        $row = $this->row();

        return $row === false ? false : ($row[$name] ?? null);
    }

    /** @param list<mixed> $params */
    private function write(string $sql, array $params): void
    {
        $this->db->prepare($sql)->execute($params);
        $this->row = null;
    }

    public function googleSearchRequiresLogin(): bool
    {
        return (bool) $this->column('google_search_requires_login');
    }

    public function setGoogleSearchRequiresLogin(bool $enabled): void
    {
        $this->write('UPDATE app_settings SET google_search_requires_login = ? WHERE id = 1', [(int) $enabled]);
    }

    /**
     * "Allow GPX export for everyone (even signed out)": when set, anyone may
     * export every route they can see, independent of the export_routes_own/
     * export_routes_public rights (GpxExportController).
     */
    public function gpxExportPublic(): bool
    {
        return (bool) $this->column('gpx_export_public');
    }

    public function setGpxExportPublic(bool $enabled): void
    {
        $this->write('UPDATE app_settings SET gpx_export_public = ? WHERE id = 1', [(int) $enabled]);
    }

    /** Autorouting: the largest distance to the shore (metres) anybody can ask for. */
    public const AUTOROUTE_SHORE_MAX_M = 2000;

    /**
     * Autorouting (AutorouteController): the default minimum distance to the
     * shore in metres for every user who has not set their own, 0 = only keep
     * off the land.
     */
    public function autorouteShoreMeters(): int
    {
        return (int) $this->column('autoroute_shore_m');
    }

    public function setAutorouteShoreMeters(int $meters): void
    {
        $this->write('UPDATE app_settings SET autoroute_shore_m = ? WHERE id = 1', [$meters]);
    }

    /** Autorouting: the largest distance to the shore anybody can ask for (metres). */
    public const AUTOROUTE_SHORE_FAR_MAX_M = 5000;

    /** Autorouting: the default largest distance to the shore in metres, 0 = no limit. */
    public function autorouteShoreMaxMeters(): int
    {
        return (int) $this->column('autoroute_shore_max_m');
    }

    public function setAutorouteShoreMaxMeters(int $meters): void
    {
        $this->write('UPDATE app_settings SET autoroute_shore_max_m = ? WHERE id = 1', [$meters]);
    }

    /** Etmal (km per day): the range a user and the admin default may take. */
    public const ETMAL_MIN_KM = 5;
    public const ETMAL_MAX_KM = 60;

    /**
     * The etmal a NEW user starts with (UserRepository::create() reads the
     * column itself); changing it does not touch existing users.
     */
    public function etmalKm(): int
    {
        $value = (int) $this->column('etmal_km');

        return $value >= self::ETMAL_MIN_KM && $value <= self::ETMAL_MAX_KM ? $value : 25;
    }

    public function setEtmalKm(int $km): void
    {
        $this->write('UPDATE app_settings SET etmal_km = ? WHERE id = 1', [$km]);
    }

    /**
     * Merging two routes (RouteMergeController, RouteMerger): joined end
     * points closer than this are replaced by their midpoint, farther ones
     * get a connecting segment. 0 = only identical points are combined.
     */
    public function routeMergeMidpointMeters(): int
    {
        return (int) $this->column('route_merge_midpoint_m');
    }

    public function setRouteMergeMidpointMeters(int $meters): void
    {
        $this->write('UPDATE app_settings SET route_merge_midpoint_m = ? WHERE id = 1', [$meters]);
    }

    /**
     * Smoothed route lines (smoothRoutePoints(), helper.js, as
     * window.YTAN_ROUTE_SMOOTHING_SHARE): how far the curve may bulge from
     * the straight line, in percent of the shortest segment involved - at
     * least the fixed 15 m of config.js. 0 = only the fixed cap.
     */
    public function routeSmoothingSharePercent(): int
    {
        return (int) $this->column('route_smoothing_share_pct');
    }

    public function setRouteSmoothingSharePercent(int $percent): void
    {
        $this->write('UPDATE app_settings SET route_smoothing_share_pct = ? WHERE id = 1', [$percent]);
    }

    /**
     * The three distance-filter presets track-recorder.js offers when
     * starting a GPS recording (Capacitor background-geolocation's only
     * tunable lever for update frequency/battery use) - previously a
     * hardcoded JS constant, now admin-configurable via /admin/settings.
     *
     * @return array{precise: int, balanced: int, battery: int}
     */
    public function trackDistanceFilterPresets(): array
    {
        $row = $this->row();

        return [
            'precise' => (int) ($row['track_distance_filter_precise_m'] ?? 0),
            'balanced' => (int) ($row['track_distance_filter_balanced_m'] ?? 0),
            'battery' => (int) ($row['track_distance_filter_battery_m'] ?? 0),
        ];
    }

    public function setTrackDistanceFilterPresets(int $precise, int $balanced, int $battery): void
    {
        $this->write('UPDATE app_settings SET track_distance_filter_precise_m = ?, track_distance_filter_balanced_m = ?, track_distance_filter_battery_m = ? WHERE id = 1', [$precise, $balanced, $battery]);
    }

    /**
     * Min/max bounds (in px) for the Preferences screen's route-label font
     * size slider (todo.md "Font size for distance information") - the
     * slider itself is a per-user setting (settings.routeLabelFontSize,
     * settings.js's cookie), but its allowed range is site-wide and
     * admin-configurable via /admin/settings, same
     * "hardcoded constant -> admin-configurable via app_settings" pattern as
     * trackDistanceFilterPresets() above.
     *
     * @return array{min: int, max: int}
     */
    public function routeLabelFontSizeRange(): array
    {
        $row = $this->row();

        return [
            'min' => (int) ($row['route_label_font_size_min'] ?? 0),
            'max' => (int) ($row['route_label_font_size_max'] ?? 0),
        ];
    }

    public function setRouteLabelFontSizeRange(int $min, int $max): void
    {
        $this->write('UPDATE app_settings SET route_label_font_size_min = ?, route_label_font_size_max = ? WHERE id = 1', [$min, $max]);
    }

    /**
     * Route/tour animation (route-animation.js): line colour (#RRGGBB) and
     * width (px), the camera's zoom level and the pause after the last
     * waypoint before the map returns to the view from before (seconds), and
     * the default speed (screen px per second at that zoom; migration 065) -
     * /admin/settings, injected into the SPA as window.YTAN_ROUTE_ANIMATION.
     *
     * @return array{color: string, line_width: int, zoom: float, end_delay_s: float, speed: int}
     */
    public function routeAnimationSettings(): array
    {
        $row = $this->row();

        return [
            'color' => (string) ($row['route_animation_color'] ?? ''),
            'line_width' => (int) ($row['route_animation_line_width'] ?? 0),
            'zoom' => (float) ($row['route_animation_zoom'] ?? 0),
            'end_delay_s' => (float) ($row['route_animation_end_delay_s'] ?? 0),
            'speed' => (int) ($row['route_animation_speed'] ?? 0),
        ];
    }

    public function setRouteAnimationSettings(string $color, int $lineWidth, float $zoom, float $endDelaySeconds, int $speed): void
    {
        $this->write('UPDATE app_settings SET route_animation_color = ?, route_animation_line_width = ?, route_animation_zoom = ?, route_animation_end_delay_s = ?, route_animation_speed = ? WHERE id = 1', [$color, $lineWidth, $zoom, $endDelaySeconds, $speed]);
    }

    /** limits of the AR settings: radius in meters, POIs in the picture */
    public const AR_RADIUS_MIN_M = 100;
    public const AR_RADIUS_MAX_M = 50000;
    public const AR_POIS_MIN = 1;
    public const AR_POIS_MAX = 50;
    /** how far ahead the AR route mode draws the route, meters along it */
    public const AR_ROUTE_RANGE_MIN_M = 200;
    public const AR_ROUTE_RANGE_MAX_M = 30000;

    /**
     * AR view (ar-view.js, migrations 075/076): the admin's default and upper
     * limit for the radius (m), for the number of POIs in the camera picture
     * and for how far ahead the AR route mode draws the route (m) -
     * /admin/settings, injected into the SPA as window.YTAN_AR. Every user
     * picks own values up to the limits (settings cookie). Read back
     * clamped, so a hand-edited row can't break the view.
     *
     * @return array{radius_m: int, radius_max_m: int, max_pois: int, max_pois_limit: int, route_range_m: int, route_range_max_m: int}
     */
    public function arSettings(): array
    {
        $row = $this->row();

        return self::clampAr(
            (int) ($row['ar_radius_m'] ?? 2000),
            (int) ($row['ar_radius_max_m'] ?? 10000),
            (int) ($row['ar_max_pois'] ?? 10),
            (int) ($row['ar_max_pois_limit'] ?? 30),
            (int) ($row['ar_route_range_m'] ?? 3000),
            (int) ($row['ar_route_range_max_m'] ?? 10000),
        );
    }

    /** @return array{radius_m: int, radius_max_m: int, max_pois: int, max_pois_limit: int, route_range_m: int, route_range_max_m: int} the values as stored */
    public function setArSettings(int $radius, int $radiusMax, int $maxPois, int $maxPoisLimit, int $routeRange, int $routeRangeMax): array
    {
        $values = self::clampAr($radius, $radiusMax, $maxPois, $maxPoisLimit, $routeRange, $routeRangeMax);
        $this->write('UPDATE app_settings SET ar_radius_m = ?, ar_radius_max_m = ?, ar_max_pois = ?, ar_max_pois_limit = ?, ar_route_range_m = ?, ar_route_range_max_m = ? WHERE id = 1', array_values($values));

        return $values;
    }

    /**
     * Each value into its range, a default never above its limit.
     *
     * @return array{radius_m: int, radius_max_m: int, max_pois: int, max_pois_limit: int, route_range_m: int, route_range_max_m: int}
     */
    public static function clampAr(int $radius, int $radiusMax, int $maxPois, int $maxPoisLimit, int $routeRange = 3000, int $routeRangeMax = 10000): array
    {
        $radiusMax = max(self::AR_RADIUS_MIN_M, min(self::AR_RADIUS_MAX_M, $radiusMax));
        $maxPoisLimit = max(self::AR_POIS_MIN, min(self::AR_POIS_MAX, $maxPoisLimit));
        $routeRangeMax = max(self::AR_ROUTE_RANGE_MIN_M, min(self::AR_ROUTE_RANGE_MAX_M, $routeRangeMax));

        return [
            'radius_m' => max(self::AR_RADIUS_MIN_M, min($radiusMax, $radius)),
            'radius_max_m' => $radiusMax,
            'max_pois' => max(self::AR_POIS_MIN, min($maxPoisLimit, $maxPois)),
            'max_pois_limit' => $maxPoisLimit,
            'route_range_m' => max(self::AR_ROUTE_RANGE_MIN_M, min($routeRangeMax, $routeRange)),
            'route_range_max_m' => $routeRangeMax,
        ];
    }

    /**
     * The POI types whose distance to water is computed and stored
     * (poi_water_distance, PoiWaterDistanceService) - /admin/settings.
     *
     * @return list<int>
     */
    public function waterDistancePoiTypes(): array
    {
        $types = json_decode((string) $this->column('water_distance_poitypes'), true);

        return is_array($types) ? array_values(array_map('intval', array_filter($types, 'is_numeric'))) : [];
    }

    /** @param list<int> $types */
    public function setWaterDistancePoiTypes(array $types): void
    {
        $types = array_values(array_unique(array_map('intval', $types)));
        sort($types);
        $this->write('UPDATE app_settings SET water_distance_poitypes = ? WHERE id = 1', [json_encode($types)]);
    }

    /**
     * Rain radar settings (RadarConfig): forecast length and regions. A row
     * that was never saved (radar_regions NULL) gets RadarConfig::defaults().
     *
     * @return array{forecast_minutes: int, presets: list<string>, custom: list<array<string, mixed>>}
     */
    public function radarSettings(): array
    {
        $row = $this->row();
        $config = \Ytan\Service\RadarConfig::defaults();
        if ($row === false) {
            return $config;
        }
        $config['history_minutes'] = (int) $row['radar_history_minutes'];
        $config['forecast_minutes'] = (int) $row['radar_forecast_minutes'];
        $config['fetch_interval_minutes'] = (int) $row['radar_fetch_interval_minutes'];
        $config['workers'] = (int) $row['radar_workers'];
        $config['max_mosaic_pixels'] = (int) $row['radar_max_mosaic_pixels'];
        $config['paused_until'] = $row['radar_paused_until'] !== null
            ? str_replace(' ', 'T', (string) $row['radar_paused_until']) . 'Z' : null;
        $config['paused_forever'] = (bool) $row['radar_paused_forever'];
        $regions = $row['radar_regions'] !== null ? json_decode((string) $row['radar_regions'], true) : null;
        if (is_array($regions)) {
            // a code no longer in PRESETS (renamed/removed) is dropped, not an error
            $config['presets'] = array_values(array_filter((array) ($regions['presets'] ?? []),
                fn ($code) => is_string($code) && isset(\Ytan\Service\RadarConfig::PRESETS[$code])));
            $config['custom'] = array_values(array_filter((array) ($regions['custom'] ?? []), 'is_array'));
        }

        return $config;
    }

    /**
     * Pause the radar job until $untilUtc (Y-m-d H:i:s, UTC) or, with
     * $forever, until it is resumed; both null/false = resume.
     */
    public function setRadarPause(?string $untilUtc, bool $forever): void
    {
        $this->write('UPDATE app_settings SET radar_paused_until = ?, radar_paused_forever = ? WHERE id = 1', [$untilUtc, (int) $forever]);
    }

    /** @param array<string, mixed> $config normalized by RadarConfig::normalize() */
    public function setRadarSettings(array $config): void
    {
        $this->write('UPDATE app_settings SET radar_history_minutes = ?, radar_forecast_minutes = ?, radar_fetch_interval_minutes = ?, radar_workers = ?, radar_max_mosaic_pixels = ?, radar_regions = ? WHERE id = 1', [
            $config['history_minutes'],
            $config['forecast_minutes'],
            $config['fetch_interval_minutes'],
            $config['workers'],
            $config['max_mosaic_pixels'],
            json_encode(['presets' => $config['presets'], 'custom' => $config['custom']], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Wind job settings (WindConfig); a row that was never saved has the column defaults.
     *
     * @return array{enabled: bool, horizon_hours: int, run_interval_hours: int, gusts: bool, fine: bool, world: bool, meps: bool, arome: bool, regions: array|null}
     */
    public function windSettings(): array
    {
        $row = $this->row();
        if ($row === false) {
            return \Ytan\Service\WindConfig::defaults();
        }

        return [
            'enabled' => (bool) $row['wind_enabled'], 'horizon_hours' => (int) $row['wind_horizon_hours'],
            'run_interval_hours' => (int) $row['wind_run_interval_hours'],
            'gusts' => (bool) $row['wind_gusts'], 'fine' => (bool) $row['wind_fine'], 'world' => (bool) $row['wind_world'],
            'meps' => (bool) $row['wind_meps'], 'arome' => (bool) $row['wind_arome'],
            'regions' => $row['wind_regions'] === null ? null : (json_decode((string) $row['wind_regions'], true) ?: null),
        ];
    }

    /**
     * The registered YTAN workers (migration 081, /admin/workers): unique names, label, note.
     *
     * @return list<array{id: string, label: string, note: string}>
     */
    public function workerRegistry(): array
    {
        $value = $this->column('workers');

        return \Ytan\Service\JobPlacement::normalizeRegistry($value === false || $value === null ? [] : json_decode((string) $value, true), false);
    }

    /** @param list<array{id: string, label: string, note: string}> $workers normalized by JobPlacement::normalizeRegistry() */
    public function setWorkerRegistry(array $workers): void
    {
        $this->write('UPDATE app_settings SET workers = ? WHERE id = 1', [json_encode($workers, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Per job the priority list of the workers (and "server") that may run it (JobPlacement); a job
     * without a list - or an entry of a worker that is no longer registered - falls back to the server.
     *
     * @return array<string, list<array{id: string, enabled: bool}>>
     */
    public function jobWorkers(): array
    {
        $value = $this->column('job_workers');
        $registered = array_column($this->workerRegistry(), 'id');

        return \Ytan\Service\JobPlacement::normalizeLists($value === false || $value === null ? null : json_decode((string) $value, true), $registered, false);
    }

    /** @param array<string, list<array{id: string, enabled: bool}>> $lists normalized by JobPlacement::normalizeLists() */
    public function setJobWorkers(array $lists): void
    {
        $this->write('UPDATE app_settings SET job_workers = ? WHERE id = 1', [json_encode($lists)]);
    }

    /**
     * The jobs whose first enabled entry is not this server - what config.json's "external_jobs" says
     * for workers that predate the lists (JobPlacement::externalFromLists()).
     *
     * @return list<string>
     */
    public function externalJobs(): array
    {
        return \Ytan\Service\JobPlacement::externalFromLists($this->jobWorkers());
    }

    /**
     * Routencheck (migration 070, /admin/ai): the whole feature on/off, model
     * requests per user and day, how many hours a generated text is reused.
     *
     * @return array{enabled: bool, daily_limit: int, cache_hours: int}
     */
    public function aiSettings(): array
    {
        $row = $this->row();
        if ($row === false) {
            return ['enabled' => false, 'daily_limit' => 20, 'cache_hours' => 3];
        }

        return ['enabled' => (bool) $row['ai_enabled'], 'daily_limit' => (int) $row['ai_daily_limit'], 'cache_hours' => (int) $row['ai_cache_hours']];
    }

    public function setAiSettings(bool $enabled, int $dailyLimit, int $cacheHours): void
    {
        $this->write('UPDATE app_settings SET ai_enabled = ?, ai_daily_limit = ?, ai_cache_hours = ? WHERE id = 1', [(int) $enabled, $dailyLimit, $cacheHours]);
    }

    /**
     * The Routencheck's traffic-light thresholds, normalized (NULL in the
     * database = BriefingRules::defaults()).
     *
     * @return array<string, mixed>
     */
    public function briefingRules(): array
    {
        $value = $this->column('briefing_rules');
        $rules = is_string($value) ? json_decode($value, true) : null;

        return \Ytan\Service\Ai\BriefingRules::normalize(is_array($rules) ? $rules : null);
    }

    /** @param array<string, mixed>|null $rules null = back to the defaults */
    public function setBriefingRules(?array $rules): void
    {
        $this->write('UPDATE app_settings SET briefing_rules = ? WHERE id = 1', [
            $rules === null ? null : json_encode(\Ytan\Service\Ai\BriefingRules::normalize($rules)),
        ]);
    }

    /** @param array<string, mixed> $config normalized by WindConfig::normalize() */
    public function setWindSettings(array $config): void
    {
        $this->write('UPDATE app_settings SET wind_enabled = ?, wind_horizon_hours = ?, wind_run_interval_hours = ?, wind_gusts = ?, wind_fine = ?, wind_world = ?, wind_meps = ?, wind_arome = ?, wind_regions = ? WHERE id = 1', [
            (int) $config['enabled'], $config['horizon_hours'], $config['run_interval_hours'], (int) $config['gusts'], (int) $config['fine'], (int) $config['world'], (int) $config['meps'], (int) $config['arome'],
            $config['regions'] === null ? null : json_encode($config['regions'], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
