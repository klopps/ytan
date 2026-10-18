<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ApiException;

/**
 * Starts the rain radar job (tools/radar/radar_job.py) right away for the
 * "update now" button on /admin/radar, in the background, with --force (the
 * fetch interval doesn't apply to a manual start).
 *
 * Also starts the wind job (tools/wind/wind_job.py, `new RadarJobRunner($root, 'wind')`):
 * same lock-file check on storage/wind/.lock, log storage/logs/wind.log, error
 * codes wind.job_*, and the same Python environment (tools/radar/.venv).
 *
 * "Is a run in progress?" is answered by the job's own lock file
 * storage/radar/.lock: radar_job.py holds an exclusive lock on it for the
 * whole run (fcntl.flock on Linux, which PHP's flock() shares). If two
 * starts race, the second job finds the lock taken and ends at once.
 */
final class RadarJobRunner
{
    /** @param string $job 'radar' or 'wind': tools/{job}/{job}_job.py, storage/{job}/.lock */
    public function __construct(private readonly string $rootDir, private readonly string $job = 'radar')
    {
    }

    public function isRunning(): bool
    {
        $lockFile = $this->rootDir . '/storage/' . $this->job . '/.lock';
        if (!is_file($lockFile)) {
            return false;
        }
        $handle = @fopen($lockFile, 'r');
        if ($handle === false) {
            return false;
        }
        try {
            if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                flock($handle, LOCK_UN);

                return false;
            }

            return true;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @throws ApiException 409 radar.job_running, 503 radar.job_not_installed / radar.job_cannot_start
     */
    public function start(): void
    {
        if ($this->isRunning()) {
            throw new ApiException('The ' . $this->job . ' job is running right now.', 409, $this->job . '.job_running');
        }
        $python = $this->python();
        $script = $this->rootDir . '/tools/' . $this->job . '/' . $this->job . '_job.py';
        if ($python === null || !is_file($script)) {
            throw new ApiException('The ' . $this->job . ' job is not installed on this server.', 503, $this->job . '.job_not_installed');
        }
        if (!$this->canExec()) {
            throw new ApiException('This server does not allow starting the ' . $this->job . ' job.', 503, $this->job . '.job_cannot_start');
        }

        $logDir = $this->rootDir . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $log = $logDir . '/' . $this->job . '.log';
        $command = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' --force';

        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows (local development): "start /B" returns at once
            pclose(popen('start "" /B ' . $command . ' >> ' . escapeshellarg($log) . ' 2>&1', 'r'));

            return;
        }
        // Linux: its own session, so the job outlives this request and the PHP worker
        exec('setsid nohup ' . $command . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new ApiException('Starting the ' . $this->job . ' job failed.', 503, $this->job . '.job_cannot_start');
        }
    }

    private function python(): ?string
    {
        foreach (['/tools/radar/.venv/bin/python', '/tools/radar/.venv/Scripts/python.exe'] as $candidate) {
            if (is_file($this->rootDir . $candidate)) {
                return $this->rootDir . $candidate;
            }
        }

        return null;
    }

    private function canExec(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('exec') && function_exists('popen')
            && !in_array('exec', $disabled, true) && !in_array('popen', $disabled, true);
    }
}
