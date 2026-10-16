-- user_token.token now holds the SHA-256 (hex) of the token mailed in the
-- invite/reset/email-change link, not the token itself (security audit
-- 2026-10-16, UserRepository::createToken()): a database dump alone no
-- longer gives working links. Converts the existing rows, so links mailed
-- before still work until they expire. Run once - running it again would
-- hash the hashes.
UPDATE user_token SET token = SHA2(token, 256);
