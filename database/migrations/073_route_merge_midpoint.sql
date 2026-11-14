-- Merging two routes (RouteMergeController): when the two joined end points
-- are closer than this many meters, both are replaced by one point halfway
-- between them; farther apart, a connecting segment is added instead.
-- 0 = only exactly identical points are combined. Set on /admin/settings.
ALTER TABLE app_settings
    ADD COLUMN route_merge_midpoint_m SMALLINT UNSIGNED NOT NULL DEFAULT 10;
