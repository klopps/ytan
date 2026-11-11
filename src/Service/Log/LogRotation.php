<?php

declare(strict_types=1);

namespace Ytan\Service\Log;

/**
 * Keeps a log file short: once it has more than MAX_LINES lines, its oldest
 * ARCHIVE_LINES lines move into a new file NAME.N.log (N = the next free
 * number), as often as needed. The file itself is rewritten in place (not
 * replaced), so a process that holds it open in append mode keeps writing
 * into it. Same rules as tools/ytanlog.py's rotate().
 */
final class LogRotation
{
    public const MAX_LINES = 2000;
    public const ARCHIVE_LINES = 1000;

    public static function countLines(string $path): int
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }
        $lines = 0;
        while (!feof($handle)) {
            $lines += substr_count((string) fread($handle, 1048576), "\n");
        }
        fclose($handle);

        return $lines;
    }

    /** @return list<string> the archives written */
    public static function rotate(string $path, int $maxLines = self::MAX_LINES, int $archiveLines = self::ARCHIVE_LINES): array
    {
        $total = self::countLines($path);
        if ($total <= $maxLines) {
            return [];
        }
        $chunks = intdiv($total - $maxLines + $archiveLines - 1, $archiveLines);
        $stem = str_ends_with($path, '.log') ? substr($path, 0, -4) : $path;
        $number = self::nextArchiveNumber($path);

        $handle = fopen($path, 'r+b');
        if ($handle === false) {
            return [];
        }
        $archives = [];
        for ($c = 0; $c < $chunks; $c++) {
            $archive = $stem . '.' . $number . '.log';
            $out = fopen($archive, 'wb');
            if ($out === false) {
                break;
            }
            for ($i = 0; $i < $archiveLines; $i++) {
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                fwrite($out, $line);
            }
            fclose($out);
            $archives[] = $archive;
            $number++;
        }
        $rest = (string) stream_get_contents($handle);
        rewind($handle);
        fwrite($handle, $rest);
        ftruncate($handle, strlen($rest));
        fclose($handle);

        return $archives;
    }

    private static function nextArchiveNumber(string $path): int
    {
        $base = basename($path);
        $stem = str_ends_with($base, '.log') ? substr($base, 0, -4) : $base;
        $max = 0;
        foreach (scandir(dirname($path)) ?: [] as $file) {
            if (preg_match('/^' . preg_quote($stem, '/') . '\.(\d+)\.log$/', $file, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }
}
