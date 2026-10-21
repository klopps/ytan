-- Distance in metres from a POI to the nearest sea (coastline), river/canal
-- and large lake (at least 10 km²), from the local water index
-- (storage/water/water-index.bin, WaterDistanceService). NULL = farther than
-- 100 km or not computable. Computed on create/move for the POI types listed
-- in app_settings.water_distance_poitypes (JSON list of poitype ids),
-- recomputed by bin/poi-water-distance.php. index_version = the index used.
CREATE TABLE poi_water_distance (
    poi_id BIGINT UNSIGNED NOT NULL,
    sea_m INT UNSIGNED NULL,
    river_m INT UNSIGNED NULL,
    lake_m INT UNSIGNED NULL,
    index_version VARCHAR(20) NULL,
    computed_at DATETIME NOT NULL,
    PRIMARY KEY (poi_id),
    CONSTRAINT fk_poi_water_distance_poi FOREIGN KEY (poi_id) REFERENCES poi (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE app_settings ADD COLUMN water_distance_poitypes VARCHAR(200) NOT NULL DEFAULT '[10]';
