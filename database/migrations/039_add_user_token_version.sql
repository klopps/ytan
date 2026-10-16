-- Revoking JWTs (security audit 2026-10-16): every token carries the user's
-- token_version as claim "tv"; AuthService::authenticate() rejects a token
-- whose "tv" differs from the current value. Raised on a password change/
-- reset/admin-set and on "sign out everywhere", which invalidates every
-- token issued before. Tokens issued before this migration carry no "tv" and
-- count as 0, so they stay valid until the first such event.
ALTER TABLE user
    ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 0;
