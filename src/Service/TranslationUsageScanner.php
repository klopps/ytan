<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Builds a "translation key -> where it's used" reverse index by regex-
 * scanning the app's own source tree, for the /translate admin tool
 * (TranslationController). Every t()/$t() call site in this app uses a
 * literal string key (confirmed by grep across the whole codebase - never
 * a variable/computed key), so a plain regex scan can reliably build a
 * complete, always-accurate index with no manual upkeep - unlike e.g. a
 * hand-maintained "key belongs to this screen" mapping, which would go
 * stale the moment a string moves between files.
 *
 * Stateless and re-scans on every call - at ~14 files and a few hundred
 * call sites this is cheap enough per-request that no caching is needed.
 */
final class TranslationUsageScanner
{
    private const JS_PATTERN = '/\bt\(\s*[\'"]([\w.]+)[\'"]/';
    private const PHP_PATTERN = '/\$t\(\s*[\'"]([\w.]+)[\'"]/';

    public function __construct(private readonly string $rootDir)
    {
    }

    /**
     * @return array<string, list<array{file: string, line: int}>>
     */
    public function scan(): array
    {
        $index = [];

        foreach ($this->jsFiles() as $file) {
            $this->scanFile($file, self::JS_PATTERN, $index);
        }
        foreach ($this->phpFiles() as $file) {
            $this->scanFile($file, self::PHP_PATTERN, $index);
        }

        return $index;
    }

    /**
     * @return list<string>
     */
    private function jsFiles(): array
    {
        return glob($this->rootDir . '/public/js/*.js') ?: [];
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        return glob($this->rootDir . '/templates/*.php') ?: [];
    }

    /**
     * @param array<string, list<array{file: string, line: int}>> $index
     */
    private function scanFile(string $absolutePath, string $pattern, array &$index): void
    {
        $lines = file($absolutePath);
        if ($lines === false) {
            return;
        }

        $relativePath = ltrim(str_replace('\\', '/', substr($absolutePath, strlen($this->rootDir))), '/');

        foreach ($lines as $lineNumber => $lineContent) {
            if (preg_match_all($pattern, $lineContent, $matches) === 0) {
                continue;
            }

            foreach ($matches[1] as $key) {
                $index[$key][] = ['file' => $relativePath, 'line' => $lineNumber + 1];
            }
        }
    }
}
