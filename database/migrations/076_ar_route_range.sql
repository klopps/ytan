-- AR route mode (ar-view.js): how far ahead (meters along the route) the
-- active route is drawn into the camera picture - the admin's default and
-- upper limit; every user picks their own value below the limit (settings
-- cookie). Set on /admin/settings next to the other AR values (migration 075).
ALTER TABLE app_settings
    ADD COLUMN ar_route_range_m INT UNSIGNED NOT NULL DEFAULT 3000,
    ADD COLUMN ar_route_range_max_m INT UNSIGNED NOT NULL DEFAULT 10000;
