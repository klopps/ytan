<?php

declare(strict_types=1);

namespace Ytan\Service\Log;

/**
 * Keeps a log file short: once it has more than MAX_LINES lines, its oldest
 * ARCHIVE_LINES lines move into a new file NAME.N.log (N = the next free
 * number), as often as needed. The file itself is rewritten in place (not
 * replaced), so a process that holds it open in append mode keeps writing
 * into it. Once there are more than MAX_ARCHIVES such archives, all but the
 * newest KEEP_ARCHIVES are packed into NAME.A-B.zip (A-B = their numbers)
 * and deleted - only after the ZIP was written. Same rules as
 * tools/ytanlog.py's rotate()/compact().
 */
final class LogRotation
{
    public const MAX_LINES = 2000;
    public const ARCHIVE_LINES = 1000;
    public const MAX_ARCHIVES = 20;
    public const KEEP_ARCHIVES = 10;

    /**
     * Packs all but the newest $keep archives NAME.N.log into NAME.A-B.zip
     * when there are more than $max of them. Needs PHP's zip extension -
     * without it nothing is packed (and nothing deleted).
     *
     * @return string|null the ZIP written
     */
    public static function compact(string $path, int $max = self::MAX_ARCHIVES, int $keep = self::KEEP_ARCHIVES): ?string
    {
        $archives = self::archives($path);
        if (count($archives) <= $max || !class_exists(\ZipArchive::class)) {
            return null;
        }
        $old = array_slice($archives, 0, count($archives) - $keep, true);
        $numbers = array_keys($old);
        $zipPath = self::stem($path) . '.' . $numbers[0] . '-' . end($numbers) . '.zip';
        if (is_file($zipPath)) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            return null;
        }
        foreach ($old as $file) {
            $zip->addFile($file, basename($file));
        }
        if (!$zip->close()) {
            @unlink($zipPath);

            return null;
        }
        // Delete only what the ZIP now really holds.
        $check = new \ZipArchive();
        if ($check->open($zipPath) !== true || $check->numFiles !== count($old)) {
            return null;
        }
        $check->close();
        foreach ($old as $file) {
            @unlink($file);
        }

        return $zipPath;
    }

    /** @return array<int,string> number => path of the NAME.N.log archives, oldest first */
    public static function archives(string $path): array
    {
        $dir = dirname($path);
        $pattern = '/^' . preg_quote(basename(self::stem($path)), '/') . '\.(\d+)\.log$/';
        $found = [];
        foreach (@scandir($dir) ?: [] as $file) {
            if (preg_match($pattern, $file, $m) === 1) {
                $found[(int) $m[1]] = $dir . '/' . $file;
            }
        }
        ksort($found);

        return $found;
    }

    private static function stem(string $path): string
    {
        return str_ends_with($path, '.log') ? substr($path, 0, -4) : $path;
    }

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

        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            return [];
        }
        $archives = [];
        for ($c = 0; $c < $chunks; $c++) {
            $archive = $stem . '.' . $number . '.log';
            $out = @fopen($archive, 'wb');
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
        self::compact($path);

        return $archives;
    }

    private static function nextArchiveNumber(string $path): int
    {
        $base = basename($path);
        $stem = str_ends_with($base, '.log') ? substr($base, 0, -4) : $base;
        $max = 0;
        foreach (@scandir(dirname($path)) ?: [] as $file) {
            // NAME.N.log, and NAME.A-B.zip of packed ones (numbers never start again after a compact)
            if (preg_match('/^' . preg_quote($stem, '/') . '\.(?:\d+-)?(\d+)\.(?:log|zip)$/', $file, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }
}
