<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ValidationException;

/**
 * The transfer file of the staged shelters (table shelter_import): exported on
 * one system (dev) and read on another (prod). JSON: {"format":
 * "ytan-shelter-list", "version": 2, "exported_at": ..., "entries": [{source,
 * source_ref, name, kind, type_label, place, country, description, website, latitude, longitude,
 * water_checked, sea_m, river_m, lake_m}, ...]}. The distances to water are
 * carried so the receiving system need not compute them (water_checked false =
 * not computed there either). Version 1 files (Overpass water check) are read
 * without their water fields. id, poi_id and imported_at belong to the
 * exporting database and are not carried.
 */
final class ShelterImportFile
{
    public const FORMAT = 'ytan-shelter-list';
    public const VERSION = 2;
    public const MAX_ENTRIES = 50000;

    private const TEXT_FIELDS = [
        'source' => 10, 'source_ref' => 40, 'name' => 200, 'description' => 1000, 'website' => 500,
        'type_label' => 60, 'place' => 120,
    ];

    /**
     * @param list<array<string,mixed>> $rows shelter_import rows
     * @return array<string,mixed>
     */
    public static function build(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'source' => (string) $row['source'],
                'source_ref' => (string) $row['source_ref'],
                'name' => (string) $row['name'],
                'kind' => (string) $row['kind'],
                'type_label' => (string) $row['type_label'],
                'place' => (string) $row['place'],
                'country' => (string) $row['country'],
                'description' => (string) $row['description'],
                'website' => (string) $row['website'],
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'water_checked' => $row['water_checked_at'] !== null,
            ] + array_combine(
                array_values(\Ytan\Domain\ShelterImport\ShelterImportRepository::DISTANCE_COLUMNS),
                array_values(\Ytan\Domain\ShelterImport\ShelterImportRepository::distancesOf($row))
            );
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => gmdate('c'),
            'entries' => $entries,
        ];
    }

    /**
     * @param array<string,mixed> $data decoded file
     * @return list<array<string,mixed>> validated entries, ready for ShelterImportRepository::upsertMany()
     */
    public static function parse(array $data): array
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new ValidationException('This is not a shelter transfer file.');
        }
        $version = $data['version'] ?? null;
        if ($version !== 1 && $version !== self::VERSION) {
            throw new ValidationException('Unsupported version of the shelter transfer file.');
        }
        $entries = $data['entries'] ?? null;
        if (!is_array($entries) || count($entries) > self::MAX_ENTRIES) {
            throw new ValidationException('The file must contain at most ' . self::MAX_ENTRIES . ' entries.');
        }

        $result = [];
        foreach ($entries as $i => $entry) {
            if (!is_array($entry)) {
                throw new ValidationException("Entry $i is not an object.");
            }
            $clean = [];
            foreach (self::TEXT_FIELDS as $field => $max) {
                $value = $entry[$field] ?? '';
                if (!is_string($value) || mb_strlen($value) > $max) {
                    throw new ValidationException("Entry $i: $field must be a text of at most $max characters.");
                }
                $clean[$field] = $value;
            }
            if ($clean['source'] === '' || $clean['source_ref'] === '') {
                throw new ValidationException("Entry $i: source and source_ref are required.");
            }
            $kind = $entry['kind'] ?? '';
            if (!in_array($kind, ['shelter', 'hut'], true)) {
                throw new ValidationException("Entry $i: kind must be shelter or hut.");
            }
            $country = $entry['country'] ?? '';
            if (!is_string($country) || !preg_match('/^[A-Z]{2}$/', $country)) {
                throw new ValidationException("Entry $i: country must be a two-letter code.");
            }
            if ($clean['website'] !== '' && !preg_match('#^https?://#i', $clean['website'])) {
                throw new ValidationException("Entry $i: website must be an http(s) URL.");
            }
            foreach (['latitude' => 90, 'longitude' => 180] as $field => $limit) {
                if (!isset($entry[$field]) || !is_numeric($entry[$field]) || abs((float) $entry[$field]) > $limit) {
                    throw new ValidationException("Entry $i: $field is not a valid coordinate.");
                }
                $clean[$field] = (float) $entry[$field];
            }
            $clean['kind'] = $kind;
            $clean['country'] = $country;
            $clean['water_checked'] = $version === self::VERSION && ($entry['water_checked'] ?? false) === true;
            foreach (['sea_m', 'river_m', 'lake_m'] as $field) {
                $meters = $clean['water_checked'] ? ($entry[$field] ?? null) : null;
                if ($meters !== null && (!is_int($meters) || $meters < 0)) {
                    throw new ValidationException("Entry $i: $field must be null or a number of metres.");
                }
                $clean[$field] = $meters;
            }
            $result[] = $clean;
        }

        return $result;
    }
}
