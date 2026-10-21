<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ApiException;

/**
 * Keeps the minimum gap between two Overpass requests (the public instances
 * are shared; their policy asks for a pause). Uses the same file as
 * OsmImportService and ClubOsmImportService. The admin tool refuses a request
 * that comes too soon; the CLI scripts wait.
 */
final class OverpassThrottle
{
    /** @var callable():int */
    private $clock;

    /** @param (callable():int)|null $clock current Unix time, injectable for tests */
    public function __construct(private readonly string $file, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function pass(bool $wait): void
    {
        $now = ($this->clock)();
        $last = is_file($this->file) ? (int) @file_get_contents($this->file) : 0;
        $remaining = $last + OsmImportService::MIN_SECONDS_BETWEEN_REQUESTS - $now;
        if ($remaining > 0) {
            if (!$wait) {
                throw new ApiException('The Overpass API is asked for at most one area per ' . OsmImportService::MIN_SECONDS_BETWEEN_REQUESTS . " seconds - please wait $remaining s.", 429, 'lighthouse_import.osm_too_soon');
            }
            sleep($remaining);
            $now += $remaining;
        }

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->file, (string) $now);
    }
}
