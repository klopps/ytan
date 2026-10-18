-- Upper limit for the area the rain radar job computes (/admin/radar,
-- RadarConfig): pixels of the rectangle enclosing all regions at zoom 8.
-- Was the constant RadarConfig::MAX_MOSAIC_PIXELS (120 M); at least 50 M.
ALTER TABLE app_settings
    ADD COLUMN radar_max_mosaic_pixels INT UNSIGNED NOT NULL DEFAULT 120000000;
