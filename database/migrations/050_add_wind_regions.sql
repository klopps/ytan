-- Own regions of the wind layer (JSON list of rectangles, worldwide); NULL = the rain radar's regions.
ALTER TABLE app_settings
    ADD COLUMN wind_regions TEXT NULL DEFAULT NULL;
