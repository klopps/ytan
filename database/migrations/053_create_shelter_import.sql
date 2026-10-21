-- Staging table of the shelter import (admin tool "Shelter import"): shelters
-- and huts from free sources (OpenStreetMap, Naturvardsverket's open data),
-- waiting to be filtered, selected and turned into POIs of type 10
-- (Unterstand / Shelter). water says which kind of water lies within 500 m:
-- NULL = not checked yet, '' = checked, none near, otherwise a comma list of
-- sea, river, lake. Only entries with water near are imported. poi_id is set
-- once an entry has been imported (cleared again when that POI is deleted).
CREATE TABLE shelter_import (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source VARCHAR(10) NOT NULL,
    source_ref VARCHAR(40) NOT NULL,
    name VARCHAR(200) NOT NULL DEFAULT '',
    kind VARCHAR(10) NOT NULL DEFAULT 'shelter',
    country CHAR(2) NOT NULL DEFAULT '',
    water VARCHAR(30) NULL,
    description VARCHAR(1000) NOT NULL DEFAULT '',
    website VARCHAR(500) NOT NULL DEFAULT '',
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    poi_id BIGINT UNSIGNED NULL,
    imported_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shelter_import_ref (source, source_ref),
    KEY idx_shelter_import_country (country),
    KEY idx_shelter_import_water (water),
    KEY idx_shelter_import_position (latitude, longitude),
    CONSTRAINT fk_shelter_import_poi FOREIGN KEY (poi_id) REFERENCES poi (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
