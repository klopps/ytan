<?php

declare(strict_types=1);

namespace Ytan\Service\Log;

use RuntimeException;
use ZipArchive;

/**
 * The log files an admin may look at on /admin/logs (LogController): every
 * regular file in storage/logs (the jobs' own logs - radar, wind, imports,
 * cron output), the YTAN worker's logs (storage/logs/worker, "w:wind.log"),
 * PHP's own error_log if it is a file, and the files listed in
 * .env's ADMIN_LOG_FILES (comma separated, e.g. the web server's logs).
 *
 * Only these files are reachable - a file is addressed by an id from list()
 * ("s:radar.log", "php", "x:0"), never by a path from the request, so no
 * request can name an arbitrary file.
 */
final class LogFileService
{
    /** What one read() returns at most - more is fetched by the next call. */
    public const MAX_READ_BYTES = 1048576;

    /** Subdirectory of storage/logs the YTAN worker uploads its own logs to. */
    public const WORKER_DIR = 'worker';

    /** @param list<string> $extraFiles */
    public function __construct(
        private readonly string $logDir,
        private readonly ?string $phpErrorLog = null,
        private readonly array $extraFiles = [],
    ) {
    }

    /**
     * @param string $setting the ADMIN_LOG_FILES setting (comma separated), may be empty
     * @return list<string>
     */
    public static function parseExtraFiles(string $setting): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $setting)), fn ($f) => $f !== ''));
    }

    /**
     * @return list<array{id:string, name:string, path:string, source:string, size:int, modified:?string}>
     */
    public function list(): array
    {
        $files = [];
        foreach ($this->paths() as $id => [$path, $source]) {
            clearstatcache(true, $path);
            $files[] = [
                'id' => $id,
                'name' => basename($path),
                'path' => $path,
                'source' => $source,
                'size' => (int) filesize($path),
                'modified' => self::modified($path),
            ];
        }

        return $files;
    }

    /** The path behind an id, or null for an unknown id / a file that is gone. */
    public function resolve(string $id): ?string
    {
        return $this->paths()[$id][0] ?? null;
    }

    /**
     * A piece of a log file. Without $from: its last $maxBytes (starting at a
     * line beginning). With $from: what was appended since that byte offset;
     * if the file is now shorter than $from it was rotated or emptied, then
     * its tail is returned with reset = true.
     *
     * @return array{content:string, from:int, to:int, size:int, modified:?string, truncated:bool, reset:bool, more:bool}
     */
    public function read(string $id, ?int $from = null, int $maxBytes = self::MAX_READ_BYTES): array
    {
        $path = $this->resolve($id);
        if ($path === null) {
            throw new RuntimeException('Unknown log file.');
        }
        $maxBytes = max(1, min($maxBytes, self::MAX_READ_BYTES));
        clearstatcache(true, $path);
        $size = (int) filesize($path);

        $reset = $from !== null && $from > $size;
        $tail = $from === null || $reset;
        $start = $tail ? max(0, $size - $maxBytes) : max(0, $from);
        $length = min($maxBytes, $size - $start);

        $content = '';
        if ($length > 0) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Log file not readable.');
            }
            fseek($handle, $start);
            $content = (string) fread($handle, $length);
            fclose($handle);
        }
        $end = $start + strlen($content);

        // A tail that starts in the middle of the file begins with a line fragment.
        if ($tail && $start > 0) {
            $newline = strpos($content, "\n");
            if ($newline !== false) {
                $start += $newline + 1;
                $content = substr($content, $newline + 1);
            }
        }

        return [
            'content' => mb_scrub($content, 'UTF-8'),
            'from' => $start,
            'to' => $end,
            'size' => $size,
            'modified' => self::modified($path),
            'truncated' => $start > 0 && $tail,
            'reset' => $reset,
            'more' => $end < $size,
        ];
    }

    /**
     * Packs the given log files into a ZIP in the system temp directory; the
     * caller sends and deletes it. Two files of the same name get the
     * source in front ("php-error.log", "x0-error.log").
     *
     * @param list<string> $ids
     */
    public function zip(array $ids): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is not available.');
        }
        $zipPath = tempnam(sys_get_temp_dir(), 'ytan-logs-');
        $zip = new ZipArchive();
        if ($zipPath === false || $zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the ZIP file.');
        }
        $used = [];
        foreach (array_unique($ids) as $id) {
            $path = $this->resolve($id);
            if ($path === null) {
                continue;
            }
            $name = basename($path);
            if (isset($used[$name])) {
                $name = preg_replace('/[^a-z0-9]/i', '', $id) . '-' . $name;
            }
            $used[$name] = true;
            $zip->addFile($path, $name);
        }
        $zip->close();

        return $zipPath;
    }

    /** @return array<string, array{0:string, 1:string}> id => [path, source] of every existing, readable log file */
    private function paths(): array
    {
        $paths = [];
        $names = is_dir($this->logDir) ? (scandir($this->logDir) ?: []) : [];
        sort($names);
        foreach ($names as $name) {
            $path = $this->logDir . DIRECTORY_SEPARATOR . $name;
            if ($name[0] !== '.' && is_file($path) && is_readable($path)) {
                $paths['s:' . $name] = [$path, 'storage'];
            }
        }
        // the YTAN worker's logs, uploaded by tools/ytan_worker.py (publish_logs()) on every tick
        $workerDir = $this->logDir . DIRECTORY_SEPARATOR . self::WORKER_DIR;
        $names = is_dir($workerDir) ? (scandir($workerDir) ?: []) : [];
        sort($names);
        foreach ($names as $name) {
            $path = $workerDir . DIRECTORY_SEPARATOR . $name;
            if ($name[0] !== '.' && is_file($path) && is_readable($path)) {
                $paths['w:' . $name] = [$path, 'worker'];
            }
        }
        if ($this->phpErrorLog !== null && $this->phpErrorLog !== '' && is_file($this->phpErrorLog) && is_readable($this->phpErrorLog)) {
            $paths['php'] = [$this->phpErrorLog, 'php'];
        }
        foreach ($this->extraFiles as $i => $file) {
            if (is_file($file) && is_readable($file)) {
                $paths['x:' . $i] = [$file, 'extra'];
            }
        }

        return $paths;
    }

    private static function modified(string $path): ?string
    {
        $time = filemtime($path);

        return $time === false ? null : date('c', $time);
    }
}
