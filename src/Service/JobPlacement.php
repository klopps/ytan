<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ValidationException;

/**
 * Where a scheduled job runs: on this server (tools/ytan_jobs.py from cron) or on
 * one of the registered YTAN workers (tools/ytan_worker.py - the Home Assistant
 * add-on ha-addon/ytan-worker/, the Docker worker docker/ytan-worker/, ...).
 *
 * The admin keeps a REGISTRY of workers (unique names, app_settings.workers) and,
 * per job, a PRIORITY LIST of them plus "server" (app_settings.job_workers,
 * /admin/workers). Both go to storage/radar/config.json (RadarConfig::writeJobConfig()).
 * Who works on a job NOW is decided by activeWorker(), a pure function that the
 * workers and the cron (tools/ytan_jobs.py active_worker(), same rules, same test
 * vectors: tests/fixtures/worker-election.json) compute for themselves: the workers
 * only talk to this server through rsync (rrsync, no PHP runs on contact), so there is
 * no coordinator. The first enabled entry that is alive and able wins; a worker that is
 * working on the job keeps it until it is done.
 *
 * A worker reports itself in storage/workers/{id}/worker-status.json after every tick
 * (workers()); it connects only TO this server (SSH key restricted to rsync below
 * storage/ by rrsync). "Update now" for a job that is not run by the server leaves
 * storage/{job}/run-request, which the active worker picks up at its next tick.
 */
final class JobPlacement
{
    /** The jobs of tools/ytan_jobs.py JOBS that may run externally. */
    public const JOBS = ['radar', 'wind'];

    /** The entry of this server's own cron in a job's list. */
    public const SERVER = 'server';

    /** A worker that has not reported for this long counts as away. */
    public const WORKER_STALE_SECONDS = 15 * 60;

    /** While the worker reports a running job, it only counts as away after this long. */
    public const WORKER_RUNNING_STALE_SECONDS = 2 * 3600;

    public const MAX_LIST = 8;
    public const MAX_WORKERS = 20;

