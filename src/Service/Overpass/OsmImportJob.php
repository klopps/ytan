<?php

declare(strict_types=1);

namespace Ytan\Service\Overpass;

use Closure;
use Ytan\Exception\ApiException;
use Ytan\Exception\ValidationException;

/**
 * Loading a country from OpenStreetMap (campsites, clubs, shelters) as a
 * background process: a big country takes many minutes (France: 20 tiles,
 * retries 30 s apart), so the admin page starts the tool's CLI script
 * (bin/import-*.php, which loads tile by tile with OverpassRetry and logs
 * every step into storage/logs/{tool}-import.log) and only shows the status.
 * The page used to drive the tiles itself, one request each - leaving the
 * page (e.g. to watch /admin/logs) ended the import after the current tile.
 *
 * One OSM import at a time (they share the Overpass gap): the script holds an
 * exclusive lock on storage/osm-import.lock for its whole run (lock()), and
 * reports its progress in storage/osm-import-status.json (writeStatus()).
 */
final class OsmImportJob
{
    /** tool => [script, argument prefix of a country, log name] */
    public const TOOLS = [
        'campsite' => ['bin/import-campsites.php', '', 'campsite-import'],
        'club' => ['bin/import-clubs-osm.php', '', 'club-import'],
        'shelter' => ['bin/import-shelters.php', 'osm:', 'shelter-import'],
        // an area, not a country: startArea()
        'lighthouse' => ['bin/import-lighthouses-osm.php', '', 'lighthouse-import'],
    ];

    private const LOCK = '/storage/osm-import.lock';
    private const STATUS = '/storage/osm-import-status.json';

    /** @var Closure(string $command, string $consoleLog): void */
    private Closure $spawn;

    /** @param (Closure(string, string): void)|null $spawn starts the command in the background (injectable for tests) */
    /** True when the real background start is used (not a test's). */
    private bool $detached;

    public function __construct(private readonly string $rootDir, ?Closure $spawn = null)
    {
        $this->detached = $spawn === null;
        $this->spawn = $spawn ?? Closure::fromCallable([self::class, 'spawnDetached']);
    }

    public function isRunning(): bool
    {
        $file = $this->rootDir . self::LOCK;
        if (!is_file($file)) {
            return false;
        }
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return false;
        }
        try {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                flock($handle, LOCK_UN);

                return false;
            }

            return true;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Starts the background import of one country.
     *
     * @throws ApiException 409 osm_import.running, 503 osm_import.cannot_start
     */
    public function start(string $tool, string $country, string $username): array
    {
        if (!isset(self::TOOLS[$tool]) || $tool === 'lighthouse') {
            throw new ValidationException('tool must be one of: campsite, club, shelter.');
        }
        $country = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new ValidationException('country must be a two-letter ISO 3166-1 code.');
        }

