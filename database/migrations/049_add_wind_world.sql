-- ICON global as the wind layer's fallback outside ICON-EU's coverage (tools/wind/, /admin/wind).
-- Default = WindConfig::defaults().
ALTER TABLE app_settings
    ADD COLUMN wind_world TINYINT(1) NOT NULL DEFAULT 1;