    /** A worker's name: 1-32 characters, lower case letters, digits and hyphens, not starting with a hyphen. */
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && $id !== self::SERVER && preg_match(self::ID_PATTERN, $id) === 1;
    }

    /**
     * Known job names only, each once, in JOBS order.
     *
     * @param array<mixed> $jobs
     * @return list<string>
     */
    public static function normalize(array $jobs): array
    {
        return array_values(array_filter(self::JOBS, fn (string $job) => in_array($job, $jobs, true)));
    }

    // -- registry -------------------------------------------------------------

    /**
     * The registered workers: unique names, label and note trimmed. $strict = a bad entry is a
     * 422 (the admin's input); otherwise it is skipped (what is stored is read leniently).
     *
     * @return list<array{id: string, label: string, note: string}>
     */
    public static function normalizeRegistry(mixed $input, bool $strict = true): array
    {
        if (!is_array($input)) {
            if ($strict) {
                throw new ValidationException('workers must be a list.', 'worker.registry_invalid');
            }

            return [];
        }
        $out = [];
        foreach ($input as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (!self::isValidId($id)) {
                if ($strict) {
                    throw new ValidationException('A worker name is 1-32 characters: a-z, 0-9 and "-" (not "server").', 'worker.invalid_name');
                }
                continue;
            }
            if (isset($out[$id])) {
                if ($strict) {
                    throw new ValidationException('The worker name "' . $id . '" is used twice.', 'worker.name_taken');
                }
                continue;
            }
            $out[$id] = [
                'id' => $id,
                'label' => mb_substr(trim((string) ($entry['label'] ?? '')), 0, 60),
                'note' => mb_substr(trim((string) ($entry['note'] ?? '')), 0, 200),
            ];
        }
        if (count($out) > self::MAX_WORKERS) {
            if ($strict) {
                throw new ValidationException('At most ' . self::MAX_WORKERS . ' workers.', 'worker.too_many');
            }
            $out = array_slice($out, 0, self::MAX_WORKERS, true);
        }

        return array_values($out);
    }

    // -- priority lists ---------------------------------------------------------

    /** @return array<string, list<array{id: string, enabled: bool}>> every job on this server */
    public static function defaultLists(): array
    {
        $lists = [];
        foreach (self::JOBS as $job) {
            $lists[$job] = [['id' => self::SERVER, 'enabled' => true]];
        }

        return $lists;
    }

    /**
     * Per job its priority list: only known jobs, entries are "server" or a registered worker,
     * each once, at most MAX_LIST; a job without a list keeps the default (the server).
     *
     * @param list<string> $registered the registered names
     * @return array<string, list<array{id: string, enabled: bool}>>
     */
    public static function normalizeLists(mixed $input, array $registered, bool $strict = true): array
    {
        $lists = self::defaultLists();
        if (!is_array($input)) {
            if ($strict && $input !== null) {
                throw new ValidationException('job_workers must be an object.', 'worker.list_invalid');
            }

            return $lists;
        }
        foreach (self::JOBS as $job) {
            if (!array_key_exists($job, $input)) {
                continue;
            }
            $entries = $input[$job];
            if (!is_array($entries)) {
                if ($strict) {
                    throw new ValidationException('The list of ' . $job . ' must be a list.', 'worker.list_invalid');
                }
                continue;
            }
            $list = [];
            $seen = [];
            foreach ($entries as $entry) {
                $id = is_array($entry) ? ($entry['id'] ?? null) : null;
                $known = $id === self::SERVER || (is_string($id) && in_array($id, $registered, true));
                if (!$known) {
                    if ($strict) {
                        throw new ValidationException('"' . (is_string($id) ? $id : '?') . '" is not a registered worker.', 'worker.unknown');
                    }
                    continue;
                }
                if (isset($seen[$id])) {
                    if ($strict) {
                        throw new ValidationException('"' . $id . '" is in the list of ' . $job . ' twice.', 'worker.list_invalid');
                    }
                    continue;
                }
                $seen[$id] = true;
                $list[] = ['id' => $id, 'enabled' => ($entry['enabled'] ?? true) === true];
            }
            if (count($list) > self::MAX_LIST) {
                if ($strict) {
                    throw new ValidationException('At most ' . self::MAX_LIST . ' entries per job.', 'worker.list_invalid');
                }
                $list = array_slice($list, 0, self::MAX_LIST);
            }
            // an empty list would leave the job to nobody: that is "every job on the server" (the default)
            $lists[$job] = $list === [] ? $lists[$job] : $list;
        }

        return $lists;
    }

    /**
     * What storage/radar/config.json's old key "external_jobs" says (kept for workers that predate
     * the lists): the jobs whose first enabled entry is not this server.
     *
     * @param array<string, list<array{id: string, enabled: bool}>> $lists
     * @return list<string>
     */
    public static function externalFromLists(array $lists): array
    {
        $jobs = [];
        foreach (self::JOBS as $job) {
            foreach ($lists[$job] ?? [] as $entry) {
                if ($entry['enabled']) {
                    if ($entry['id'] !== self::SERVER) {
                        $jobs[] = $job;
                    }
                    break;
                }
            }
        }

        return $jobs;
    }

    /**
     * The old "run on the worker" switch of /admin/radar and /admin/wind on top of the lists:
     * external = the first registered worker (or the first one already in the list) goes to the
     * front and is enabled; local = the server goes to the front and is enabled.
     *
     * @param array<string, list<array{id: string, enabled: bool}>> $lists
     * @param list<string> $registered
     * @return array<string, list<array{id: string, enabled: bool}>>
     */
    public static function withExternal(array $lists, string $job, bool $external, array $registered): array
    {
        $list = $lists[$job] ?? self::defaultLists()[$job];
        if ($external) {
            $head = null;
            foreach ($list as $entry) {
                if ($entry['id'] !== self::SERVER) {
                    $head = $entry['id'];
                    break;
                }
            }
            $head ??= $registered[0] ?? null;
            if ($head === null) {
                throw new ValidationException('Register a worker first (Admin > Worker).', 'worker.none_registered');
            }
        } else {
            $head = self::SERVER;
        }
        $rest = array_values(array_filter($list, fn ($e) => $e['id'] !== $head));
        if ($external) {
            // "the server no longer computes this job itself": it stays in the list, switched off (the editor can turn it on as the fallback)
            $rest = array_map(fn ($e) => $e['id'] === self::SERVER ? ['id' => self::SERVER, 'enabled' => false] : $e, $rest);
        }
        $lists[$job] = array_merge([['id' => $head, 'enabled' => true]], $rest);
        if ($external && !in_array(self::SERVER, array_column($lists[$job], 'id'), true)) {
            $lists[$job][] = ['id' => self::SERVER, 'enabled' => false];
        }

        return $lists;
    }

    // -- who works on a job now --------------------------------------------------

    /**
     * Whether a heartbeat is fresh: its tick is younger than WORKER_STALE_SECONDS, or - while it reports
     * a running job (a wind run takes ~15 min) - younger than WORKER_RUNNING_STALE_SECONDS.
     *
     * @param array<string, mixed>|null $heartbeat
     */
    public static function heartbeatAlive(?array $heartbeat, int $now): bool
    {
        if ($heartbeat === null) {
            return false;
        }
        $at = strtotime((string) ($heartbeat['tick'] ?? ''));
        if ($at === false) {
            return false;
        }
        $limit = is_array($heartbeat['running'] ?? null) ? self::WORKER_RUNNING_STALE_SECONDS : self::WORKER_STALE_SECONDS;

        return $at >= $now - $limit;
    }

    /**
     * The one who works on $job now, or null (nobody alive and able). The same rules in
     * tools/ytan_jobs.py active_worker(); tests/fixtures/worker-election.json pins both.
     *
     * - only enabled entries count; a worker is a candidate when its heartbeat is fresh and it reports
     *   $job in "capabilities"; the server is a candidate when $serverTick (storage/jobs-heartbeat.json:
     *   the cron ticks) is fresh;
     * - one that is running $job right now keeps it (first of them in list order), whatever its place;
     * - else the first candidate.
     * A name used by two systems does not change the election: the one that was there first goes on
     * working, the second one stops itself and leaves conflict.json (workers()) for the admin to see.
     *
     * @param list<array{id: string, enabled: bool}> $list
     * @param array<string, array<string, mixed>|null> $heartbeats worker id => its worker-status.json
     */
    public static function activeWorker(string $job, array $list, array $heartbeats, ?string $serverTick, int $now): ?string
    {
        $candidates = [];
        $busy = [];
        foreach ($list as $entry) {
            if (empty($entry['enabled'])) {
                continue;
            }
            $id = (string) $entry['id'];
            if ($id === self::SERVER) {
                if ($serverTick !== null && self::heartbeatAlive(['tick' => $serverTick], $now)) {
                    $candidates[] = $id;
                }
                continue;
            }
            $heartbeat = $heartbeats[$id] ?? null;
            if (!self::heartbeatAlive($heartbeat, $now)
                || !in_array($job, is_array($heartbeat['capabilities'] ?? null) ? $heartbeat['capabilities'] : [], true)) {
                continue;
            }
            $candidates[] = $id;
            if (is_array($heartbeat['running'] ?? null) && ($heartbeat['running']['job'] ?? null) === $job) {
                $busy[] = $id;
            }
        }

        return $busy[0] ?? $candidates[0] ?? null;
    }

    // -- what is on disk ---------------------------------------------------------

    /**
     * storage/workers/ and storage/logs/worker/ exist before the first worker connects (rsync creates only
     * the last path component of a destination: workers/{id}/, logs/worker/{id}/).
     */
    public static function ensureWorkersDir(string $storageDir): void
    {
        foreach (['/workers', '/logs/worker'] as $sub) {
            if (!is_dir($storageDir . $sub)) {
                @mkdir($storageDir . $sub, 0775, true);
            }
        }
    }

    /**
     * Every system that has reported, from storage/workers/{id}/: `status` (its worker-status.json plus
     * `stale`, null when it left none) and `conflict` (conflict.json when another system used its name
     * within WORKER_STALE_SECONDS, else null).
     *
     * @return array<string, array{status: array<string, mixed>|null, conflict: array<string, mixed>|null}>
     */
    public static function workers(string $storageDir, ?int $now = null): array
    {
        $now ??= time();
        self::ensureWorkersDir($storageDir);
        $out = [];
        foreach (glob($storageDir . '/workers/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (!self::isValidId($id)) {
                continue;
            }
            $status = self::readJson($dir . '/worker-status.json');
            if ($status !== null) {
                $status['stale'] = !self::heartbeatAlive($status, $now);
            }
            $conflict = self::readJson($dir . '/conflict.json');
            if ($conflict !== null && !self::heartbeatAlive($conflict, $now)) {
                $conflict = null;
            }
            $out[$id] = ['status' => $status, 'conflict' => $conflict];
        }
        ksort($out);

        return $out;
    }

    /** "Reset instance": frees a name (the system behind it was replaced) by removing what it reported. */
    public static function resetWorker(string $storageDir, string $id): void
    {
        if (!self::isValidId($id)) {
            return;
        }
        foreach (['worker-status.json', 'conflict.json'] as $file) {
            @unlink($storageDir . '/workers/' . $id . '/' . $file);
        }
    }

    /** The tick of this server's cron (storage/jobs-heartbeat.json), null before the first one. */
    public static function serverTick(string $storageDir): ?string
    {
        $data = self::readJson($storageDir . '/jobs-heartbeat.json');

        return is_string($data['tick'] ?? null) ? $data['tick'] : null;
    }

    /**
     * The election inputs read from disk: worker id => its worker-status.json (null when it left none).
     *
     * @param array<string, array{status: array<string, mixed>|null, conflict: array<string, mixed>|null}> $workers workers()
     * @return array<string, array<string, mixed>|null>
     */
    public static function heartbeats(array $workers): array
    {
        return array_map(fn ($worker) => $worker['status'], $workers);
    }

    /**
     * Who works on $job now (null: nobody alive and able), from what is on disk.
     *
     * @param array<string, list<array{id: string, enabled: bool}>> $lists
     */
    public static function activeFor(string $storageDir, string $job, array $lists, ?int $now = null): ?string
    {
        $now ??= time();
        $list = $lists[$job] ?? self::defaultLists()[$job];

        return self::activeWorker($job, $list, self::heartbeats(self::workers($storageDir, $now)), self::serverTick($storageDir), $now);
    }

    /**
     * Whether "update now" starts the job on this server: the server is the one who works on it, or nobody
     * is alive and able and the server is on the list (the manual button then falls back to it).
     *
     * @param array<string, list<array{id: string, enabled: bool}>> $lists
     */
    public static function startsLocally(string $storageDir, string $job, array $lists, ?int $now = null): bool
    {
        $active = self::activeFor($storageDir, $job, $lists, $now);
        if ($active !== null) {
            return $active === self::SERVER;
        }
        foreach ($lists[$job] ?? self::defaultLists()[$job] as $entry) {
            if ($entry['enabled'] && $entry['id'] === self::SERVER) {
                return true;
            }
        }

        return false;
    }

    /** "Update now" for a job the server does not run: a marker the active worker sees at its next tick. */
    public static function requestRun(string $storageDir, string $job): void
    {
        $dir = $storageDir . '/' . $job;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (@file_put_contents($dir . '/run-request', date('c')) === false) {
            throw new \RuntimeException('Cannot write ' . $dir . '/run-request');
        }
    }

    /** A pending request is dropped when the job comes back to this server (cron runs it anyway). */
    public static function clearRequest(string $storageDir, string $job): void
    {
        @unlink($storageDir . '/' . $job . '/run-request');
    }

    public static function runRequested(string $storageDir, string $job): bool
    {
        return is_file($storageDir . '/' . $job . '/run-request');
    }

    /**
     * What a worker without an id reported after its last tick (storage/worker-status.json, the file of
     * the add-on before the registry), plus `stale`; null if there is none. Only the transition reads it.
     *
     * @return array<string, mixed>|null
     */
    public static function workerStatus(string $storageDir, ?int $now = null): ?array
    {
        $data = self::readJson($storageDir . '/worker-status.json');
        if ($data === null) {
            return null;
        }
        $data['stale'] = !self::heartbeatAlive($data, $now ?? time());

        return $data;
    }

    /**
     * The placement part of an admin page's payload for one job.
     *
     * @param array<string, list<array{id: string, enabled: bool}>> $lists
     * @return array{active: ?string, list: list<array{id: string, enabled: bool}>, external: bool, run_requested: bool, worker: array<string, mixed>|null}
     */
    public static function payload(string $storageDir, string $job, array $lists, ?int $now = null): array
    {
        $now ??= time();
        $workers = self::workers($storageDir, $now);
        $list = $lists[$job] ?? self::defaultLists()[$job];
        $active = self::activeWorker($job, $list, self::heartbeats($workers), self::serverTick($storageDir), $now);
        // the line under the switch tells about the one who works on the job - or, with nobody alive, about the first of the list
        $shown = $active;
        if ($shown === null) {
            foreach ($list as $entry) {
                if ($entry['enabled'] && $entry['id'] !== self::SERVER) {
                    $shown = $entry['id'];
                    break;
                }
            }
        }

        return [
            'active' => $active,
            'list' => $list,
            'external' => $active !== null && $active !== self::SERVER,
            'run_requested' => self::runRequested($storageDir, $job),
            'worker' => $shown !== null && $shown !== self::SERVER ? ($workers[$shown]['status'] ?? null) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function readJson(string $path): ?array
    {
        $json = @file_get_contents($path);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
