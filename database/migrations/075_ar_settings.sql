-- AR view (ar-view.js): the admin's default and upper limit for the radius
-- (meters) and for the number of POIs shown at once in the camera picture.
-- Every user picks their own values below the limits (settings cookie);
-- without one the default applies. Set on /admin/settings.
ALTER TABLE app_settings
    ADD COLUMN ar_radius_m INT UNSIGNED NOT NULL DEFAULT 2000,
    ADD COLUMN ar_radius_max_m INT UNSIGNED NOT NULL DEFAULT 10000,
    ADD COLUMN ar_max_pois SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    ADD COLUMN ar_max_pois_limit SMALLINT UNSIGNED NOT NULL DEFAULT 30;
