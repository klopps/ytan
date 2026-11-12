-- Where each scheduled job runs (tools/ytan_jobs.py JOBS: radar, wind): on
-- this server (default) or on an external worker (the YTAN worker add-on on a
-- Home Assistant system, ha-addon/ytan-worker/). JSON list of the job names
-- that run externally; handed to both sides in storage/radar/config.json
-- ("external_jobs", JobPlacement / RadarConfig::writeJobConfig()).
ALTER TABLE app_settings
    ADD COLUMN external_jobs VARCHAR(255) NOT NULL DEFAULT '[]';
