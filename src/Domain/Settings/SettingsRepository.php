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
}
