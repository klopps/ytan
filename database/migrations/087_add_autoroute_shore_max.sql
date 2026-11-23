-- Autorouting (AutorouteController): the wanted LARGEST distance to the shore
-- (soft: the way is only made more expensive the farther it is). app_settings:
-- the default for everybody (0 = no limit); user: the user's own value
-- (NULL = the default).
ALTER TABLE app_settings
    ADD COLUMN autoroute_shore_max_m SMALLINT UNSIGNED NOT NULL DEFAULT 1000;

ALTER TABLE user
    ADD COLUMN autoroute_shore_max_m SMALLINT UNSIGNED NULL DEFAULT NULL;
