-- Garmin watch key, kept encrypted (AES-256-GCM, key derived from JWT_SECRET,
-- see WatchKeyVault) next to its hash: YTAN builds the watch's settings file
-- (.SET, which contains the key) and keeps it available for download until a
-- new key is created. The hash stays what the device endpoint looks the key
-- up by. NULL for keys created before this column existed - the settings
-- file is available again after the next "create key".
ALTER TABLE watch_link ADD COLUMN token_enc TEXT NULL AFTER token_hash;
