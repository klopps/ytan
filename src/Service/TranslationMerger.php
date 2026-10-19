<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Merges a freshly deployed locale file into the one already on the server.
 *
 * The deployed file decides WHICH keys exist (and their order); the server's
 * file decides the VALUES of the keys both have, so translations edited on
 * the server survive a deploy. New keys arrive with their deployed value,
 * keys the deployed file no longer has are dropped.
 */
final class TranslationMerger
{
    /**
     * @param array<string, string> $incoming deployed file
     * @param array<string, string> $existing file on the server (may be empty)
     * @return array<string, string>
     */
    public static function merge(array $incoming, array $existing): array
    {
        $merged = [];
        foreach ($incoming as $key => $value) {
            $key = (string) $key;
            $merged[$key] = array_key_exists($key, $existing) && is_string($existing[$key])
                ? $existing[$key]
                : $value;
        }

        return $merged;
    }

    /**
     * Merges every {locale}.json in $incomingDir into $targetDir and removes
     * the incoming files afterwards.
     *
     * @return array<string, array{added: int, removed: int, kept: int}> per locale
     */
    public static function mergeDirectory(string $incomingDir, string $targetDir): array
    {
        $report = [];
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException("Cannot create $targetDir");
        }
        foreach (glob(rtrim($incomingDir, '/\\') . '/*.json') ?: [] as $file) {
            $incoming = json_decode((string) file_get_contents($file), true);
            if (!is_array($incoming)) {
                throw new \RuntimeException("$file is not a valid JSON object - nothing merged for it");
            }
            $target = rtrim($targetDir, '/\\') . '/' . basename($file);
            $existing = [];
            if (is_file($target)) {
                $decoded = json_decode((string) file_get_contents($target), true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException("$target is not a valid JSON object - left untouched");
                }
                $existing = $decoded;
            }
            $merged = self::merge($incoming, $existing);
            $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || file_put_contents($target, $json . "\n") === false) {
                throw new \RuntimeException("Cannot write $target");
            }
            unlink($file);
            $report[basename($file, '.json')] = [
                'added' => count(array_diff_key($merged, $existing)),
                'removed' => count(array_diff_key($existing, $merged)),
                'kept' => count(array_intersect_key($merged, $existing)),
            ];
        }
        @rmdir($incomingDir);

        return $report;
    }
}
