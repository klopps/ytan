ALTER TABLE route
    ADD COLUMN recorded_at DATETIME NULL AFTER color,
    ADD COLUMN recording_duration_seconds INT UNSIGNED NULL AFTER recorded_at;
