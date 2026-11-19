<?php

declare(strict_types=1);

namespace Ytan\Service\Weather;

/**
 * Remembers for a few minutes that the forecast for a (rounded) point could
 * not be fetched, so the next request for it fails at once instead of
 * waiting for the providers again - a Routencheck asks for up to 5 points and
 * every failed provider call costs up to the connect timeout (4 s; found
 * 2026-11-18: a dead DNS server made single Routenchecks take 20 s, and the
 * same points failed again on the next try).
 *
 * One small file per point in $dir (content = time of the failure), so
 * parallel requests need no lock; expired files are removed whenever a new
 * failure is written. Never throws - without a writable directory nothing
 * is remembered.
 */
final class WeatherFailureMemory
{
    public const TTL_SECONDS = 180;

    /** @var \Closure(): int */
    private \Closure $now;

    public function __construct(private readonly string $dir, ?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): int => time();
    }

    public function recentlyFailed(float $lat, float $lng): bool
    {
        $at = @file_get_contents($this->file($lat, $lng));

        return $at !== false && ($this->now)() - (int) $at < self::TTL_SECONDS;
    }

    public function remember(float $lat, float $lng): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return;
        }
        $now = ($this->now)();
        foreach (glob($this->dir . '/*.fail') ?: [] as $file) {
            $at = @file_get_contents($file);
            if ($at === false || $now - (int) $at >= self::TTL_SECONDS) {
                @unlink($file);
            }
        }
        @file_put_contents($this->file($lat, $lng), (string) $now, LOCK_EX);
    }

    private function file(float $lat, float $lng): string
    {
        return $this->dir . '/' . sprintf('%.4F_%.4F', $lat, $lng) . '.fail';
    }
}
