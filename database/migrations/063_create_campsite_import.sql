-- Staging table of the campsite import (admin tool "Campingplatz-Import"):
-- campsites from OpenStreetMap (tourism=camp_site), one country per load,
-- waiting to be filtered, selected and turned into POIs. category decides the
-- POI type: 'campsite' = 11 (Campingplatz kommerziell), 'basic' = 1
-- (Zeltplatz; camp_site=basic or backcountry=yes). tents/caravans/cabins: 1 =
-- yes, 0 = no, NULL = not tagged. sea_m/river_m/lake_m are the distances to
-- water from the local water index (NULL = farther than 100 km or outside its
-- countries), water_checked_at NULL = not computed. poi_id is set once an
-- entry has been imported (cleared again when that POI is deleted).
CREATE TABLE campsite_import (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source VARCHAR(10) NOT NULL,
    source_ref VARCHAR(40) NOT NULL,
    name VARCHAR(200) NOT NULL DEFAULT '',
    category VARCHAR(10) NOT NULL DEFAULT 'campsite',
    country CHAR(2) NOT NULL DEFAULT '',
    place VARCHAR(120) NOT NULL DEFAULT '',
    description VARCHAR(1000) NOT NULL DEFAULT '',
    website VARCHAR(500) NOT NULL DEFAULT '',
    tents TINYINT NULL,
    caravans TINYINT NULL,
    cabins TINYINT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    sea_m INT UNSIGNED NULL,
    river_m INT UNSIGNED NULL,
    lake_m INT UNSIGNED NULL,
    water_checked_at DATETIME NULL,
    poi_id BIGINT UNSIGNED NULL,
    imported_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campsite_import_ref (source, source_ref),
    KEY idx_campsite_import_country (country),
    KEY idx_campsite_import_position (latitude, longitude),
    KEY idx_campsite_import_sea (sea_m),
    KEY idx_campsite_import_river (river_m),
    KEY idx_campsite_import_lake (lake_m),
    CONSTRAINT fk_campsite_import_poi FOREIGN KEY (poi_id) REFERENCES poi (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
