ALTER TABLE poi
    ADD COLUMN updated_at DATETIME NULL AFTER url;

ALTER TABLE route
    ADD COLUMN updated_at DATETIME NULL AFTER recording_duration_seconds;

ALTER TABLE area
    ADD COLUMN updated_at DATETIME NULL AFTER zindex;
