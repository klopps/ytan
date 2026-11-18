-- Live map updates (todo.md "Automatische Update bei Veränderungen"): open
-- clients poll GET /api/v1/map/changes?since=... and apply what changed.
--
-- changed_at is bumped by the database on EVERY write to the row (ON UPDATE),
-- whatever code path makes it - unlike updated_at, which only the repositories'
-- own update() sets and some bulk updates deliberately keep (optimistic
-- locking compares it). Milliseconds, so two saves within one second differ.
ALTER TABLE poi
    ADD COLUMN changed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    ADD INDEX idx_poi_changed_at (changed_at);

ALTER TABLE route
    ADD COLUMN changed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    ADD INDEX idx_route_changed_at (changed_at);

ALTER TABLE area
    ADD COLUMN changed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    ADD INDEX idx_area_changed_at (changed_at);

-- Deleted rows leave no changed_at behind - one tombstone per deletion,
-- written by PoiRepository/RouteRepository/AreaRepository::delete(), read by
-- the change feed; each new tombstone also deletes those older than a day
-- (MapChangeRepository::recordDeletion()).
CREATE TABLE map_deletion (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity VARCHAR(8) NOT NULL,
    entity_id INT NOT NULL,
    deleted_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_map_deletion_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
