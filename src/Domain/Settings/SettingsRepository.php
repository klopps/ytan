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
     * Rain radar settings (RadarConfig): forecast length and regions. A row
     * that was never saved (radar_regions NULL) gets RadarConfig::defaults().
     *
     * @return array{forecast_minutes: int, presets: list<string>, custom: list<array<string, mixed>>}
     */
    public function radarSettings(): array
    {
        $stmt = $this->db->query('SELECT radar_history_minutes, radar_forecast_minutes, radar_fetch_interval_minutes, radar_workers, radar_max_mosaic_pixels, radar_regions FROM app_settings WHERE id = 1');
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
        $regions = $row['radar_regions'] !== null ? json_decode((string) $row['radar_regions'], true) : null;
        if (is_array($regions)) {
            // a code no longer in PRESETS (renamed/removed) is dropped, not an error
            $config['presets'] = array_values(array_filter((array) ($regions['presets'] ?? []),
                fn ($code) => is_string($code) && isset(\Ytan\Service\RadarConfig::PRESETS[$code])));
            $config['custom'] = array_values(array_filter((array) ($regions['custom'] ?? []), 'is_array'));
        }

        return $config;
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
}
