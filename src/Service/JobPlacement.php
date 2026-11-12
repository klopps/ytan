<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Where a scheduled job runs: on this server (tools/ytan_jobs.py from cron,
 * the default) or on the external YTAN worker - the Home Assistant add-on
 * ha-addon/ytan-worker/, which runs the same tools/ytan_jobs.py with --worker.
 *
 * The admin chooses per job (/admin/radar, /admin/wind); the choice is stored
 * in app_settings.external_jobs and handed to BOTH sides in
 * storage/radar/config.json ("external_jobs", RadarConfig::writeJobConfig()):
 * cron here skips those jobs, the worker runs only those. The worker only
 * connects TO this server (SSH key restricted to rsync below storage/ by
 * rrsync); it fetches config.json, uploads the job's output (manifest last)
 * and after every tick storage/worker-status.json (workerStatus()). "Update
 * now" for an external job leaves storage/{job}/run-request, which the worker
 * picks up at its next tick (requestRun()).
 */
final class JobPlacement
{
    /** The jobs of tools/ytan_jobs.py JOBS that may run externally. */
    public const JOBS = ['radar', 'wind'];

    /** A worker that has not reported for this long counts as away. */
    public const WORKER_STALE_SECONDS = 15 * 60;

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

    /**
     * @param list<string> $external
     * @return list<string>
     */
    public static function with(array $external, string $job, bool $isExternal): array
    {
        $jobs = array_values(array_diff($external, [$job]));
        if ($isExternal) {
            $jobs[] = $job;
        }

        return self::normalize($jobs);
    }

    /** "Update now" for an external job: a marker the worker sees at its next tick. */
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
     * What the worker reported after its last tick (storage/worker-status.json),
     * plus `stale` (no report for WORKER_STALE_SECONDS); null if it never reported.
     *
     * @return array<string, mixed>|null
     */
    public static function workerStatus(string $storageDir, ?int $now = null): ?array
    {
        $json = @file_get_contents($storageDir . '/worker-status.json');
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $at = strtotime((string) ($data['tick'] ?? ''));
        $data['stale'] = $at === false || $at < ($now ?? time()) - self::WORKER_STALE_SECONDS;

        return $data;
    }

    /**
     * The placement part of an admin page's payload for one job.
     *
     * @param list<string> $external
     * @return array{external: bool, run_requested: bool, worker: array<string, mixed>|null}
     */
    public static function payload(string $storageDir, string $job, array $external): array
    {
        return [
            'external' => in_array($job, $external, true),
            'run_requested' => self::runRequested($storageDir, $job),
            'worker' => self::workerStatus($storageDir),
        ];
    }
}
