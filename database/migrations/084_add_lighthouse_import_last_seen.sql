-- When an entry of the staged lighthouse lists was last read from its source (a list file, the NGA
-- service, OpenStreetMap). A newer edition of a list no longer contains lights that were removed;
-- their last_seen_at stays behind the others', so the admin tool can show them ("not seen since").
-- NULL = staged before this column existed.
ALTER TABLE lighthouse_import
    ADD COLUMN last_seen_at DATETIME NULL DEFAULT NULL AFTER imported_at,
    ADD KEY idx_lighthouse_import_last_seen (source, last_seen_at);
