-- Smoothed route lines (smoothRoutePoints(), helper.js): the curve may bulge
-- at most this share (in percent) of the shortest segment involved away from
-- the straight line - at least ROUTE_SMOOTHING_MAX_DEVIATION_METERS (15 m).
-- 0 = the fixed 15 m cap of before 2026-11-19. Set on /admin/settings.
ALTER TABLE app_settings
    ADD COLUMN route_smoothing_share_pct TINYINT UNSIGNED NOT NULL DEFAULT 10;
