<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\CampsiteImport\CampsiteImportRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\Campsite\CampsiteOsmMapper;
use Ytan\Service\Campsite\CampsiteSurroundings;
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

    public function __construct(
        private readonly OverpassClient $client,
        private readonly CampsiteImportRepository $staging,
        private readonly CampsiteOsmMapper $mapper,
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
        if (!in_array($country, ClubOsmImportService::EUROPE, true)) {
            throw new ValidationException('country must be the ISO 3166-1 code of a European country.');
        }

        $this->throttle->pass($wait);
        $answer = $this->client->query(self::query($country));

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
        ];
    }

    /**
     * The Overpass QL query for one country (public for tests): all campsites,
     * and for those without a name the places within
     * CampsiteSurroundings::PLACE_RADIUS_M and the named streets (with their
     * geometry) within STREET_RADIUS_M, to name them by.
     */
    public static function query(string $country): string
    {
        return '[out:json][timeout:300];'
            . 'area["ISO3166-1"="' . $country . '"]["boundary"="administrative"]->.a;'
            . 'nwr["tourism"="camp_site"](area.a)->.c;'
            . '.c out tags center;'
            . 'nwr.c[!"name"]->.u;'
            . 'node(around.u:' . CampsiteSurroundings::PLACE_RADIUS_M . ')'
            . '["place"~"^(' . implode('|', CampsiteSurroundings::PLACE_TYPES) . ')$"]["name"];'
            . 'out body;'
            . 'way(around.u:' . CampsiteSurroundings::STREET_RADIUS_M . ')["highway"]["highway"!~"^motorway"]["name"];'
            . 'out tags geom;';
    }
}
