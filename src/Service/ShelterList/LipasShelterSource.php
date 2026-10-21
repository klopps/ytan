<?php

declare(strict_types=1);

namespace Ytan\Service\ShelterList;

use Ytan\Exception\ApiException;

/**
 * Finland: Lipas, the national register of sports and outdoor facilities
 * (University of Jyvaskyla, CC BY 4.0), REST API v2. Types 301 "Laavu, kota
 * tai kammi" (lean-to, Sami-style hut) and 302 "Tupa" (wilderness, booking
 * and day huts). The API pages at most 100 items per request.
 */
final class LipasShelterSource implements ShelterSource
{
    public const API_URL = 'https://api.lipas.fi/v2/sports-sites';
    public const PAGE_SIZE = 100;
    /** Safety stop: about 3,500 entries are expected, i.e. 36 pages. */
    private const MAX_PAGES = 200;

    /** Type code => [kind, label]. */
    private const TYPES = [
        301 => ['shelter', 'Laavu/Kota'],
        302 => ['hut', 'Tupa (Hütte)'],
    ];

    /** @var callable(string):string */
    private $fetch;

    /** @param (callable(string):string)|null $fetch URL => response body, injectable for tests */
    public function __construct(?callable $fetch = null)
    {
        $this->fetch = $fetch ?? [ShelterHttp::class, 'get'];
    }

    public function code(): string
    {
        return 'lipas';
    }

    public function fetch(): array
    {
        $entries = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = json_decode(($this->fetch)(self::requestUrl($page)), true);
            if (!is_array($data) || !is_array($data['items'] ?? null)) {
                throw new ApiException('The Lipas service did not answer as expected.', 502, 'shelter_import.source_bad_answer');
            }
            foreach (self::parseItems($data['items']) as $entry) {
                $entries[$entry['source_ref']] = $entry;
            }
            if ($page >= (int) ($data['pagination']['total-pages'] ?? 0)) {
                break;
            }
        }

        return array_values($entries);
    }

    public static function requestUrl(int $page): string
    {
        return self::API_URL . '?' . http_build_query([
            'type-codes' => implode(',', array_keys(self::TYPES)),
            'statuses' => 'active',
            'page-size' => self::PAGE_SIZE,
            'page' => $page,
        ]);
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string,mixed>>
     */
    public static function parseItems(array $items): array
    {
        $entries = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = $item['lipas-id'] ?? null;
            $type = (int) ($item['type']['type-code'] ?? 0);
            if (!is_int($id) || !isset(self::TYPES[$type]) || ($item['status'] ?? 'active') !== 'active') {
                continue;
            }
            $coordinates = $item['location']['geometries']['features'][0]['geometry']['coordinates'] ?? null;
            if (!is_array($coordinates) || !is_numeric($coordinates[0] ?? null) || !is_numeric($coordinates[1] ?? null)) {
                continue;
            }
            [$kind, $label] = self::TYPES[$type];

            $lines = ['Art: ' . $label];
            $properties = is_array($item['properties'] ?? null) ? $item['properties'] : [];
            if (($properties['free-use?'] ?? null) === true) {
                $lines[] = 'Frei zugänglich';
            }
            if (($properties['toilet?'] ?? null) === true) {
                $lines[] = 'Toilette vorhanden';
            }
            $comment = trim((string) ($item['comment'] ?? ''));
            if ($comment !== '') {
                $lines[] = mb_substr($comment, 0, 400);
            }

            $entries[] = [
                'source' => 'lipas',
                'source_ref' => (string) $id,
                'name' => trim((string) ($item['name'] ?? '')),
                'type_label' => $type === 302 ? 'Tupa' : 'Laavu',
                'kind' => $kind,
                'country' => 'FI',
                'description' => implode("\n", $lines),
                'website' => ShelterHttp::url($item['www'] ?? null),
                // GeoJSON order: longitude, latitude.
                'latitude' => round((float) $coordinates[1], 7),
                'longitude' => round((float) $coordinates[0], 7),
            ];
        }

        return $entries;
    }
}
