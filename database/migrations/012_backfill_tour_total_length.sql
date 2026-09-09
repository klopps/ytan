UPDATE tour
SET total_length = (
    SELECT COALESCE(SUM(route.length), 0)
    FROM tour_route
    JOIN route ON route.id = tour_route.route_id
    WHERE tour_route.tour_id = tour.id
);
