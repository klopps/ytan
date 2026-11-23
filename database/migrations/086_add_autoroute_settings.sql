-- Autorouting (AutorouteController): the wanted minimum distance to the shore.
-- app_settings: the default for everybody (set on /admin/settings, 0 = keep
-- off the land only). user: the user's own value (NULL = the default above)
-- and whether the distance rule is switched on (remembered from the dialog).
ALTER TABLE app_settings
    ADD COLUMN autoroute_shore_m SMALLINT UNSIGNED NOT NULL DEFAULT 100;

ALTER TABLE user
    ADD COLUMN autoroute_shore_m SMALLINT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN autoroute_shore_enabled TINYINT(1) NOT NULL DEFAULT 1;
