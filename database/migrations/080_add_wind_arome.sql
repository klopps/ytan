-- Météo-France AROME (2.5 km, France) as an extra fine source of the wind layer (tools/wind/, /admin/wind).
-- Needs AROME_API_KEY in .env (or the YTAN worker's add-on option); without a key the job goes on without it.
-- Default = WindConfig::defaults().
ALTER TABLE app_settings
    ADD COLUMN wind_arome TINYINT(1) NOT NULL DEFAULT 1;
