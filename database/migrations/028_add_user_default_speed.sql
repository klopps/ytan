-- Average paddling speed (km/h) a user can set in their profile: the Garmin
-- watch data field (watch/) uses it for the ETA until it has measured
-- speeds of its own. NULL = not set.
ALTER TABLE user ADD COLUMN default_speed_kmh DECIMAL(4,1) NULL;
