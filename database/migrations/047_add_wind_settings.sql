-- Wind overlay (tools/wind/, /admin/radar card "Wind"): the job's settings.
-- The regions are the rain radar's. Defaults = WindConfig::defaults().
ALTER TABLE app_settings
    ADD COLUMN wind_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN wind_horizon_hours SMALLINT NOT NULL DEFAULT 48,
    ADD COLUMN wind_run_interval_hours TINYINT NOT NULL DEFAULT 3,
    ADD COLUMN wind_gusts TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN wind_fine TINYINT(1) NOT NULL DEFAULT 1;
