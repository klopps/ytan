ALTER TABLE app_settings
    ADD COLUMN track_distance_filter_precise_m SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    ADD COLUMN track_distance_filter_balanced_m SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    ADD COLUMN track_distance_filter_battery_m SMALLINT UNSIGNED NOT NULL DEFAULT 100;
