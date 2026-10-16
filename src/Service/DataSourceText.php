<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * The language-dependent texts of a data_source row (license, attribution).
 * The table holds one fixed text per source; the i18n files can translate
 * it per source code (`data_source.{code}.{field}`). A source or field
 * without a key keeps the table's value.
 *
 * The keys are written out literally (no `'data_source.' . $code`) so that
 * TranslationUsageScanner finds them. public/js/poi.js's
 * dataSourceTranslations() mirrors this list for the lighthouse InfoWindow.
 */
final class DataSourceText
{
    /**
     * @param array<string, mixed> $source a data_source row (code, name, url, license, license_url, attribution)
     * @param callable(string): string $t Translator::t() - returns the key itself when it has no translation
     * @return array<string, mixed>
     */
    public static function localize(array $source, callable $t): array
    {
        $texts = match ($source['code'] ?? null) {
            'dk' => ['license' => $t('data_source.dk.license'), 'attribution' => $t('data_source.dk.attribution')],
            'nga' => ['license' => $t('data_source.nga.license')],
            'ngaapi' => ['license' => $t('data_source.ngaapi.license')],
            'osm' => ['attribution' => $t('data_source.osm.attribution')],
            default => [],
        };

        foreach ($texts as $field => $text) {
            if (!str_starts_with($text, 'data_source.')) {
                $source[$field] = $text;
            }
        }

        return $source;
    }
}
