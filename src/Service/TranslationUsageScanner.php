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
     * Includes templates/partials/*.php (the shared /admin/* page shell,
     * templates/partials/admin-shell-header.php in particular, is the only
     * current occupant) alongside the top-level templates/*.php - a plain
     * glob('templates/*.php') doesn't descend into subdirectories, which
     * silently made every t()/$t() call in that one file show up as
     * "not referenced anywhere" here despite being real, live usage.
     *
     * @return list<string>
     */
    private function phpFiles(): array
    {
        return array_merge(
            glob($this->rootDir . '/templates/*.php') ?: [],
            glob($this->rootDir . '/templates/partials/*.php') ?: [],
        );
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

    /**
     * Finds the placeholder names (e.g. "app" for a call passing
     * {'app' => $appName}) a key's call sites actually supply, for the
     * /translate tool to show next to a key like "about.p1" - a translator
     * writing "{app}" into a translation has no other way to know that
     * name is available, or that it's spelled "app" and not "appName"/
     * "app_name", without reading the call site's source directly.
     *
     * A separate pass from scan() (rather than folded into it) because it
     * needs each call's full argument list, which can span multiple lines
     * (see route.js's t('route.info.recorded', {...}) - scan()'s line-by-
     * line matching is enough for just the key, not for this) - reads
     * whole files instead of one line at a time and locates each call's
     * matching closing paren by tracking bracket depth. Deliberately
     * simple/best-effort like scan() itself: a key passed as a variable
     * instead of an inline object literal (rare - grep confirms every
     * *inline vars object* in this app is a literal, even on the handful
     * of call sites whose *key* argument is itself a variable) yields no
     * placeholder names for that call site rather than an error - the tool
     * degrades to showing nothing extra, not a wrong answer.
     *
     * @return array<string, list<string>>
     */
    public function scanPlaceholders(): array
    {
        $placeholders = [];

        foreach ($this->jsFiles() as $file) {
            $this->scanFilePlaceholders($file, self::JS_PATTERN, $placeholders);
        }
        foreach ($this->phpFiles() as $file) {
            $this->scanFilePlaceholders($file, self::PHP_PATTERN, $placeholders);
        }

        foreach ($placeholders as $key => $names) {
            $unique = array_values(array_unique($names));
            sort($unique);
            $placeholders[$key] = $unique;
        }

        return $placeholders;
    }

    /**
     * @param array<string, list<string>> $placeholders
     */
    private function scanFilePlaceholders(string $absolutePath, string $pattern, array &$placeholders): void
    {
        $content = file_get_contents($absolutePath);
        if ($content === false || preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return;
        }

        foreach ($matches[0] as $i => $fullMatch) {
            $key = $matches[1][$i][0];
            $openParenOffset = strpos($content, '(', $fullMatch[1]);
            if ($openParenOffset === false) {
                continue;
            }

            $callArgs = $this->extractBalanced($content, $openParenOffset);
            if ($callArgs === null) {
                continue;
            }

            $varsArg = $this->secondArgument($callArgs);
            if ($varsArg === '') {
                continue;
            }

            foreach ($this->extractVariableNames($varsArg) as $name) {
                $placeholders[$key][] = $name;
            }
        }
    }

    /**
     * Returns everything between the '(' at $openParenOffset and its
     * matching ')', by tracking (/[/{ vs )/]/} depth - not distinguishing
     * bracket types (a mismatched pair like "(]" would be accepted) is a
     * deliberate simplification, since the source being scanned is always
     * valid JS/PHP that already balances correctly; this only needs to
     * find *where* the call ends, not validate syntax.
     */
    private function extractBalanced(string $content, int $openParenOffset): ?string
    {
        $depth = 0;
        $length = strlen($content);
        for ($i = $openParenOffset; $i < $length; $i++) {
            $ch = $content[$i];
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $openParenOffset + 1, $i - $openParenOffset - 1);
                }
            }
        }

        return null;
    }

    /**
     * Given a call's full argument list (everything between t(/$t('s outer
     * parens), returns whatever comes after the first top-level comma -
     * the vars argument, skipping past the key argument without needing to
     * know its own length. Empty string if there's no second argument.
     */
    private function secondArgument(string $callArgs): string
    {
        $depth = 0;
        $length = strlen($callArgs);
        for ($i = 0; $i < $length; $i++) {
            $ch = $callArgs[$i];
            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                return trim(substr($callArgs, $i + 1));
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private function extractVariableNames(string $varsArg): array
    {
        $names = [];

        // JS object literal keys ({ error: ..., app: ... }) - the
        // '[{,]' lookbehind-by-hand (checked via the pattern itself, not a
        // real lookbehind) keeps this from matching a ternary's "a : b" or
        // similar, which would otherwise false-positive on "a" whenever a
        // vars *value* happens to contain one.
        if (preg_match_all('/[{,]\s*(\w+)\s*:/', $varsArg, $m) > 0) {
            $names = array_merge($names, $m[1]);
        }

        // PHP array literal keys (['app' => ..., 'error' => ...]).
        if (preg_match_all('/[\[,]\s*[\'"](\w+)[\'"]\s*=>/', $varsArg, $m) > 0) {
            $names = array_merge($names, $m[1]);
        }

        return $names;
    }
}
