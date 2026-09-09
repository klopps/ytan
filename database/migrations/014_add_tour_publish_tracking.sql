ALTER TABLE tour
    ADD COLUMN published_by BIGINT UNSIGNED NULL AFTER public,
    ADD COLUMN published_at DATETIME NULL AFTER published_by,
    ADD COLUMN created_at DATETIME NULL AFTER total_length,
    ADD COLUMN updated_at DATETIME NULL AFTER created_at,
    ADD CONSTRAINT FK_tour_published_by FOREIGN KEY (published_by) REFERENCES user (id) ON DELETE SET NULL;