        return $this->launch($tool, [self::TOOLS[$tool][1] . $country], $country, $username);
    }

    /**
     * Starts the background import of the lights of one area (lighthouse tool).
     *
     * @throws ValidationException the area rules of OsmImportService
     */
    public function startArea(float $south, float $west, float $north, float $east, string $label, string $username): array
    {
        \Ytan\Service\OsmImportService::validateArea($south, $west, $north, $east, $label);
        $label = trim($label);

        return $this->launch('lighthouse', [
            // base64: a label with umlauts would not survive the Windows command line (ANSI code page)
            '--south=' . $south, '--west=' . $west, '--north=' . $north, '--east=' . $east, '--label64=' . base64_encode($label),
        ], $label, $username);
    }

    /** @param list<string> $args the script's arguments besides --user */
    private function launch(string $tool, array $args, string $what, string $username): array
    {
        if ($this->isRunning()) {
            throw new ApiException('An OpenStreetMap import is running right now.', 409, 'osm_import.running');
        }
        if ($this->detached && !self::canExec()) {
            throw new ApiException('This server does not allow starting background jobs.', 503, 'osm_import.cannot_start');
        }
        $user = preg_replace('/[^A-Za-z0-9._@-]/', '', $username) ?: 'admin';
        $command = escapeshellarg(self::phpBinary()) . ' ' . escapeshellarg($this->rootDir . '/' . self::TOOLS[$tool][0]);
        foreach (array_merge($args, ['--user=' . $user]) as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $status = ['tool' => $tool, 'country' => $what, 'user' => $user, 'state' => 'starting', 'started' => date('c')];
        $this->writeStatus($status);
        $logDir = $this->rootDir . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        // The script writes its own log; console.log only catches a crash before that.
        ($this->spawn)($command, $logDir . '/console.log');

        return $status + ['running' => true];
    }

    /** The last status written (empty array if none), plus whether a run holds the lock now. */
    public function status(): array
    {
        $file = $this->rootDir . self::STATUS;
        $status = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $status = is_array($status) ? $status : [];
        $running = $this->isRunning();
        // Started but the process never took the lock (crashed at once) - after a minute that's a failure.
        if (!$running && ($status['state'] ?? null) === 'starting' && strtotime((string) ($status['started'] ?? '')) < time() - 60) {
            $status['state'] = 'failed';
            $status['message'] = 'The background process did not start (see storage/logs/console.log).';
        }
        // Says it is running, but nobody holds the lock: the process died (killed, PHP fatal) before it could finish.
        if (!$running && in_array($status['state'] ?? null, ['running', 'retrying'], true)) {
            $status['state'] = 'failed';
            $status['message'] = 'The background process ended unexpectedly (see the import log and storage/logs/console.log).';
        }

        return $status + ['running' => $running];
    }

    /**
     * For the CLI script: takes the exclusive lock for its whole run. Returns
     * the handle to keep open, or null if another import holds it.
     *
     * @return resource|null
     */
    public function lock()
    {
        $file = $this->rootDir . self::LOCK;
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** For the CLI script: replaces the status file (atomically, the page may read it at any time). */
    public function writeStatus(array $status): void
    {
        $file = $this->rootDir . self::STATUS;
        $status['updated'] = date('c');
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            if (!@rename($tmp, $file)) {
                @unlink($file);
                @rename($tmp, $file);
            }
        }
    }

    /**
     * The PHP CLI binary: .env's PHP_CLI, else this one if it is the CLI,
     * else a php(.exe) next to the running binary (php-fpm/php-cgi) or next
     * to the loaded php.ini (Apache mod_php), else "php" from the PATH.
     */
    public static function phpBinary(): string
    {
        $configured = trim((string) ($_ENV['PHP_CLI'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
            return PHP_BINARY;
        }
        $exe = DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';
        $dirs = [];
        if (PHP_BINARY !== '') {
            $dirs[] = dirname(PHP_BINARY);
        }
        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') {
            $dirs[] = dirname($ini);
        }
        $dirs[] = PHP_BINDIR;
        foreach ($dirs as $dir) {
            if (is_file($dir . DIRECTORY_SEPARATOR . $exe)) {
                return $dir . DIRECTORY_SEPARATOR . $exe;
            }
        }

        return 'php';
    }

    private static function spawnDetached(string $command, string $consoleLog): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows (local development): "start /B" returns at once
            pclose(popen('start "" /B ' . $command . ' >> ' . escapeshellarg($consoleLog) . ' 2>&1', 'r'));

            return;
        }
        // Linux: its own session, so the import outlives this request and the PHP worker
        exec('setsid nohup ' . $command . ' >> ' . escapeshellarg($consoleLog) . ' 2>&1 < /dev/null &', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new ApiException('Starting the background import failed.', 503, 'osm_import.cannot_start');
        }
    }

    private static function canExec(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('exec') && function_exists('popen')
            && !in_array('exec', $disabled, true) && !in_array('popen', $disabled, true);
    }
}
