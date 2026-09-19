CREATE TABLE weather_forecast (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lat DECIMAL(6,2) NOT NULL,
    lng DECIMAL(6,2) NOT NULL,
    region_code VARCHAR(20) NOT NULL,
    source VARCHAR(50) NOT NULL,
    model VARCHAR(100) NULL,
    generated_at DATETIME NULL,
    fetched_at DATETIME NOT NULL,
    payload JSON NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY UK_weather_forecast_coords (lat, lng),
    KEY IX_weather_forecast_fetched_at (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
