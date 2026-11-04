-- Route/tour animation (todo.md "Animierte Routen- und Tourenpräsentation"):
-- colour and width of the line drawn along the route, the zoom level the
-- camera follows it at, and how long the finished animation stays before
-- the map returns to the view from before (seconds, with decimals).
ALTER TABLE app_settings
    ADD COLUMN route_animation_color CHAR(7) NOT NULL DEFAULT '#FF6A00',
    ADD COLUMN route_animation_line_width TINYINT UNSIGNED NOT NULL DEFAULT 6,
    ADD COLUMN route_animation_zoom DECIMAL(4,1) NOT NULL DEFAULT 15.0,
    ADD COLUMN route_animation_end_delay_s DECIMAL(5,2) NOT NULL DEFAULT 2.00;
