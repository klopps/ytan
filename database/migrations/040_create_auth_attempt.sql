-- Brute-force protection for POST /auth/login and /auth/forgot-password
-- (security audit 2026-10-16, RequestThrottle): one row per failed login /
-- reset request, counted per identifier and per IP address within a time
-- window. Rows older than a day are deleted while recording new ones.
CREATE TABLE auth_attempt (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(16) NOT NULL,
    identifier VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_auth_attempt_identifier (kind, identifier, created_at),
    KEY idx_auth_attempt_ip (kind, ip, created_at),
    KEY idx_auth_attempt_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
