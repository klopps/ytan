<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\ShelterImport\ShelterImportRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\Overpass\OverpassClient;
use Ytan\Service\ShelterList\ShelterOsmMapper;

/**
 * Loads all shelters and huts of one country from OpenStreetMap (Overpass API)
 * into the staging table shelter_import, where their distances to water are
 * computed from the local water index; the import filters on them. One country per request: its boundary
 * (ISO3166-1 area) is the search area.
 */
final class ShelterOsmImportService
{
    /** The countries of this tool (ISO 3166-1): Scandinavia plus Finland. */
    public const SCANDINAVIA = ['DK', 'NO', 'SE', 'FI'];

    public function __construct(
        private readonly OverpassClient $client,
        private readonly ShelterImportRepository $staging,
        private readonly ShelterOsmMapper $mapper,
        private readonly OverpassThrottle $throttle,
    ) {
    }

    /**
     * @param bool $wait sleep until the next request is allowed instead of refusing (CLI)
     * @return array{fetched:int, skipped:int, inserted:int, updated:int}
     */
    public function import(string $country, bool $wait = false): array
    {
        $country = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new ValidationException('country must be a two-letter ISO 3166-1 code.');
        }

        $this->throttle->pass($wait);
        $answer = $this->client->query(self::query($country));

        $entries = [];
        $fetched = 0;
        foreach ($answer['elements'] as $element) {
            if (!is_array($element)) {
                continue;
            }
            $fetched++;
            $entry = $this->mapper->map($element, $country);
            if ($entry !== null) {
                $entries[$entry['source_ref']] ??= $entry;
            }
        }
        $rows = array_values($entries);
        $saved = $this->staging->upsertMany($rows);

        return [
            'fetched' => $fetched,
            'skipped' => max(0, $fetched - count($rows)),
            'inserted' => $saved['inserted'],
            'updated' => $saved['updated'],
        ];
    }

    /** The Overpass QL query for one country (public for tests). */
    public static function query(string $country): string
    {
        $excluded = ShelterOsmMapper::EXCLUDED_SHELTER_TYPES;

        return '[out:json][timeout:300];'
            . 'area["ISO3166-1"="' . $country . '"]["boundary"="administrative"]->.a;'
            . '(nwr["amenity"="shelter"]["shelter_type"!~"^(' . $excluded . ')$"](area.a);'
            . 'nwr["tourism"~"^(wilderness_hut|alpine_hut)$"](area.a););'
            . 'out tags center;';
    }
}
