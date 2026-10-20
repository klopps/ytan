-- Staging table of the club import (admin tool "Club import"): canoe/kayak
-- clubs and rowing clubs read from OpenStreetMap (bin/import-clubs-osm.php or
-- the admin tool), waiting to be filtered, selected and turned into POIs of
-- type 13 (Clubs / Institutions). poi_id is set once an entry has been
-- imported (cleared again when that POI is deleted).
CREATE TABLE club_import (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    osm_ref VARCHAR(30) NOT NULL,
    name VARCHAR(200) NOT NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'canoe',
    country CHAR(2) NOT NULL DEFAULT '',
    city VARCHAR(150) NOT NULL DEFAULT '',
    address VARCHAR(300) NOT NULL DEFAULT '',
    website VARCHAR(500) NOT NULL DEFAULT '',
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    poi_id BIGINT UNSIGNED NULL,
    imported_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_club_import_osm_ref (osm_ref),
    KEY idx_club_import_country (country),
    KEY idx_club_import_name (name),
    CONSTRAINT fk_club_import_poi FOREIGN KEY (poi_id) REFERENCES poi (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
