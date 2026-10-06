-- Garmin watch data field (watch/): one link per user - the device token the
-- sideloaded data field authenticates with (only its SHA-256 hash is stored),
-- the route currently "sent to the watch" and the distance unit to show.
-- A separate table rather than user columns, so the token hash never leaks
-- through the many SELECT * FROM user call sites.
CREATE TABLE watch_link (
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NULL,
    route_id BIGINT UNSIGNED NULL,
    unit VARCHAR(10) NOT NULL DEFAULT 'metric',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY UQ_watch_link_token (token_hash),
    KEY IDX_watch_link_route (route_id),
    CONSTRAINT FK_watch_link_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE,
    CONSTRAINT FK_watch_link_route FOREIGN KEY (route_id) REFERENCES route (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
