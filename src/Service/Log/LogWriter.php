<?php

declare(strict_types=1);

namespace Ytan\Service\Log;

/**
 * A log file in storage/logs that a CLI script writes itself (bin/import-*.php,
 * bin/cleanup-*.php, ...): appended, never overwritten, every line starting
 * with the local "YYYY-MM-DD hh:mm:ss", rotated by LogRotation. Same rules as
 * the Python tools (tools/ytanlog.py).
 *
 * Lines are echoed to the console only when it is a terminal: cron lines
 * that redirect the output (old ones even into the same file) would
 * otherwise get every line twice. error() always goes to STDERR as well.
 */
final class LogWriter
{
    private int $lines;

    /** @param resource|null $console where line() echoes to (null = nowhere) */
    public function __construct(
        private readonly string $path,
        private $console = null,
        private readonly int $maxLines = LogRotation::MAX_LINES,
        private readonly int $archiveLines = LogRotation::ARCHIVE_LINES,
    ) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->rotate();
    }

    /** storage/logs/{name}.log of this checkout; console echo when STDOUT is a terminal. */
    public static function cli(string $rootDir, string $name): self
    {
        $tty = defined('STDOUT') && stream_isatty(STDOUT);

        return new self($rootDir . '/storage/logs/' . $name . '.log', $tty ? STDOUT : null);
    }

    public static function stamp(string $message, ?int $time = null): string
    {
        $prefix = date('Y-m-d H:i:s', $time ?? time()) . ' ';
        $out = '';
        foreach (explode("\n", rtrim(str_replace("\r\n", "\n", $message), "\n")) as $line) {
            $out .= $prefix . $line . "\n";
        }

        return $out;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function line(string $message): void
    {
        $stamped = self::stamp($message);
        $this->append($stamped);
        if ($this->console !== null) {
            fwrite($this->console, $stamped);
        }
    }

    /** An error: into the log ("ERROR ...") and always onto STDERR. */
    public function error(string $message): void
    {
        $stamped = self::stamp('ERROR ' . $message);
        $this->append($stamped);
        if (defined('STDERR')) {
            fwrite(STDERR, $stamped);
        }
    }

    private function append(string $stamped): void
    {
        file_put_contents($this->path, $stamped, FILE_APPEND | LOCK_EX);
        $this->lines += substr_count($stamped, "\n");
        if ($this->lines > $this->maxLines) {
            $this->rotate();
        }
    }

    private function rotate(): void
    {
        try {
            LogRotation::rotate($this->path, $this->maxLines, $this->archiveLines);
        } catch (\Throwable) {
            // e.g. Windows refusing a file another process holds open - next time
        }
        $this->lines = LogRotation::countLines($this->path);
    }
}
