<?php

declare(strict_types=1);

namespace Ytan\Service\Overpass;

use Closure;
use Throwable;

/**
 * The CLI side of OsmImportJob, shared by bin/import-campsites.php,
 * bin/import-clubs-osm.php, bin/import-shelters.php and
 * bin/import-lighthouses-osm.php: holds the OSM import lock for the whole run
 * (no second OSM import - started by hand or from an admin page - can
 * overlap), knows who started it (--user=NAME, set by OsmImportJob::start();
 * null = run by hand) and keeps storage/osm-import-status.json current, which
 * the admin pages poll.
 *
 * Resuming (found on prod 2026-11-12: France ended silently after 11 of 20
 * tiles): a process the admin page starts lives in the cgroup of the
 * account's php-fpm systemd service, and when that service is stopped
 * (socket activated, stopped when idle; KillMode=control-group) every process
 * in it is killed - setsid/nohup don't help. So the status also says how to
 * run the script again (`resume`: PHP binary, script, arguments, log name),
 * and tools/ytan_jobs.py (cron, every 5 minutes, outside that cgroup) starts
 * it again with --resume when the status says running but nobody holds the
 * lock. --resume continues at the tile that was in progress and keeps the
 * sums of the run so far. A SIGTERM is logged before the process ends.
 */
final class OsmImportCliRun
{
    /** @var resource|null */
    private $lock = null;

    private array $status = [];

    /** The status of the interrupted run (--resume), [] otherwise. */
    private array $previous = [];

    /**
     * @param list<string> $args the script's arguments without --user/--resume (to run it again)
     * @param string|null $script the script's path (to run it again); null = not resumable
     * @param (Closure(string): void)|null $logLine writes one line into the tool's log
     */
    public function __construct(
        private readonly OsmImportJob $job,
        private readonly string $tool,
        private readonly ?string $user,
        private readonly bool $resume = false,
        private readonly array $args = [],
        private readonly ?string $script = null,
        private readonly ?Closure $logLine = null,
    ) {
    }

    /**
     * Takes --user=NAME and --resume out of the arguments.
     *
     * @param list<string> $args
     * @return array{0: list<string>, 1: string|null, 2: bool} the other arguments, the user, resume
     */
    public static function parseArgs(array $args): array
    {
        $user = null;
        $resume = false;
        $rest = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--user=')) {
                $user = substr($arg, 7) ?: null;
            } elseif ($arg === '--resume') {
                $resume = true;
            } else {
                $rest[] = $arg;
            }
        }

        return [$rest, $user, $resume];
    }

    public function user(): ?string
    {
        return $this->user;
    }

    /** Takes the lock; false if another OSM import is running. */
    public function begin(): bool
    {
        $this->lock = $this->job->lock();
        if ($this->lock === null) {
            return false;
        }
        if ($this->resume) {
            $previous = $this->job->readStatus();
            $this->previous = ($previous['tool'] ?? null) === $this->tool ? $previous : [];
        }
        $this->watchSignals();

        return true;
    }

    /**
     * The first tile (0-based) to load for a country: 0, or with --resume the
     * one the interrupted run was working on.
     */
    public function firstTile(string $country): int
    {
        if ($this->previous === [] || ($this->previous['country'] ?? null) !== $country) {
            return 0;
        }

        return max(0, (int) ($this->previous['tile'] ?? 1) - 1);
    }

    public function country(string $country, int $tiles): void
    {
        $carry = $this->previous !== [] && ($this->previous['country'] ?? null) === $country ? $this->previous : [];
        $this->status = [
            'tool' => $this->tool, 'country' => $country, 'user' => $this->user ?? 'cli', 'state' => 'running',
            'tile' => $this->firstTile($country) + 1, 'tiles' => $tiles,
            'sums' => $carry['sums'] ?? [], 'failed_tiles' => $carry['failed_tiles'] ?? [], 'message' => '',
            'started' => $carry['started'] ?? $this->status['started'] ?? date('c'),
            'resumes' => (int) ($carry['resumes'] ?? 0),
        ];
        if ($this->script !== null) {
            $this->status['resume'] = [
                'php' => PHP_BINARY, 'script' => $this->script, 'args' => $this->args,
                'log' => OsmImportJob::TOOLS[$this->tool][2] ?? $this->tool . '-import',
            ];
        }
        $this->job->writeStatus($this->status);
        if ($carry !== []) {
            $this->line(sprintf('cli: continues %s at tile %d/%d (resumed %d time(s) after the process had ended unexpectedly)', $country, $this->status['tile'], $tiles, $this->status['resumes']));
        }
    }

    /** @param int $tile 0-based */
    public function tileDone(int $tile, array $result): void
    {
        foreach ($result as $key => $value) {
            if (is_int($value) && $key !== 'tile' && $key !== 'tiles') {
                $this->status['sums'][$key] = ($this->status['sums'][$key] ?? 0) + $value;
            }
        }
        $this->status['state'] = 'running';
        $this->status['tile'] = min($tile + 2, $this->status['tiles'] ?? $tile + 2);
        $this->status['message'] = '';
        $this->job->writeStatus($this->status);
    }

    /** @param int $tile 0-based */
    public function retry(int $tile, int $attempt, int $max, int $delay, Throwable $e): void
    {
        $this->status['state'] = 'retrying';
        $this->status['tile'] = $tile + 1;
        $this->status['message'] = sprintf('retry %d/%d in %d s after: %s', $attempt, $max, $delay, $e->getMessage());
        $this->job->writeStatus($this->status);
    }

    /** @param int $tile 0-based */
    public function tileFailed(int $tile, Throwable $e): void
    {
        if (!in_array($tile + 1, $this->status['failed_tiles'] ?? [], true)) {
            $this->status['failed_tiles'][] = $tile + 1;
        }
        $this->status['state'] = 'running';
        // on to the next tile: a resume must not load this one again
        $this->status['tile'] = min($tile + 2, $this->status['tiles'] ?? $tile + 2);
        $this->status['message'] = $e->getMessage();
        $this->job->writeStatus($this->status);
    }

    /** Ends the run: done, or failed when a tile or step failed. */
    public function finish(bool $ok, string $message = ''): void
    {
        if ($this->status !== []) {
            $this->status['state'] = $ok && ($this->status['failed_tiles'] ?? []) === [] ? 'done' : 'failed';
            $this->status['message'] = $message !== '' ? $message : (string) ($this->status['message'] ?? '');
            $this->status['finished'] = date('c');
            $this->job->writeStatus($this->status);
        }
        $this->unlock();
    }

    private function unlock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /**
     * SIGTERM/SIGHUP/SIGINT (e.g. the php-fpm service the process was started
     * from is stopped): one log line, the lock released, the status left at
     * "running" - which is what lets tools/ytan_jobs.py resume it.
     */
    private function watchSignals(): void
    {
        if (PHP_SAPI !== 'cli' || !function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGHUP, SIGINT] as $signal) {
            pcntl_signal($signal, function (int $signal): void {
                $this->line(sprintf(
                    'cli: stopped by signal %d at tile %s/%s - %s',
                    $signal,
                    $this->status['tile'] ?? '?',
                    $this->status['tiles'] ?? '?',
                    $this->script !== null ? 'the cron job (tools/ytan_jobs.py) continues it within 5 minutes' : 'not resumable'
                ));
                $this->unlock();
                exit(128 + $signal);
            });
        }
    }

    private function line(string $line): void
    {
        if ($this->logLine !== null) {
            try {
                ($this->logLine)($line);
            } catch (Throwable) {
                // a log line never stops the import
            }
        }
    }
}
