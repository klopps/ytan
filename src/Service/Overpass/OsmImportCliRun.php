<?php

declare(strict_types=1);

namespace Ytan\Service\Overpass;

use Throwable;

/**
 * The CLI side of OsmImportJob, shared by bin/import-campsites.php,
 * bin/import-clubs-osm.php and bin/import-shelters.php: holds the OSM import
 * lock for the whole run (no second OSM import - started by hand or from an
 * admin page - can overlap), knows who started it (--user=NAME, set by
 * OsmImportJob::start(); null = run by hand) and keeps
 * storage/osm-import-status.json current, which the admin pages poll.
 */
final class OsmImportCliRun
{
    /** @var resource|null */
    private $lock = null;

    private array $status = [];

    public function __construct(private readonly OsmImportJob $job, private readonly string $tool, private readonly ?string $user)
    {
    }

    /**
     * Takes --user=NAME out of the arguments.
     *
     * @param list<string> $args
     * @return array{0: list<string>, 1: string|null} the other arguments, the user
     */
    public static function parseArgs(array $args): array
    {
        $user = null;
        $rest = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--user=')) {
                $user = substr($arg, 7) ?: null;
            } else {
                $rest[] = $arg;
            }
        }

        return [$rest, $user];
    }

    public function user(): ?string
    {
        return $this->user;
    }

    /** Takes the lock; false if another OSM import is running. */
    public function begin(): bool
    {
        $this->lock = $this->job->lock();

        return $this->lock !== null;
    }

    public function country(string $country, int $tiles): void
    {
        $this->status = [
            'tool' => $this->tool, 'country' => $country, 'user' => $this->user ?? 'cli', 'state' => 'running',
            'tile' => 1, 'tiles' => $tiles, 'sums' => [], 'failed_tiles' => [], 'message' => '',
            'started' => $this->status['started'] ?? date('c'),
        ];
        $this->job->writeStatus($this->status);
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
        $this->status['failed_tiles'][] = $tile + 1;
        $this->status['state'] = 'running';
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
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }
}
