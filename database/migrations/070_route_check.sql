-- Routencheck (routencheck.md): AI assessment of a route for one day.
--
-- user.ai_use        = may use the Routencheck (every request costs money at
--                      Anthropic); admins always may.
-- user.paddle_level  = the user's experience level, scales the traffic-light
--                      thresholds (BriefingRules: beginner/experienced/expert).
-- app_settings.ai_*  = the whole feature on/off, requests per user and day,
--                      how long a generated text is reused (hours, 0 = never).
-- app_settings.briefing_rules = the traffic-light thresholds as JSON
--                      (NULL = BriefingRules::defaults()).
ALTER TABLE user
    ADD COLUMN ai_use TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN paddle_level VARCHAR(16) NOT NULL DEFAULT 'experienced';

ALTER TABLE app_settings
    ADD COLUMN ai_enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN ai_daily_limit INT UNSIGNED NOT NULL DEFAULT 20,
    ADD COLUMN ai_cache_hours INT UNSIGNED NOT NULL DEFAULT 3,
    ADD COLUMN briefing_rules TEXT NULL;

-- One row per Routencheck request (also cache hits and failures): the admin
-- page's usage statistics and the daily limit are counted from it. The user
-- name is kept as a snapshot so a deleted user's usage stays readable.
CREATE TABLE ai_usage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    username_snapshot VARCHAR(255) NOT NULL DEFAULT '',
    route_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    model VARCHAR(64) NOT NULL DEFAULT '',
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cache_read_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cache_write_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cache_hit TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'ok',
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_ai_usage_created (created_at),
    KEY idx_ai_usage_user_created (user_id, created_at),
    CONSTRAINT FK_ai_usage_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL,
    CONSTRAINT FK_ai_usage_route FOREIGN KEY (route_id) REFERENCES route (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
