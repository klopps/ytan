<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\CampsiteImport\CampsiteImportRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\Campsite\CampsiteOsmMapper;

/**
 * The transfer file of the staged campsites (table campsite_import): exported
 * on one system (dev) and read on another (prod). JSON: {"format":
 * "ytan-campsite-list", "version": 1, "exported_at": ..., "entries": [{source,
 * source_ref, name, category, country, place, description, website, tents,
 * caravans, cabins, latitude, longitude, water_checked, sea_m, river_m,
 * lake_m}, ...]}. The distances to water are carried so the receiving system
 * need not compute them (water_checked false = not computed there either).
 * id, poi_id and imported_at belong to the exporting database and are not
 * carried.
 */
final class CampsiteImportFile
{
    public const FORMAT = 'ytan-campsite-list';
    public const VERSION = 1;
    public const MAX_ENTRIES = 100000;

    private const TEXT_FIELDS = [
        'source' => 10, 'source_ref' => 40, 'name' => 200, 'place' => 120, 'description' => 1000, 'website' => 500,
    ];

    private const FLAGS = ['tents', 'caravans', 'cabins'];

    /**
     * @param list<array<string,mixed>> $rows campsite_import rows
     * @return array<string,mixed>
     */
    public static function build(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $entry = [
                'source' => (string) $row['source'],
                'source_ref' => (string) $row['source_ref'],
                'name' => (string) $row['name'],
                'category' => (string) $row['category'],
                'country' => (string) $row['country'],
                'place' => (string) $row['place'],
                'description' => (string) $row['description'],
                'website' => (string) $row['website'],
            ];
            foreach (self::FLAGS as $flag) {
                $entry[$flag] = $row[$flag] === null ? null : (int) $row[$flag];
            }
            $entries[] = $entry + [
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'water_checked' => $row['water_checked_at'] !== null,
            ] + array_combine(
                array_values(CampsiteImportRepository::DISTANCE_COLUMNS),
                array_values(CampsiteImportRepository::distancesOf($row))
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
     * @return list<array<string,mixed>> validated entries, ready for CampsiteImportRepository::upsertMany()
     */
    public static function parse(array $data): array
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new ValidationException('This is not a campsite transfer file.');
        }
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new ValidationException('Unsupported version of the campsite transfer file.');
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
            if ($clean['source'] === '' || $clean['source_ref'] === '' || $clean['name'] === '') {
                throw new ValidationException("Entry $i: source, source_ref and name are required.");
            }
            $category = $entry['category'] ?? '';
            if (!in_array($category, [CampsiteOsmMapper::CATEGORY_CAMPSITE, CampsiteOsmMapper::CATEGORY_BASIC], true)) {
                throw new ValidationException("Entry $i: category must be campsite or basic.");
            }
            $country = $entry['country'] ?? '';
            if (!is_string($country) || !preg_match('/^[A-Z]{2}$/', $country)) {
                throw new ValidationException("Entry $i: country must be a two-letter code.");
            }
            if ($clean['website'] !== '' && !preg_match('#^https?://#i', $clean['website'])) {
                throw new ValidationException("Entry $i: website must be an http(s) URL.");
            }
            foreach (self::FLAGS as $flag) {
                $value = $entry[$flag] ?? null;
                if ($value !== null && $value !== 0 && $value !== 1) {
                    throw new ValidationException("Entry $i: $flag must be null, 0 or 1.");
                }
                $clean[$flag] = $value;
            }
            foreach (['latitude' => 90, 'longitude' => 180] as $field => $limit) {
                if (!isset($entry[$field]) || !is_numeric($entry[$field]) || abs((float) $entry[$field]) > $limit) {
                    throw new ValidationException("Entry $i: $field is not a valid coordinate.");
                }
                $clean[$field] = (float) $entry[$field];
            }
            $clean['category'] = $category;
            $clean['country'] = $country;
            $clean['water_checked'] = ($entry['water_checked'] ?? false) === true;
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
