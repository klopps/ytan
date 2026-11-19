<?php

declare(strict_types=1);

namespace Ytan\Service\Log;

/**
 * Deletes log content older than MAX_AGE_SECONDS (12 months, the operator's
 * retention period for storage/logs - decided 2026-11-19, privacy note):
 *
 * - archives NAME.N.log and NAME.A-B.zip whose file is older: an archive is
 *   written when its newest line is rotated out (a ZIP later still), so the
 *   file time is never earlier than its newest entry - nothing younger is lost;
 * - in the current NAME.log the leading lines older than the cut-off (lines
 *   without a time stamp right after such a line - tracebacks - go with it).
 *   The file is rewritten in place like LogRotation does, so a process that
 *   holds it open for appending keeps writing into it. A file whose first
 *   line has no recognisable time stamp is left alone.
 *
 * Archive numbers never start again (LogRotation): the highest deleted number
 * of a log is kept in the hidden .NAME.archive-counter, which LogRotation and
 * tools/ytanlog.py also read.
 *
 * Run daily by bin/cleanup-file-caches.php. Never throws.
 */
final class LogRetention
{
    public const MAX_AGE_SECONDS = 365 * 86400;
    public const COUNTER_SUFFIX = '.archive-counter';

    /**
     * @return array{archives: list<string>, trimmed: array<string, int>} deleted archive names, removed lines per log
     */
    public static function purge(string $dir, int $now, int $maxAge = self::MAX_AGE_SECONDS): array
    {
        $cutoff = $now - $maxAge;
        $deleted = [];
        $trimmed = [];
        foreach (@scandir($dir) ?: [] as $file) {
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            if (preg_match('/^(.+)\.(?:\d+-)?(\d+)\.(?:log|zip)$/', $file, $m) === 1) {
                $mtime = @filemtime($path);
                if ($mtime !== false && $mtime < $cutoff) {
                    self::rememberNumber($dir, $m[1], (int) $m[2]);
                    if (@unlink($path)) {
                        $deleted[] = $file;
                    }
                }
            } elseif (str_ends_with($file, '.log')) {
                $removed = self::trim($path, $cutoff);
                if ($removed > 0) {
                    $trimmed[$file] = $removed;
                }
            }
        }
        sort($deleted);

        return ['archives' => $deleted, 'trimmed' => $trimmed];
    }

    /** Unix time of a line's leading time stamp (YTAN's local "YYYY-MM-DD hh:mm:ss" or PHP's "[19-Nov-2026 05:00:00 UTC]"), null = none. */
    public static function lineTime(string $line): ?int
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m) === 1) {
            $time = strtotime($m[1]);
        } elseif (preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([A-Za-z_\/+-]+))?\]/', $line, $m) === 1) {
            $time = strtotime($m[1] . (isset($m[2]) ? ' ' . $m[2] : ''));
        } else {
            return null;
        }

        return $time === false ? null : $time;
    }

    /** Removes the leading lines older than $cutoff; returns how many. */
    private static function trim(string $path, int $cutoff): int
    {
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            return 0;
        }
        $first = fgets($handle);
        $time = $first === false ? null : self::lineTime($first);
        if ($time === null || $time >= $cutoff) {
            fclose($handle);

            return 0;
        }
        $removed = 1;
        $keepFrom = null;
        while (($line = fgets($handle)) !== false) {
            $time = self::lineTime($line);
            if ($time !== null && $time >= $cutoff) {
                $keepFrom = $line;
                break;
            }
            $removed++;
        }
        $rest = $keepFrom === null ? '' : $keepFrom . (string) stream_get_contents($handle);
        rewind($handle);
        fwrite($handle, $rest);
        ftruncate($handle, strlen($rest));
        fclose($handle);

        return $removed;
    }

    /** The counter file of a log (hidden: the log viewer lists every other file in storage/logs). */
    public static function counterFile(string $dir, string $stem): string
    {
        return $dir . '/.' . $stem . self::COUNTER_SUFFIX;
    }

    private static function rememberNumber(string $dir, string $stem, int $number): void
    {
        $file = self::counterFile($dir, $stem);
        $known = @file_get_contents($file);
        if ($known === false || (int) trim($known) < $number) {
            @file_put_contents($file, (string) $number, LOCK_EX);
        }
    }
}
