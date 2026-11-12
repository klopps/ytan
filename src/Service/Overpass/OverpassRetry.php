<?php

declare(strict_types=1);

namespace Ytan\Service\Overpass;

use Closure;
use Throwable;
use Ytan\Exception\OverpassException;

/**
 * For the OSM import scripts (bin/import-*.php, also when OsmImportJob runs
 * them in the background for an admin page): a step that
 * failed because Overpass didn't deliver (OverpassException - busy,
 * unreachable, no JSON; CurlOverpassClient already retried "busy" twice
 * within the request) is run again after DELAY_SECONDS, up to RETRIES times,
 * so one bad minute of the public instance doesn't lose a whole country.
 * Other errors are not retried.
 */
final class OverpassRetry
{
    public const RETRIES = 3;
    public const DELAY_SECONDS = 30;

    /**
     * @template T
     * @param Closure(): T $step
     * @param Closure(int $attempt, int $max, int $delaySeconds, Throwable $error): void $onRetry called before each wait
     * @param (Closure(int): void)|null $sleep injectable for tests
     * @return T
     */
    public static function run(Closure $step, Closure $onRetry, ?Closure $sleep = null): mixed
    {
        $sleep ??= static fn (int $seconds) => sleep($seconds);
        for ($retry = 0; ; $retry++) {
            try {
                return $step();
            } catch (OverpassException $e) {
                if ($retry >= self::RETRIES) {
                    throw $e;
                }
                $onRetry($retry + 1, self::RETRIES, self::DELAY_SECONDS, $e);
                $sleep(self::DELAY_SECONDS);
            }
        }
    }

    /**
     * run() for the CLI scripts: writes "{label}: retry n/3 pending in 30 s
     * after: ..." through $line before each wait, and when the retries are used
     * up throws an OverpassException "GAVE UP after 4 attempt(s): ..." (same
     * message otherwise), so the script's FAILED line says it was final.
     *
     * @template T
     * @param Closure(): T $step
     * @param Closure(string): void $line
     * @param (Closure(int): void)|null $sleep
     * @param (Closure(int $attempt, int $max, int $delaySeconds, Throwable $error): void)|null $onRetry also told (status file)
     * @return T
     */
    public static function logged(Closure $step, Closure $line, string $label, ?Closure $sleep = null, ?Closure $onRetry = null): mixed
    {
        $attempts = 1;
        try {
            return self::run($step, function (int $attempt, int $max, int $delay, Throwable $e) use ($line, $label, &$attempts, $onRetry) {
                $attempts = $attempt + 1;
                $line(sprintf('%s: retry %d/%d pending in %d s after: %s', $label, $attempt, $max, $delay, $e->getMessage()));
                if ($onRetry !== null) {
                    $onRetry($attempt, $max, $delay, $e);
                }
            }, $sleep);
        } catch (OverpassException $e) {
            throw new OverpassException(sprintf('GAVE UP after %d attempt(s): %s', $attempts, $e->getMessage()));
        }
    }
}
