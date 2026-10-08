-- GPX 1.1 route export (todo.md "Routen-Export als GPX 1.1 Datei"):
-- export_routes_own = export one's own routes, export_routes_public =
-- export every public route; app_settings.gpx_export_public
-- lets everyone (even signed out) export whatever they can see.
ALTER TABLE user
    ADD COLUMN export_routes_own TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN export_routes_public TINYINT(1) UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE app_settings
    ADD COLUMN gpx_export_public TINYINT(1) UNSIGNED NOT NULL DEFAULT 0;
