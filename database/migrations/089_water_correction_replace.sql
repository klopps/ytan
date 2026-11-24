-- Water index corrections (/admin/water): type "replace" = take an existing
-- line out and put a new one (or none = delete it) in its place. "original"
-- is the old line as drawn on the page ([[lat, lng], ...]); segments of the
-- same kind with both ends within WaterCorrections::REPLACE_BUFFER_M of it are
-- removed - a geometric test, so the correction survives a rebuild of the index.
ALTER TABLE water_correction
    MODIFY type ENUM('delete', 'flip', 'add', 'replace') NOT NULL,
    ADD COLUMN original MEDIUMTEXT NULL AFTER points;
