-- Staging table of the lighthouse list import (admin tool "Lighthouse import"):
-- bin/import-lighthouse-list.php reads an official lighthouse list (PDF) into
-- it, the admin tool filters/selects entries and turns them into POIs.
-- poi_id is set once an entry has been imported (and cleared again when that
-- POI is deleted), so nothing is imported twice.
CREATE TABLE lighthouse_import (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source VARCHAR(10) NOT NULL,
    source_key VARCHAR(190) NOT NULL,
    dk_no VARCHAR(20) NOT NULL DEFAULT '',
    international_id VARCHAR(30) NOT NULL DEFAULT '',
    region VARCHAR(100) NOT NULL DEFAULT '',
    subregion VARCHAR(150) NOT NULL DEFAULT '',
    name VARCHAR(200) NOT NULL DEFAULT '',
    location VARCHAR(300) NOT NULL DEFAULT '',
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    characteristic VARCHAR(50) NOT NULL DEFAULT '',
    other_signals VARCHAR(200) NOT NULL DEFAULT '',
    flame_height VARCHAR(60) NOT NULL DEFAULT '',
    light_range VARCHAR(120) NOT NULL DEFAULT '',
    appearance VARCHAR(300) NOT NULL DEFAULT '',
    remarks TEXT NULL,
    sector_characteristic VARCHAR(500) NULL,
    sector_missing_range TINYINT(1) NOT NULL DEFAULT 0,
    poi_id BIGINT UNSIGNED NULL,
    imported_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lighthouse_import_source_key (source, source_key),
    KEY idx_lighthouse_import_dk_no (dk_no),
    KEY idx_lighthouse_import_international_id (international_id),
    KEY idx_lighthouse_import_region (region),
    KEY idx_lighthouse_import_name (name),
    CONSTRAINT fk_lighthouse_import_poi FOREIGN KEY (poi_id) REFERENCES poi (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
