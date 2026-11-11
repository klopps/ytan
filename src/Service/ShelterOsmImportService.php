<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\ShelterImport\ShelterImportRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\Overpass\CountryTiles;
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

    /** HTTP timeout per tile (the default 90 s was too close: all Norwegian shelters took 80 s, 2026-11-11). */
    public const REQUEST_TIMEOUT_SECONDS = 180;

    /**
     * Big countries are loaded in tiles of at most this size (degrees, see
     * CountryTiles); Overpass time grows with the elements tested against the
     * country polygon (measured 2026-11-11: all French clubs 41 s, all
     * Norwegian shelters 80 s, the 8-degree tile with 63 % of them 49-72 s).
     */
    public const TILE_DEGREES = 5.0;

    public function __construct(
        private readonly OverpassClient $client,
        private readonly ShelterImportRepository $staging,
        private readonly ShelterOsmMapper $mapper,
        private readonly OverpassThrottle $throttle,
    ) {
    }

    /**
     * Loads ONE tile of a country (see tiles()); the caller goes on with the
     * next tile until `tile + 1 == tiles`.
     *
     * @param bool $wait sleep until the next request is allowed instead of refusing (CLI, later tiles)
     * @return array{fetched:int, skipped:int, inserted:int, updated:int, tile:int, tiles:int}
     */
    public function import(string $country, bool $wait = false, int $tile = 0): array
    {
        $country = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new ValidationException('country must be a two-letter ISO 3166-1 code.');
        }

        $tiles = self::tiles($country);
        $box = CountryTiles::pick($tiles, $tile);

        $this->throttle->pass($wait);
        $answer = $this->client->query(self::query($country, $box));

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
            'tile' => $tile,
            'tiles' => count($tiles),
        ];
    }

    /**
     * The tiles a country is loaded in (CountryTiles, TILE_DEGREES).
     *
     * @return list<array{south:float, west:float, north:float, east:float}|null>
     */
    public static function tiles(string $country): array
    {
        return CountryTiles::tiles($country, self::TILE_DEGREES);
    }

    /**
     * The Overpass QL query for one country or one tile of it (public for tests).
     *
     * @param array{south:float, west:float, north:float, east:float}|null $tile
     */
    public static function query(string $country, ?array $tile = null): string
    {
        $excluded = ShelterOsmMapper::EXCLUDED_SHELTER_TYPES;
        $area = '(area.a)' . CountryTiles::bbox($tile);

        return '[out:json][timeout:300];'
            . 'area["ISO3166-1"="' . $country . '"]["boundary"="administrative"]->.a;'
            . '(nwr["amenity"="shelter"]["shelter_type"!~"^(' . $excluded . ')$"]' . $area . ';'
            . 'nwr["tourism"~"^(wilderness_hut|alpine_hut)$"]' . $area . ';);'
            . 'out tags center;';
    }
}
