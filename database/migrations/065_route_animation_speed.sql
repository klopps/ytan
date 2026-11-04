-- Route/tour animation: default playback speed, in screen pixels per second
-- the route moves under the camera at the animation's zoom level (until now a
-- fixed 90 in route-animation.js, too slow). The viewer can still change it
-- during the animation with the -/+ buttons (a factor on top of this value).
ALTER TABLE app_settings
    ADD COLUMN route_animation_speed SMALLINT UNSIGNED NOT NULL DEFAULT 250;
