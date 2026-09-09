ALTER TABLE tour_route
    ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER route_id,
    ADD UNIQUE KEY UK_tour_route (tour_id, route_id);
