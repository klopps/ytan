<?php

declare(strict_types=1);

namespace Ytan\Domain\Settings;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function googleSearchRequiresLogin(): bool
    {
        $stmt = $this->db->query('SELECT google_search_requires_login FROM app_settings WHERE id = 1');

        return (bool) $stmt->fetchColumn();
    }

    public function setGoogleSearchRequiresLogin(bool $enabled): void
    {
        $stmt = $this->db->prepare('UPDATE app_settings SET google_search_requires_login = ? WHERE id = 1');
        $stmt->execute([(int) $enabled]);
    }

    /**
     * "Allow GPX export for everyone (even signed out)": when set, anyone may
     * export every route they can see, independent of the export_routes_own/
     * export_routes_public rights (GpxExportController).
     */
    public function gpxExportPublic(): bool
    {
        $stmt = $this->db->query('SELECT gpx_export_public FROM app_settings WHERE id = 1');

        return (bool) $stmt->fetchColumn();
    }

    public function setGpxExportPublic(bool $enabled): void
    {
        $stmt = $this->db->prepare('UPDATE app_settings SET gpx_export_public = ? WHERE id = 1');
        $stmt->execute([(int) $enabled]);
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
        $stmt = $this->db->query('SELECT track_distance_filter_precise_m, track_distance_filter_balanced_m, track_distance_filter_battery_m FROM app_settings WHERE id = 1');
        $row = $stmt->fetch();

        return [
            'precise' => (int) $row['track_distance_filter_precise_m'],
            'balanced' => (int) $row['track_distance_filter_balanced_m'],
            'battery' => (int) $row['track_distance_filter_battery_m'],
        ];
    }

    public function setTrackDistanceFilterPresets(int $precise, int $balanced, int $battery): void
    {
        $stmt = $this->db->prepare('UPDATE app_settings SET track_distance_filter_precise_m = ?, track_distance_filter_balanced_m = ?, track_distance_filter_battery_m = ? WHERE id = 1');
        $stmt->execute([$precise, $balanced, $battery]);
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
        $stmt = $this->db->query('SELECT route_label_font_size_min, route_label_font_size_max FROM app_settings WHERE id = 1');
        $row = $stmt->fetch();

        return [
            'min' => (int) $row['route_label_font_size_min'],
            'max' => (int) $row['route_label_font_size_max'],
        ];
    }

    public function setRouteLabelFontSizeRange(int $min, int $max): void
    {
        $stmt = $this->db->prepare('UPDATE app_settings SET route_label_font_size_min = ?, route_label_font_size_max = ? WHERE id = 1');
        $stmt->execute([$min, $max]);
    }

    /**
     * The POI types whose distance to water is computed and stored
     * (poi_water_distance, PoiWaterDistanceService) - /admin/settings.
     *
     * @return list<int>
     */
    public function waterDistancePoiTypes(): array
    {
        $stmt = $this->db->query('SELECT water_distance_poitypes FROM app_settings WHERE id = 1');
        $types = json_decode((string) $stmt->fetchColumn(), true);

        return is_array($types) ? array_values(array_map('intval', array_filter($types, 'is_numeric'))) : [];
    }

    /** @param list<int> $types */
    public function setWaterDistancePoiTypes(array $types): void
    {
        $types = array_values(array_unique(array_map('intval', $types)));
        sort($types);
        $this->db->prepare('UPDATE app_settings SET water_distance_poitypes = ? WHERE id = 1')->execute([json_encode($types)]);
    }

    /**
     * Rain radar settings (RadarConfig): forecast length and regions. A row
     * that was never saved (radar_regions NULL) gets RadarConfig::defaults().
     *
     * @return array{forecast_minutes: int, presets: list<string>, custom: list<array<string, mixed>>}
     */
    public function radarSettings(): array
    {
        $stmt = $this->db->query('SELECT radar_history_minutes, radar_forecast_minutes, radar_fetch_interval_minutes, radar_workers, radar_max_mosaic_pixels, radar_regions, radar_paused_until, radar_paused_forever FROM app_settings WHERE id = 1');
        $row = $stmt->fetch();
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
        $stmt = $this->db->prepare('UPDATE app_settings SET radar_paused_until = ?, radar_paused_forever = ? WHERE id = 1');
        $stmt->execute([$untilUtc, (int) $forever]);
    }

    /** @param array<string, mixed> $config normalized by RadarConfig::normalize() */
    public function setRadarSettings(array $config): void
    {
        $stmt = $this->db->prepare('UPDATE app_settings SET radar_history_minutes = ?, radar_forecast_minutes = ?, radar_fetch_interval_minutes = ?, radar_workers = ?, radar_max_mosaic_pixels = ?, radar_regions = ? WHERE id = 1');
        $stmt->execute([
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
     * @return array{enabled: bool, horizon_hours: int, run_interval_hours: int, gusts: bool, fine: bool, world: bool, regions: array|null}
     */
    public function windSettings(): array
    {
        $row = $this->db->query('SELECT wind_enabled, wind_horizon_hours, wind_run_interval_hours, wind_gusts, wind_fine, wind_world, wind_regions FROM app_settings WHERE id = 1')->fetch();
        if ($row === false) {
            return \Ytan\Service\WindConfig::defaults();
        }

        return [
            'enabled' => (bool) $row['wind_enabled'], 'horizon_hours' => (int) $row['wind_horizon_hours'],
            'run_interval_hours' => (int) $row['wind_run_interval_hours'],
            'gusts' => (bool) $row['wind_gusts'], 'fine' => (bool) $row['wind_fine'], 'world' => (bool) $row['wind_world'],
            'regions' => $row['wind_regions'] === null ? null : (json_decode((string) $row['wind_regions'], true) ?: null),
        ];
    }

    /** @param array<string, mixed> $config normalized by WindConfig::normalize() */
    public function setWindSettings(array $config): void
    {
        $stmt = $this->db->prepare('UPDATE app_settings SET wind_enabled = ?, wind_horizon_hours = ?, wind_run_interval_hours = ?, wind_gusts = ?, wind_fine = ?, wind_world = ?, wind_regions = ? WHERE id = 1');
        $stmt->execute([(int) $config['enabled'], $config['horizon_hours'], $config['run_interval_hours'], (int) $config['gusts'], (int) $config['fine'], (int) $config['world'],
            $config['regions'] === null ? null : json_encode($config['regions'], JSON_UNESCAPED_UNICODE)]);
    }
}
