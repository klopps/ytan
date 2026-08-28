-- Core schema for YTAN, reconstructed from the production PESR schema
-- (poi, route, tour, area, user, windshelter, lighthouse, portage, poitype,
-- tour_route, serviceuser) with FKs, unique/index keys and utf8mb4.

CREATE TABLE `user` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(200) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `firstname` VARCHAR(50) DEFAULT NULL,
  `lastname` VARCHAR(50) DEFAULT NULL,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UK_username` (`username`),
  UNIQUE KEY `UK_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `poitype` (
  `id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(200) DEFAULT NULL,
  `icon` VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `poi` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poitype_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(200) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `public` TINYINT(1) NOT NULL DEFAULT 0,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `url` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_poi_poitype` (`poitype_id`),
  KEY `FK_poi_user` (`user_id`),
  CONSTRAINT `FK_poi_poitype` FOREIGN KEY (`poitype_id`) REFERENCES `poitype` (`id`),
  CONSTRAINT `FK_poi_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE `windshelter` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poi_id` BIGINT UNSIGNED NOT NULL,
  `direction` CHAR(16) NOT NULL DEFAULT '0000000000000000',
  PRIMARY KEY (`id`),
  UNIQUE KEY `UK_poi_id` (`poi_id`),
  CONSTRAINT `FK_windshelter_poi` FOREIGN KEY (`poi_id`) REFERENCES `poi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lighthouse` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poi_id` BIGINT UNSIGNED NOT NULL,
  `characteristic` VARCHAR(50) DEFAULT NULL,
  `sector_characteristic` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_lighthouse_poi` (`poi_id`),
  CONSTRAINT `FK_lighthouse_poi` FOREIGN KEY (`poi_id`) REFERENCES `poi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `portage` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poi_id` BIGINT UNSIGNED NOT NULL,
  `path` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_portage_poi` (`poi_id`),
  CONSTRAINT `FK_portage_poi` FOREIGN KEY (`poi_id`) REFERENCES `poi` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `route` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL DEFAULT '',
  `description` TEXT DEFAULT NULL,
  `length` INT DEFAULT NULL,
  `points` TEXT DEFAULT NULL,
  `public` TINYINT(1) NOT NULL DEFAULT 0,
  `color` VARCHAR(7) NOT NULL DEFAULT '#BF409F',
  PRIMARY KEY (`id`),
  KEY `FK_route_user` (`user_id`),
  CONSTRAINT `FK_route_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tour` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `public` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `FK_tour_user` (`user_id`),
  CONSTRAINT `FK_tour_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Mehrere zusammengehoerige Routen';

CREATE TABLE `tour_route` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tour_id` BIGINT UNSIGNED NOT NULL,
  `route_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_tour_route_tour` (`tour_id`),
  KEY `FK_tour_route_route` (`route_id`),
  CONSTRAINT `FK_tour_route_tour` FOREIGN KEY (`tour_id`) REFERENCES `tour` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_tour_route_route` FOREIGN KEY (`route_id`) REFERENCES `route` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `area` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL DEFAULT '',
  `description` TEXT DEFAULT NULL,
  `points` TEXT DEFAULT NULL,
  `public` TINYINT(1) NOT NULL DEFAULT 0,
  `color` VARCHAR(7) NOT NULL DEFAULT '#00FF30',
  `opacity` DECIMAL(3,2) NOT NULL DEFAULT 0.10,
  `zindex` INT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `FK_area_user` (`user_id`),
  CONSTRAINT `FK_area_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `serviceuser` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `apikey` VARCHAR(200) NOT NULL,
  `allowed_referrers` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UK_apikey` (`apikey`),
  KEY `FK_serviceuser_user` (`user_id`),
  CONSTRAINT `FK_serviceuser_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
