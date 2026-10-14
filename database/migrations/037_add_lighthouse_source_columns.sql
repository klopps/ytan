-- Source of every staged list entry and of every imported lighthouse.
-- source_ref: the entry's id within its source (an OSM element "node/123456");
-- empty for the PDF lists, whose entries are identified by number + position.
ALTER TABLE lighthouse_import
    ADD COLUMN source_ref VARCHAR(60) NOT NULL DEFAULT '' AFTER source_key,
    ADD CONSTRAINT fk_lighthouse_import_source FOREIGN KEY (source) REFERENCES data_source (code);

-- NULL = drawn by hand. The column names its character set and collation: a
-- foreign key needs the same ones on both sides, and an older `lighthouse` table
-- may still be utf8mb3 (production was), unlike data_source.
ALTER TABLE lighthouse
    ADD COLUMN data_source VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER imported_at,
    ADD COLUMN source_ref VARCHAR(60) NULL DEFAULT NULL AFTER data_source,
    ADD CONSTRAINT fk_lighthouse_data_source FOREIGN KEY (data_source) REFERENCES data_source (code) ON DELETE SET NULL;

-- Lighthouses imported before these columns existed: take the source from the staging table.
UPDATE lighthouse l JOIN lighthouse_import li ON li.poi_id = l.poi_id
SET l.data_source = li.source
WHERE l.data_source IS NULL;
