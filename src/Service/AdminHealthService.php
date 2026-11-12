<?php

declare(strict_types=1);

namespace Ytan\Service;

use PDO;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Service\Overpass\OsmImportJob;

/**
 * Problems for the /admin dashboard's "Probleme" card (AdminHealthController,
 * GET /api/v1/admin/health): failed or no longer running scheduled jobs,
 * stale rain radar / wind data, a YTAN worker that stopped reporting, a
 * failed OSM import, cleanup cron jobs that obviously never run, and errors
 * in the web server's / PHP's error log during the last 24 hours.
 *
 * Everything comes from what the jobs already write (storage/{radar,wind}/
 * status.json + manifest.json, storage/worker-status.json, the OSM import's
 * status, storage/logs/*.log modification times) and one COUNT on
 * weather_forecast - nothing is fetched from outside, so the dashboard can
 * call it on every load.
 *
 * An issue = ['severity' => error|warning|info, 'code' => 'radar.stale', 'vars' => [...], 'page' => 'radar'|null];
 * the dashboard translates `code` (admin.health.*) and links `page` (/admin/{page}).
 */
final class AdminHealthService
{
    /** Cron runs tools/ytan_jobs.py every 5 minutes; its log not written for longer = cron is not running. */
    public const CRON_SILENT_SECONDS = 15 * 60;
    /** The radar job writes status.json on every run (also "nothing new", "paused"), the longest fetch interval is 15 min. */
    public const RADAR_SILENT_SECONDS = 20 * 60;
    /** Newest radar observation older than this = warning, older than RADAR_STALE_ERROR_SECONDS = error. */
    public const RADAR_STALE_SECONDS = 30 * 60;
    public const RADAR_STALE_ERROR_SECONDS = 3 * 3600;
    /** The wind job runs once per quarter hour. */
    public const WIND_SILENT_SECONDS = 45 * 60;
    /** Wind data older than the run interval plus this = no new model run arrived (DWD runs every 3 h, ~2 h until complete). */
    public const WIND_STALE_EXTRA_SECONDS = 4 * 3600;
    /** A failed/interrupted OSM import is shown this long after its last status update. */
    public const OSM_SHOWN_SECONDS = 7 * 86400;
    /** bin/cleanup-weather-data.php deletes rows older than 7 days - older ones mean its cron entry is missing. */
    public const WEATHER_ROW_MAX_DAYS = 8;
    /** bin/cleanup-file-caches.php is meant to run daily. */
    public const CLEANUP_SILENT_SECONDS = 2 * 86400;
    /** Error log entries younger than this are reported. */
    public const ERROR_LOG_WINDOW_SECONDS = 86400;
    /** Only the end of an error log is read (it can be huge). */
    public const ERROR_LOG_TAIL_BYTES = 524288;

    public function __construct(
        private readonly string $rootDir,
        private readonly SettingsRepository $settings,
        private readonly PDO $db,
        /** @var list<string> PHP's error_log + the error logs of .env's ADMIN_LOG_FILES (see errorLogFiles()) */
        private readonly array $errorLogs = [],
    ) {
    }

