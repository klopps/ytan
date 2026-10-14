<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ValidationException;

/**
 * The transfer file of the staged lighthouse list entries (table
 * lighthouse_import): the admin tool exports it on one system (dev) and reads
 * it on another (prod), so the lists don't have to be parsed from the PDF
 * everywhere. JSON: {"format": "ytan-lighthouse-list", "version": 1,
 * "exported_at": ..., "entries": [{source, source_key, ...}, ...]}.
 *
 * What an entry does NOT carry: id, poi_id, imported_at - those belong to the
 * database it came from (a POI id of the exporting system means nothing on
 * the importing one); an import keeps the "imported" state of entries that
 * already exist there.
 */
final class LighthouseImportFile
{
    public const FORMAT = 'ytan-lighthouse-list';
    public const VERSION = 1;
    public const MAX_ENTRIES = 20000;

    /** Text columns of an entry => maximum length (the column sizes of lighthouse_import). */
    private const TEXT_FIELDS = [
        'source_key' => 190,
        'source_ref' => 60,
        'dk_no' => 20,
        'international_id' => 30,
        'region' => 100,
        'subregion' => 150,
        'name' => 200,
        'location' => 300,
        'characteristic' => 50,
        'other_signals' => 200,
        'flame_height' => 60,
        'light_range' => 120,
        'appearance' => 300,
    ];

    /**
     * @param list<array<string,mixed>> $rows lighthouse_import rows
     * @return array<string,mixed> the file's content, ready for json_encode
     */
    public static function build(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $entry = ['source' => $row['source']];
            foreach (array_keys(self::TEXT_FIELDS) as $field) {
                $entry[$field] = (string) $row[$field];
            }
            $entry['latitude'] = (float) $row['latitude'];
            $entry['longitude'] = (float) $row['longitude'];
            $entry['remarks'] = $row['remarks'];
            $entry['sector_characteristic'] = $row['sector_characteristic'];
            $entry['sector_missing_range'] = (bool) $row['sector_missing_range'];
            $entries[] = $entry;
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => gmdate('c'),
            'entries' => $entries,
        ];
    }

    /**
     * Checks a decoded file and returns its entries grouped by source, each
     * in the shape LighthouseImportRepository::upsertMany() takes.
     *
     * @param array<string,mixed> $file
     * @return array<string, list<array<string,mixed>>>
     * @throws ValidationException when the file is not a lighthouse list transfer file
     */
    public static function parse(array $file): array
    {
        if (($file['format'] ?? null) !== self::FORMAT) {
            throw new ValidationException('This is not a YTAN lighthouse list file.', 'lighthouse_import.invalid_file');
        }
        if ((int) ($file['version'] ?? 0) !== self::VERSION) {
            throw new ValidationException('Unsupported lighthouse list file version.', 'lighthouse_import.invalid_file');
        }
        $entries = $file['entries'] ?? null;
        if (!is_array($entries) || count($entries) > self::MAX_ENTRIES) {
            throw new ValidationException('The file has no entries or too many.', 'lighthouse_import.invalid_file');
        }

        $bySource = [];
        foreach ($entries as $index => $entry) {
            $bySource[self::source($entry, $index)][] = self::entry($entry, $index);
        }

        return $bySource;
    }

    private static function source(mixed $entry, int|string $index): string
    {
        $source = is_array($entry) ? ($entry['source'] ?? null) : null;
        if (!is_string($source) || preg_match('/^[a-z]{1,10}$/', $source) !== 1) {
            throw new ValidationException("Entry $index has no valid source.", 'lighthouse_import.invalid_file');
        }

        return $source;
    }

    /** @return array<string,mixed> */
    private static function entry(array $entry, int|string $index): array
    {
        $result = [];
        foreach (self::TEXT_FIELDS as $field => $maxLength) {
            $value = $entry[$field] ?? '';
            if (!is_scalar($value) && $value !== null) {
                throw new ValidationException("Entry $index: $field is not text.", 'lighthouse_import.invalid_file');
            }
            $result[$field] = mb_substr((string) $value, 0, $maxLength);
        }
        if ($result['source_key'] === '') {
            throw new ValidationException("Entry $index has no source_key.", 'lighthouse_import.invalid_file');
        }

        foreach (['latitude' => 90.0, 'longitude' => 180.0] as $field => $limit) {
            $value = $entry[$field] ?? null;
            if (!is_numeric($value) || abs((float) $value) > $limit) {
                throw new ValidationException("Entry $index: $field is not a valid coordinate.", 'lighthouse_import.invalid_file');
            }
            $result[$field] = (float) $value;
        }

        $remarks = $entry['remarks'] ?? null;
        $sector = $entry['sector_characteristic'] ?? null;
        $result['remarks'] = is_string($remarks) ? $remarks : null;
        $result['sector_characteristic'] = is_string($sector) && $sector !== '' ? mb_substr($sector, 0, 500) : null;
        $result['sector_missing_range'] = (bool) ($entry['sector_missing_range'] ?? false);

        return $result;
    }
}
