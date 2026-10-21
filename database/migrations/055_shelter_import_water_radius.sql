-- Search radius (metres) the water check used for a staged shelter. It tells
-- an entry checked with "no water within R" apart from one that was never
-- searched that far: raising the radius in the admin tool checks the entries
-- again whose water_radius is smaller. NULL = not checked or radius unknown.
ALTER TABLE shelter_import
    ADD COLUMN water_radius INT UNSIGNED NULL AFTER water_distance;

-- Everything checked so far used the default radius of 500 m.
UPDATE shelter_import SET water_radius = 500 WHERE water = '';
