-- Hand-made corrections of the water index (admin page /admin/water,
-- WaterCorrectionRepository). Geometric operations, not segment references,
-- so they survive a rebuild of the index: delete/flip = polygon + kinds,
-- add = a line of one kind. After every change WaterCorrectionFile writes them
-- to storage/water/water-corrections.json, which WaterDistanceService applies
-- while reading the index. username = snapshot (the user may be deleted).
CREATE TABLE water_correction (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type ENUM('delete', 'flip', 'add') NOT NULL,
    kinds VARCHAR(40) NOT NULL,
    points MEDIUMTEXT NOT NULL,
    note VARCHAR(500) NOT NULL DEFAULT '',
    user_id BIGINT UNSIGNED NULL,
    username VARCHAR(100) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT FK_water_correction_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
