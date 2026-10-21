-- Distance in metres to the nearest water of a staged shelter (shelter import),
-- at most 500. NULL = not checked yet or no water within 500 m (told apart by
-- shelter_import.water: NULL / ''). The import filters on it ("at most N m").
ALTER TABLE shelter_import
    ADD COLUMN water_distance SMALLINT UNSIGNED NULL AFTER water,
    ADD KEY idx_shelter_import_distance (water_distance);

-- Entries checked before this column existed only know the kinds of water:
-- check them again so they get a distance.
UPDATE shelter_import SET water = NULL WHERE water IS NOT NULL AND poi_id IS NULL;
