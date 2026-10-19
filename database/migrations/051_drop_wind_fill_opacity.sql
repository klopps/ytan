-- The wind layer's colour-fill opacity is a per-user setting now (settings cookie, Preferences);
-- the site-wide value from migration 048 is gone.
ALTER TABLE app_settings DROP COLUMN wind_fill_opacity;
