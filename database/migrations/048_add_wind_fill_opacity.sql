-- Wind layer (/admin/wind): opacity of the colour fill in percent (0 = no fill,
-- 100 = opaque). The map reads it as window.YTAN_WIND_FILL_OPACITY.
ALTER TABLE app_settings
    ADD COLUMN wind_fill_opacity TINYINT UNSIGNED NOT NULL DEFAULT 32;
