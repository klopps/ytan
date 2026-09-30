ALTER TABLE app_settings
    ADD COLUMN route_label_font_size_min TINYINT UNSIGNED NOT NULL DEFAULT 8,
    ADD COLUMN route_label_font_size_max TINYINT UNSIGNED NOT NULL DEFAULT 20;
