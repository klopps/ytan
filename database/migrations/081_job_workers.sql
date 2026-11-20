-- Worker registry and a priority list of workers per job (JobPlacement, /admin/workers).
--
-- workers:     JSON list of the registered workers [{"id": "homeassistant", "label": "...", "note": "..."}];
--              the name is the worker's unique identity (--worker-id / WORKER_ID / add-on option worker_id).
-- job_workers: JSON object {job: [{"id": "<worker or server>", "enabled": true}, ...]} in priority order;
--              "server" = this server's own cron. NULL = every job runs on the server.
--
-- Taken over from external_jobs (the flat list of jobs on the one Home Assistant worker): an external
-- job becomes [homeassistant, server (off)], every other job [server]. external_jobs stays as a column
-- (rollback) but is no longer read.
ALTER TABLE app_settings
    ADD COLUMN workers TEXT NULL,
    ADD COLUMN job_workers TEXT NULL;

UPDATE app_settings SET
    workers = '[{"id":"homeassistant","label":"Home Assistant","note":""}]',
    job_workers = CONCAT(
        '{"radar":',
        IF(JSON_VALID(external_jobs) AND JSON_CONTAINS(external_jobs, '"radar"'),
           '[{"id":"homeassistant","enabled":true},{"id":"server","enabled":false}]',
           '[{"id":"server","enabled":true}]'),
        ',"wind":',
        IF(JSON_VALID(external_jobs) AND JSON_CONTAINS(external_jobs, '"wind"'),
           '[{"id":"homeassistant","enabled":true},{"id":"server","enabled":false}]',
           '[{"id":"server","enabled":true}]'),
        '}');
