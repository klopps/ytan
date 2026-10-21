-- Distance to water for all POI types except landing sites (2), danger
-- spots (6), portages (9), lights (14) and fishing (16) - those lie at or
-- in the water anyway.
ALTER TABLE app_settings ALTER COLUMN water_distance_poitypes SET DEFAULT '[0,1,3,4,5,7,8,10,11,12,13,15]';
UPDATE app_settings SET water_distance_poitypes = '[0,1,3,4,5,7,8,10,11,12,13,15]';
