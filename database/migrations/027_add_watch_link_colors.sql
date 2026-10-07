-- Garmin watch data field (watch/): the user's own text colors for the data
-- field, separately for a dark and a light watch background (JSON, see
-- WatchColors). NULL = the built-in defaults.
ALTER TABLE watch_link ADD COLUMN colors TEXT NULL AFTER unit;
