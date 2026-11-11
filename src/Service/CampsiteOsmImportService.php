<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\CampsiteImport\CampsiteImportRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\Campsite\CampsiteOsmMapper;
use Ytan\Service\Campsite\CampsiteSurroundings;
use Ytan\Service\Overpass\CountryTiles;
use Ytan\Service\Overpass\OverpassClient;

/**
 * Loads all campsites (tourism=camp_site) of one country from OpenStreetMap
 * (Overpass API) into the staging table campsite_import, where their
 * distances to water are computed from the local water index (only inside its
 * countries - elsewhere they stay empty). One country per request: its
 * boundary (ISO3166-1 area) is the search area.
 */
final class CampsiteOsmImportService
{
    /** HTTP timeout for the query: longer than its [timeout:300], the place/street lookups make it slow. */
    public const REQUEST_TIMEOUT_SECONDS = 330;

    /**
     * Big countries are loaded in tiles of at most this size (degrees, see
     * CountryTiles): one query for all of France was still sending after 6:40
     * minutes (2026-11-11); a dense 3-degree tile (Brittany) took 51 s.
     */
    public const TILE_DEGREES = 3.0;

    public function __construct(
        private readonly OverpassClient $client,
        private readonly CampsiteImportRepository $staging,
        private readonly CampsiteOsmMapper $mapper,
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
        if (!in_array($country, ClubOsmImportService::EUROPE, true)) {
            throw new ValidationException('country must be the ISO 3166-1 code of a European country.');
        }
        $tiles = self::tiles($country);
        $box = CountryTiles::pick($tiles, $tile);

        $this->throttle->pass($wait);
        $answer = $this->client->query(self::query($country, $box));

        // The answer holds the campsites plus places and streets around the unnamed ones.
        $surroundings = new CampsiteSurroundings();
        $campsites = [];
        foreach ($answer['elements'] as $element) {
            if (is_array($element) && !$surroundings->add($element)) {
                $campsites[] = $element;
            }
        }

        $entries = [];
        $fetched = count($campsites);
        foreach ($campsites as $element) {
            $entry = $this->mapper->map($element, $country, $surroundings);
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
     * The Overpass QL query for one country or one tile of it (public for
     * tests): all campsites, and for those without a name the places within
     * CampsiteSurroundings::PLACE_RADIUS_M and the named streets (with their
     * geometry) within STREET_RADIUS_M, to name them by.
     *
     * @param array{south:float, west:float, north:float, east:float}|null $tile
     */
    public static function query(string $country, ?array $tile = null): string
    {
        return '[out:json][timeout:300];'
            . 'area["ISO3166-1"="' . $country . '"]["boundary"="administrative"]->.a;'
            . 'nwr["tourism"="camp_site"](area.a)' . CountryTiles::bbox($tile) . '->.c;'
            . '.c out tags center;'
            . 'nwr.c[!"name"]->.u;'
            . 'node(around.u:' . CampsiteSurroundings::PLACE_RADIUS_M . ')'
            . '["place"~"^(' . implode('|', CampsiteSurroundings::PLACE_TYPES) . ')$"]["name"];'
            . 'out body;'
            . 'way(around.u:' . CampsiteSurroundings::STREET_RADIUS_M . ')["highway"]["highway"!~"^motorway"]["name"];'
            . 'out tags geom;';
    }
}
