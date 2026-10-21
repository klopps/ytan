-- Shelter import: the distances to the three kinds of water come from the
-- local water index now (WaterDistanceService) and are computed while a list
-- is loaded - the Overpass water check and its columns are gone.
-- water_checked_at NULL = not computed yet (no index at load time).
ALTER TABLE shelter_import
    DROP INDEX idx_shelter_import_water,
    DROP INDEX idx_shelter_import_distance,
    DROP COLUMN water,
    DROP COLUMN water_distance,
    DROP COLUMN water_radius,
    ADD COLUMN sea_m INT UNSIGNED NULL AFTER country,
    ADD COLUMN river_m INT UNSIGNED NULL AFTER sea_m,
    ADD COLUMN lake_m INT UNSIGNED NULL AFTER river_m,
    ADD COLUMN water_checked_at DATETIME NULL AFTER lake_m,
    ADD KEY idx_shelter_import_sea (sea_m),
    ADD KEY idx_shelter_import_river (river_m),
    ADD KEY idx_shelter_import_lake (lake_m);
