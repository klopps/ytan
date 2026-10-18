-- Pausing the rain radar job (/admin/radar, RadarSettingsController::pause()):
-- until a time (UTC, 15/30/60 minutes from the moment it was set) or for
-- good. NULL/0 = running normally.
ALTER TABLE app_settings
    ADD COLUMN radar_paused_until DATETIME NULL,
    ADD COLUMN radar_paused_forever TINYINT(1) NOT NULL DEFAULT 0;
