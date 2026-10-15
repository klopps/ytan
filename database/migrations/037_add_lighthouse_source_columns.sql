-- Source of every staged list entry and of every imported lighthouse.
-- source_ref: the entry's id within its source (an OSM element "node/123456");
-- empty for the PDF lists, whose entries are identified by number + position.
--
-- Every step first checks whether it was applied already: MySQL/MariaDB DDL is not
-- transactional, so an earlier run that stopped half way (production did, at the
-- foreign key on `lighthouse`, see below) left some of it in place and bin/migrate.php
-- runs the whole file again. Plain SQL only (prepared statements built from
-- information_schema) - no "ADD COLUMN IF NOT EXISTS", which MySQL lacks.

-- lighthouse_import.source_ref
SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE lighthouse_import ADD COLUMN source_ref VARCHAR(60) NOT NULL DEFAULT '''' AFTER source_key',
    'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lighthouse_import' AND COLUMN_NAME = 'source_ref');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lighthouse_import.source -> data_source
SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE lighthouse_import ADD CONSTRAINT fk_lighthouse_import_source FOREIGN KEY (source) REFERENCES data_source (code)',
    'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lighthouse_import' AND CONSTRAINT_NAME = 'fk_lighthouse_import_source');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- lighthouse.data_source / lighthouse.source_ref. NULL = drawn by hand. The column
-- names its character set and collation: a foreign key needs the same ones on both
-- sides, and an older `lighthouse` table may still be utf8mb3 (production was),
-- unlike data_source.
SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE lighthouse ADD COLUMN data_source VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER imported_at',
    'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lighthouse' AND COLUMN_NAME = 'data_source');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE lighthouse ADD COLUMN source_ref VARCHAR(60) NULL DEFAULT NULL AFTER data_source',
    'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lighthouse' AND COLUMN_NAME = 'source_ref');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE lighthouse ADD CONSTRAINT fk_lighthouse_data_source FOREIGN KEY (data_source) REFERENCES data_source (code) ON DELETE SET NULL',
    'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lighthouse' AND CONSTRAINT_NAME = 'fk_lighthouse_data_source');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Lighthouses imported before these columns existed: take the source from the staging table.
UPDATE lighthouse l JOIN lighthouse_import li ON li.poi_id = l.poi_id
SET l.data_source = li.source
WHERE l.data_source IS NULL;
