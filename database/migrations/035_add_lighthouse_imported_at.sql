-- When a lighthouse was created (or last overwritten) by the lighthouse list import;
-- NULL for lighthouses drawn by hand. Independent of the staging table
-- lighthouse_import, which can be emptied or re-read.
ALTER TABLE lighthouse ADD COLUMN imported_at DATETIME NULL DEFAULT NULL AFTER international_id;

-- Lighthouses imported before this column existed: take the time from the staging table.
UPDATE lighthouse l JOIN lighthouse_import li ON li.poi_id = l.poi_id
SET l.imported_at = li.imported_at
WHERE l.imported_at IS NULL AND li.imported_at IS NOT NULL;
