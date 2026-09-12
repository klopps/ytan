<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\TranslationKeyMismatchException;
use Ytan\Exception\ValidationException;

/**
 * Reads/writes resources/i18n/{locale}.json for the /translate admin tool
 * (TranslationController) - the write counterpart to Translator's
 * read-only loadFile(). Deliberately separate from Translator itself:
 * Translator is the hot, read-only, per-request path every page load goes
 * through; this is the cold, admin-only, occasional write path, and
 * keeping them apart means a bug here can't affect normal page rendering.
 *
 * $resourcesDir is constructor-injected (mirrors Translator's own
 * $resourcesDir param) so tests can point this at a temp fixture
 * directory instead of ever touching the real resources/i18n files.
 */
final class TranslationRepository
{
    public function __construct(private readonly string $resourcesDir)
    {
    }

    /**
     * @return array<string, string> in file order
     */
    public function load(string $locale): array
    {
        $file = $this->pathFor($locale);
        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Writes both locale files together. Refuses (ValidationException,
     * writing neither file) if the two maps' key sets differ unless
     * $force is true - a key silently present in one locale but not the
     * other is exactly the kind of mistake this tool exists to catch, not
     * to quietly persist. Also refuses any value containing a literal
     * "</script" (case-insensitive): these values end up inside a
     * <script> tag on every page load (window.YTAN_TRANSLATIONS), where
     * such a value would prematurely close the tag and inject markup -
     * see the JSON_HEX_TAG fix at the render side for the other half of
     * this defense; this is the write-side half, so an obviously
     * dangerous value is never persisted in the first place.
     *
     * @param array<string, string> $en
     * @param array<string, string> $de
     */
    public function save(array $en, array $de, bool $force = false): void
    {
        $this->assertNoScriptBreakout($en);
        $this->assertNoScriptBreakout($de);

        if (!$force) {
            $mismatch = $this->diffKeys($en, $de);
            if ($mismatch['only_in_en'] !== [] || $mismatch['only_in_de'] !== []) {
                throw new TranslationKeyMismatchException($mismatch['only_in_en'], $mismatch['only_in_de']);
            }
        }

        $this->write('en', $en);
        $this->write('de', $de);
    }

    /**
     * @param array<string, string> $en
     * @param array<string, string> $de
     * @return array{only_in_en: list<string>, only_in_de: list<string>}
     */
    public function diffKeys(array $en, array $de): array
    {
        return [
            'only_in_en' => array_values(array_diff(array_keys($en), array_keys($de))),
            'only_in_de' => array_values(array_diff(array_keys($de), array_keys($en))),
        ];
    }

    /**
     * @param array<string, string> $map
     */
    private function assertNoScriptBreakout(array $map): void
    {
        foreach ($map as $key => $value) {
            if (is_string($value) && stripos($value, '</script') !== false) {
                throw new ValidationException(
                    "Translation \"$key\" contains \"</script\" - this would break out of the <script> tag every page injects translations into. Rephrase it.",
                    'translation.unsafe_value'
                );
            }
        }
    }

    /**
     * @param array<string, string> $map
     */
    private function write(string $locale, array $map): void
    {
        $json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->pathFor($locale), $json . "\n");
    }

    private function pathFor(string $locale): string
    {
        // basename() defensively, even though callers only ever pass one
        // of the two hardcoded LOCALES constants below - mirrors
        // Translator::loadFile()'s same defensive habit for a locale
        // string that (unlike here) does trace back to request input.
        return $this->resourcesDir . '/' . basename($locale) . '.json';
    }
}
