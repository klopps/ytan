CREATE TABLE poi_image (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    poi_id BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(50) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY IDX_poi_image_poi (poi_id),
    CONSTRAINT FK_poi_image_poi FOREIGN KEY (poi_id) REFERENCES poi (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
