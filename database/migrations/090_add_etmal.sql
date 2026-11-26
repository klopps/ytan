-- Etmal (the distance a user paddles per day on a tour, km): the reach lines
-- of the map context menu (WaterReachController). app_settings: the value a
-- NEW user starts with (set on /admin/settings). user: the user's own value -
-- always a valid number (5-60), never NULL; existing users start at 25.
ALTER TABLE app_settings
    ADD COLUMN etmal_km SMALLINT UNSIGNED NOT NULL DEFAULT 25;

ALTER TABLE user
    ADD COLUMN etmal_km SMALLINT UNSIGNED NOT NULL DEFAULT 25;
