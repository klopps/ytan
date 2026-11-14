<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * The tide sources' small JSON files in storage/tide-cache/: valid for
 * MAX_AGE_SECONDS (30 days, decided 2026-11-14 - tide predictions are
 * astronomical and change about once a year), a failed download is
 * remembered for FAIL_SECONDS so an outage does not slow every Routencheck.
 * Files are written to a temp name and renamed (no half files for a
 * parallel request).
 */
final class TideCache
{
    public const MAX_AGE_SECONDS = 30 * 86400;
    public const FAIL_SECONDS = 3600;

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * Fresh content, ['failed' => t] for a recent failure, null = nothing usable.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $file): ?array
    {
        $path = $this->dir . '/' . $file;
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }
        $maxAge = isset($data['failed']) ? self::FAIL_SECONDS : self::MAX_AGE_SECONDS;

        return time() - $mtime <= $maxAge ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function write(string $file, array $data): array
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $temp = $this->dir . '/' . $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, json_encode($data)) !== false) {
            @rename($temp, $this->dir . '/' . $file);
        }

        return $data;
    }

    public function failed(string $file, string $what): void
    {
        error_log('YTAN: tide data ' . $what . ' could not be loaded');
        $this->write($file, ['failed' => time()]);
    }

    /** A file name part for an id from outside (station names with spaces, slashes ...). */
    public static function safe(string $id): string
    {
        $plain = preg_replace('/[^A-Za-z0-9_-]/', '_', $id) ?? 'x';

        return substr($plain, 0, 40) . '-' . substr(md5($id), 0, 8);
    }
}
