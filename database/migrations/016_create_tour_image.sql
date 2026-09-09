CREATE TABLE tour_image (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tour_id BIGINT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(50) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY IDX_tour_image_tour (tour_id),
    CONSTRAINT FK_tour_image_tour FOREIGN KEY (tour_id) REFERENCES tour (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