    /** @return list<array{severity: string, code: string, vars: array<string, mixed>, page: ?string}> */
    public function issues(?int $now = null): array
    {
        $now ??= time();
        $storage = $this->rootDir . '/storage';
        $external = $this->settings->externalJobs();
        $issues = [];

        $this->checkCron($storage, $now, $issues);
        $this->checkRadar($storage, $now, $issues);
        $this->checkWind($storage, $now, $issues);
        $this->checkWorker($storage, $external, $now, $issues);
        $this->checkOsmImport($now, $issues);
        $this->checkWeather($now, $issues);
        $this->checkCleanup($storage, $now, $issues);
        $this->checkErrorLogs($now, $issues);

        $order = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($issues, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return $issues;
    }

    private static function add(array &$issues, string $severity, string $code, array $vars = [], ?string $page = null): void
    {
        $issues[] = ['severity' => $severity, 'code' => $code, 'vars' => $vars, 'page' => $page];
    }

    private static function iso(int $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    private static function time(mixed $value): ?int
    {
        $time = is_string($value) && $value !== '' ? strtotime($value) : false;

        return $time === false ? null : $time;
    }

    private static function json(string $file): ?array
    {
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

        return is_array($data) ? $data : null;
    }

    private function checkCron(string $storage, int $now, array &$issues): void
    {
        $log = $storage . '/logs/jobs.log';
        clearstatcache(true, $log);
        $modified = is_file($log) ? (int) filemtime($log) : null;
        if ($modified === null) {
            self::add($issues, 'error', 'cron.never', [], 'logs');
        } elseif ($now - $modified > self::CRON_SILENT_SECONDS) {
            self::add($issues, 'error', 'cron.silent', ['since' => self::iso($modified)], 'logs');
        }
    }

    private function checkRadar(string $storage, int $now, array &$issues): void
    {
        $radar = $this->settings->radarSettings();
        $pausedUntil = self::time($radar['paused_until'] ?? null);
        if (!empty($radar['paused_forever'])) {
            self::add($issues, 'info', 'radar.paused_forever', [], 'radar');

            return;
        }
        if ($pausedUntil !== null && $pausedUntil > $now) {
            self::add($issues, 'info', 'radar.paused', ['until' => self::iso($pausedUntil)], 'radar');

            return;
        }

        $last = self::json($storage . '/radar/status.json')['last_run'] ?? null;
        $start = self::time($last['start'] ?? null);
        if (!is_array($last) || $start === null) {
            self::add($issues, 'warning', 'radar.never', [], 'radar');
        } else {
            if (($last['outcome'] ?? '') === 'failed') {
                self::add($issues, 'error', 'radar.failed', ['time' => $last['end'] ?? $last['start'], 'error' => (string) ($last['error'] ?? '')], 'radar');
            }
            if ($now - $start > self::RADAR_SILENT_SECONDS) {
                self::add($issues, 'error', 'radar.silent', ['since' => self::iso($start)], 'radar');
            }
        }

        $manifest = self::json($storage . '/radar/manifest.json');
        $observed = self::time($manifest['latest_observation'] ?? null);
        if ($observed === null) {
            self::add($issues, 'warning', 'radar.no_data', [], 'radar');
        } elseif ($now - $observed > self::RADAR_STALE_SECONDS) {
            $severity = $now - $observed > self::RADAR_STALE_ERROR_SECONDS ? 'error' : 'warning';
            self::add($issues, $severity, 'radar.stale', ['since' => self::iso($observed)], 'radar');
        }
    }

    private function checkWind(string $storage, int $now, array &$issues): void
    {
        $wind = $this->settings->windSettings();
        if (empty($wind['enabled'])) {
            return;
        }

        $last = self::json($storage . '/wind/status.json')['last_run'] ?? null;
        $start = self::time($last['start'] ?? null);
        if (!is_array($last) || $start === null) {
            self::add($issues, 'warning', 'wind.never', [], 'wind');
        } else {
            if (($last['outcome'] ?? '') === 'failed') {
                self::add($issues, 'error', 'wind.failed', ['time' => $last['end'] ?? $last['start'], 'error' => (string) ($last['error'] ?? '')], 'wind');
            }
            if ($now - $start > self::WIND_SILENT_SECONDS) {
                self::add($issues, 'error', 'wind.silent', ['since' => self::iso($start)], 'wind');
            }
        }

        $manifest = self::json($storage . '/wind/manifest.json');
        $generated = self::time($manifest['generated'] ?? null);
        if ($generated === null) {
            self::add($issues, 'warning', 'wind.no_data', [], 'wind');
        } elseif ($now - $generated > (int) ($wind['run_interval_hours'] ?? 3) * 3600 + self::WIND_STALE_EXTRA_SECONDS) {
            $hours = is_array($manifest['hours'] ?? null) ? $manifest['hours'] : [];
            $until = self::time(end($hours) ?: null);
            // the forecast no longer reaches the present = the layer shows nothing at all
            $severity = $until !== null && $until < $now ? 'error' : 'warning';
            self::add($issues, $severity, 'wind.stale', ['since' => self::iso($generated), 'until' => $until !== null ? self::iso($until) : null], 'wind');
        }
    }

    /** @param list<string> $external */
    private function checkWorker(string $storage, array $external, int $now, array &$issues): void
    {
        if ($external === []) {
            return;
        }
        $worker = JobPlacement::workerStatus($storage, $now);
        $page = in_array('wind', $external, true) ? 'wind' : 'radar';
        if ($worker === null) {
            self::add($issues, 'error', 'worker.never', ['jobs' => implode(', ', $external)], $page);

            return;
        }
        if ($worker['stale']) {
            self::add($issues, 'error', 'worker.silent', ['since' => (string) ($worker['tick'] ?? ''), 'host' => (string) ($worker['host'] ?? '')], $page);
        }
        if (!empty($worker['error'])) {
            self::add($issues, 'error', 'worker.error', ['error' => (string) $worker['error']], $page);
        }
        foreach (($worker['jobs'] ?? []) as $job => $entry) {
            if (is_array($entry) && !empty($entry['ran']) && (($entry['code'] ?? 0) !== 0 || empty($entry['published']))) {
                self::add($issues, 'error', 'worker.job_failed', ['job' => (string) $job, 'code' => $entry['code'] ?? null,
                    'published' => !empty($entry['published'])], in_array($job, JobPlacement::JOBS, true) ? (string) $job : $page);
            }
        }
    }

    private function checkOsmImport(int $now, array &$issues): void
    {
        $status = (new OsmImportJob($this->rootDir))->status();
        $updated = self::time($status['updated'] ?? $status['finished'] ?? $status['started'] ?? null);
        if ($status === [] || $updated === null || $now - $updated > self::OSM_SHOWN_SECONDS) {
            return;
        }
        $page = OsmImportJob::TOOLS[$status['tool'] ?? '-'][2] ?? null;
        $vars = ['tool' => (string) ($status['tool'] ?? ''), 'country' => (string) ($status['country'] ?? $status['label'] ?? ''),
            'time' => self::iso($updated), 'message' => (string) ($status['message'] ?? '')];
        $state = $status['state'] ?? '';
        if ($state === 'failed') {
            self::add($issues, 'warning', 'osm.failed', $vars, $page);
        } elseif ($state === 'interrupted') {
            self::add($issues, 'info', 'osm.interrupted', $vars, $page);
        } elseif ($state === 'done' && !empty($status['failed_tiles'])) {
            self::add($issues, 'warning', 'osm.failed_tiles', $vars + ['count' => count((array) $status['failed_tiles'])], $page);
        }
    }

    private function checkWeather(int $now, array &$issues): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM weather_forecast WHERE fetched_at < ?');
        $stmt->execute([gmdate('Y-m-d H:i:s', $now - self::WEATHER_ROW_MAX_DAYS * 86400)]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            self::add($issues, 'warning', 'weather.old_rows', ['count' => $count, 'days' => self::WEATHER_ROW_MAX_DAYS], 'logs');
        }
    }

    private function checkCleanup(string $storage, int $now, array &$issues): void
    {
        $log = $storage . '/logs/file-cache-cleanup.log';
        clearstatcache(true, $log);
        $modified = is_file($log) ? (int) filemtime($log) : null;
        if ($modified === null) {
            self::add($issues, 'warning', 'cleanup.never', ['script' => 'bin/cleanup-file-caches.php'], 'logs');
        } elseif ($now - $modified > self::CLEANUP_SILENT_SECONDS) {
            self::add($issues, 'warning', 'cleanup.silent', ['script' => 'bin/cleanup-file-caches.php', 'since' => self::iso($modified)], 'logs');
        }
    }

    /**
     * The error logs to watch: PHP's error_log (if it is a file) and every
     * ADMIN_LOG_FILES entry with "error" in its file name (the web server's
     * error log; its access log is not an error log). Same files /admin/logs shows.
     *
     * @param list<string> $adminLogFiles
     * @return list<string>
     */
    public static function errorLogFiles(?string $phpErrorLog, array $adminLogFiles): array
    {
        $files = [];
        foreach ([$phpErrorLog, ...array_filter($adminLogFiles, fn ($f) => stripos(basename($f), 'error') !== false)] as $file) {
            if ($file !== null && $file !== '' && !in_array($file, $files, true)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * One entry of an error log: [time, level (error|warning|null), message] or null for a line
     * without a known timestamp (stack trace lines etc.). Formats: PHP's own error_log
     * ("[12-Nov-2026 19:51:00 UTC] PHP Fatal error:  ..."), Apache ("[Wed Nov 11 10:42:50.939098 2026]
     * [php:error] [pid ...] [client ...] PHP Fatal error: ...") and nginx ("2026/11/12 19:51:00 [error] ...").
     * Notices, "Deprecated" and lines without a level (error_log() calls, CSP reports) count as null.
     *
     * @return array{0: int, 1: ?string, 2: string}|null
     */
    public static function parseErrorLine(string $line): ?array
    {
        $level = null;
        if (preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([^\]]+))?\]\s*(.*)$/', $line, $m)) {
            $time = strtotime($m[1] . (isset($m[2]) && $m[2] !== '' ? ' ' . $m[2] : ''));
            $message = $m[3];
        } elseif (preg_match('/^\[\w{3} (\w{3} +\d{1,2} \d{2}:\d{2}:\d{2})(?:\.\d+)? (\d{4})\] \[(?:[\w-]*:)?(\w+)\]\s*(.*)$/', $line, $m)) {
            $time = strtotime($m[1] . ' ' . $m[2]);
            $level = in_array($m[3], ['error', 'crit', 'alert', 'emerg'], true) ? 'error' : ($m[3] === 'warn' ? 'warning' : null);
            $message = preg_replace('/^(\[[^\]]*\]\s*)+/', '', $m[4]);
        } elseif (preg_match('/^(\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}) \[(\w+)\]\s*(.*)$/', $line, $m)) {
            $time = strtotime(str_replace('/', '-', $m[1]));
            $level = in_array($m[2], ['error', 'crit', 'alert', 'emerg'], true) ? 'error' : ($m[2] === 'warn' ? 'warning' : null);
            $message = $m[3];
        } else {
            return null;
        }
        if ($time === false) {
            return null;
        }
        // the PHP message decides (Apache logs PHP warnings as [php:warn], deprecations as [php:notice])
        if (preg_match('/PHP (Fatal error|Parse error|Recoverable fatal error)|Uncaught /', $message)) {
            $level = 'error';
        } elseif (preg_match('/PHP Warning/', $message)) {
            $level = 'warning';
        } elseif (preg_match('/PHP (Notice|Deprecated)/', $message)) {
            $level = null;
        }

        return [$time, $level, trim($message)];
    }

    private function checkErrorLogs(int $now, array &$issues): void
    {
        $readable = 0;
        foreach ($this->errorLogs as $file) {
            clearstatcache(true, $file);
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            $readable++;
            if ($now - (int) filemtime($file) > self::ERROR_LOG_WINDOW_SECONDS) {
                continue;
            }
            $size = (int) filesize($file);
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            fseek($handle, max(0, $size - self::ERROR_LOG_TAIL_BYTES));
            $text = (string) fread($handle, self::ERROR_LOG_TAIL_BYTES);
            fclose($handle);

            $errors = 0;
            $warnings = 0;
            $last = null;
            foreach (explode("\n", $text) as $line) {
                $entry = self::parseErrorLine(rtrim($line, "\r"));
                if ($entry === null || $entry[1] === null || $now - $entry[0] > self::ERROR_LOG_WINDOW_SECONDS) {
                    continue;
                }
                if ($entry[1] === 'error') {
                    $errors++;
                    $last = $entry;
                } else {
                    $warnings++;
                    $last = $errors === 0 ? $entry : $last;
                }
            }
            if ($last === null) {
                continue;
            }
            $message = mb_strimwidth(mb_scrub($last[2], 'UTF-8'), 0, 240, '…', 'UTF-8');
            self::add($issues, $errors > 0 ? 'error' : 'warning', $errors > 0 ? 'errorlog.errors' : 'errorlog.warnings', [
                'file' => basename($file), 'errors' => $errors, 'warnings' => $warnings, 'time' => self::iso($last[0]), 'message' => $message,
            ], 'logs');
        }
        if ($readable === 0) {
            self::add($issues, 'info', 'errorlog.none', [], 'logs');
        }
    }
}
