<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ValidationException;

/**
 * The transfer file of the staged clubs (table club_import): exported on one
 * system (dev) and read on another (prod). JSON: {"format": "ytan-club-list",
 * "version": 1, "exported_at": ..., "entries": [{osm_ref, name, kind, country,
 * city, address, website, latitude, longitude}, ...]}. id, poi_id and
 * imported_at belong to the exporting database and are not carried.
 */
final class ClubImportFile
{
    public const FORMAT = 'ytan-club-list';
    public const VERSION = 1;
    public const MAX_ENTRIES = 50000;

    private const TEXT_FIELDS = [
        'osm_ref' => 30, 'name' => 200, 'city' => 150, 'address' => 300, 'website' => 500,
    ];

    /**
     * @param list<array<string,mixed>> $rows club_import rows
     * @return array<string,mixed>
     */
    public static function build(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'osm_ref' => (string) $row['osm_ref'],
                'name' => (string) $row['name'],
                'kind' => (string) $row['kind'],
                'country' => (string) $row['country'],
                'city' => (string) $row['city'],
                'address' => (string) $row['address'],
                'website' => (string) $row['website'],
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
            ];
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
     * @return list<array<string,mixed>> validated entries, ready for ClubImportRepository::upsertMany()
     */
    public static function parse(array $data): array
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new ValidationException('This is not a club transfer file.');
        }
        if (($data['version'] ?? null) !== self::VERSION) {
            throw new ValidationException('Unsupported version of the club transfer file.');
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
            if ($clean['osm_ref'] === '' || trim($clean['name']) === '') {
                throw new ValidationException("Entry $i: osm_ref and name are required.");
            }
            $kind = $entry['kind'] ?? '';
            if (!in_array($kind, ['canoe', 'rowing', 'both'], true)) {
                throw new ValidationException("Entry $i: kind must be canoe, rowing or both.");
            }
            $country = $entry['country'] ?? '';
            if (!is_string($country) || !preg_match('/^[A-Z]{2}$/', $country)) {
                throw new ValidationException("Entry $i: country must be a two-letter code.");
            }
            $website = $clean['website'];
            if ($website !== '' && !preg_match('#^https?://#i', $website)) {
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
            $result[] = $clean;
        }

        return $result;
    }
}
