-- More rain radar settings (/admin/radar, RadarConfig): how far back the
-- observations go, how often the job fetches (cron still runs every 5
-- minutes, the job skips runs in between) and how many processes render
-- frames. The forecast default becomes 60 minutes; a row that never saved
-- radar settings gets it too.
ALTER TABLE app_settings
    ADD COLUMN radar_history_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    ADD COLUMN radar_fetch_interval_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    ADD COLUMN radar_workers TINYINT UNSIGNED NOT NULL DEFAULT 4,
    MODIFY radar_forecast_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60;

UPDATE app_settings SET radar_forecast_minutes = 60 WHERE radar_regions IS NULL;
