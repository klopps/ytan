<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Merges a freshly deployed locale file into the one already on the server -
 * a three-way merge against the BASELINE, the file as it was deployed last
 * time (kept on the server under storage/i18n-baseline/):
 *
 * - The deployed file decides WHICH keys exist (and their order): new keys
 *   arrive with their deployed value, keys it no longer has are dropped.
 * - A value changed in the repository reaches the server (until 2026-11-16
 *   the server's value always won, so a changed text of an existing key -
 *   e.g. the credits' licence lines - never arrived).
 * - A value edited on the server (translation tool) and unchanged in the
 *   repository since the last deploy stays.
 * - Changed on both sides: the deployed value wins, the key is reported as a
 *   conflict so the server edit can be redone by hand.
 * - No baseline for a key (first deploy with this merger): the deployed
 *   value wins - nothing tells a server edit from an old text.
 */
final class TranslationMerger
{
    /**
     * @param array<string, string> $incoming deployed file
     * @param array<string, string> $existing file on the server (may be empty)
     * @param array<string, string> $baseline file deployed last time (empty = unknown)
     * @return array{merged: array<string, string>, updated: list<string>, kept_server: list<string>, conflicts: list<string>}
     */
    public static function merge(array $incoming, array $existing, array $baseline = []): array
    {
        $merged = [];
        $updated = [];
        $keptServer = [];
        $conflicts = [];
        foreach ($incoming as $key => $value) {
            $key = (string) $key;
            $server = $existing[$key] ?? null;
            if (!is_string($server) || $server === $value) {
                $merged[$key] = $value;
                continue;
            }
            $base = $baseline[$key] ?? null;
            if (is_string($base) && $server !== $base && $value === $base) {
                // edited on the server, unchanged in the repository
                $merged[$key] = $server;
                $keptServer[] = $key;
            } else {
                $merged[$key] = $value;
                if (is_string($base) && $server !== $base) {
                    $conflicts[] = $key;
                } else {
                    $updated[] = $key;
                }
            }
        }

        return ['merged' => $merged, 'updated' => $updated, 'kept_server' => $keptServer, 'conflicts' => $conflicts];
    }

    /**
     * Merges every {locale}.json in $incomingDir into $targetDir, stores the
     * incoming file as the new baseline in $baselineDir and removes the
     * incoming files afterwards.
     *
     * @return array<string, array{added: int, removed: int, updated: int, kept_server: int, conflicts: list<string>}> per locale
     */
    public static function mergeDirectory(string $incomingDir, string $targetDir, string $baselineDir): array
    {
        $report = [];
        foreach ([$targetDir, $baselineDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create $dir");
            }
        }
        foreach (glob(rtrim($incomingDir, '/\\') . '/*.json') ?: [] as $file) {
            $raw = (string) file_get_contents($file);
            $incoming = json_decode($raw, true);
            if (!is_array($incoming)) {
                throw new \RuntimeException("$file is not a valid JSON object - nothing merged for it");
            }
            $target = rtrim($targetDir, '/\\') . '/' . basename($file);
            $existing = self::read($target, true);
            $baselineFile = rtrim($baselineDir, '/\\') . '/' . basename($file);
            $baseline = self::read($baselineFile, false);
            $result = self::merge($incoming, $existing, $baseline);
            $json = json_encode($result['merged'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || file_put_contents($target, $json . "\n") === false) {
                throw new \RuntimeException("Cannot write $target");
            }
            if (file_put_contents($baselineFile, $raw) === false) {
                throw new \RuntimeException("Cannot write $baselineFile");
            }
            unlink($file);
            $report[basename($file, '.json')] = [
                'added' => count(array_diff_key($result['merged'], $existing)),
                'removed' => count(array_diff_key($existing, $result['merged'])),
                'updated' => count($result['updated']),
                'kept_server' => count($result['kept_server']),
                'conflicts' => $result['conflicts'],
            ];
        }
        @rmdir($incomingDir);

        return $report;
    }

    /** @return array<string, string> */
    private static function read(string $file, bool $strict): array
    {
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            if ($strict) {
                throw new \RuntimeException("$file is not a valid JSON object - left untouched");
            }

            return [];
        }

        return $decoded;
    }
}
