-- Rain radar settings (/admin/settings, RadarSettingsController): forecast
-- length in minutes (0-120, steps of 5) and the regions the radar job
-- computes - JSON {"presets": ["de", ...], "custom": [{"name", "south",
-- "west", "north", "east"}, ...]}, NULL = the defaults of RadarConfig.
ALTER TABLE app_settings
    ADD COLUMN radar_forecast_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    ADD COLUMN radar_regions TEXT NULL;
