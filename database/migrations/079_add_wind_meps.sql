-- MET Norway MEPS (2.5 km) as an extra fine source of the wind layer (tools/wind/, /admin/wind).
-- Default = WindConfig::defaults().
ALTER TABLE app_settings
    ADD COLUMN wind_meps TINYINT(1) NOT NULL DEFAULT 1;
