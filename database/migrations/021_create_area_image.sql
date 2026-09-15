CREATE TABLE area_image (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    area_id BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(50) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY IDX_area_image_area (area_id),
    CONSTRAINT FK_area_image_area FOREIGN KEY (area_id) REFERENCES area (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
