<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\ClubImport\ClubImportRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ValidationException;
use Ytan\Service\ClubList\ClubOsmMapper;
use Ytan\Service\Overpass\CountryTiles;
use Ytan\Service\Overpass\OverpassClient;

/**
 * Loads the canoe/kayak and rowing clubs of one country from OpenStreetMap
 * (Overpass API) into the staging table club_import. One country per
 * request: its boundary (ISO3166-1 area) is the search area, which also
 * gives every entry its country.
 *
 * Shares the request spacing file with OsmImportService (the public Overpass
 * instances are shared, the policy asks for a pause between requests). The
 * admin tool refuses a request that comes too soon; the CLI script waits.
 */
final class ClubOsmImportService
{
    /** ISO 3166-1 codes of the European countries and territories loaded by bin/import-clubs-osm.php. */
    public const EUROPE = [
        'AL', 'AD', 'AT', 'BY', 'BE', 'BA', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FO', 'FI', 'FR', 'DE', 'GI', 'GR',
        'HU', 'IS', 'IE', 'IT', 'XK', 'LV', 'LI', 'LT', 'LU', 'MT', 'MD', 'MC', 'ME', 'NL', 'MK', 'NO', 'PL', 'PT',
        'RO', 'SM', 'RS', 'SK', 'SI', 'ES', 'SE', 'CH', 'UA', 'GB',
    ];

    /** HTTP timeout per tile (the default 90 s was too close: all Norwegian shelters took 80 s, 2026-11-11). */
    public const REQUEST_TIMEOUT_SECONDS = 180;

    /**
     * Big countries are loaded in tiles of at most this size (degrees, see
     * CountryTiles); Overpass time grows with the elements tested against the
     * country polygon (measured 2026-11-11: all French clubs 41 s, all
     * Norwegian shelters 80 s, the 8-degree tile with 63 % of them 49-72 s).
     */
    public const TILE_DEGREES = 8.0;

    /** @var callable():int */
    private $clock;

    /** @param (callable():int)|null $clock current Unix time, injectable for tests */
    public function __construct(
        private readonly OverpassClient $client,
        private readonly ClubImportRepository $staging,
        private readonly ClubOsmMapper $mapper,
        private readonly string $throttleFile,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
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

        $this->throttle($wait);
        $answer = $this->client->query(self::query($country, $box));

        $entries = [];
        foreach ($answer['elements'] as $element) {
            $entry = is_array($element) ? $this->mapper->map($element, $country) : null;
            if ($entry !== null) {
                $entries[$entry['osm_ref']] = $entry;
            }
        }
        $saved = $this->staging->upsertMany(array_values($entries));

        return [
            'fetched' => count($answer['elements']),
            'skipped' => count($answer['elements']) - count($entries),
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
        $sport = '["sport"~"' . ClubOsmMapper::SPORT_PATTERN . '"]';
        $area = '(area.a)' . CountryTiles::bbox($tile);

        return '[out:json][timeout:300];area["ISO3166-1"="' . $country . '"]["boundary"="administrative"]->.a;('
            . 'nwr' . $sport . '["club"]' . $area . ';'
            . 'nwr' . $sport . '["leisure"="sports_club"]' . $area . ';'
            . 'nwr' . $sport . '["amenity"="club"]' . $area . ';'
            . ');out tags center;';
    }

    private function throttle(bool $wait): void
    {
        $now = ($this->clock)();
        $last = is_file($this->throttleFile) ? (int) @file_get_contents($this->throttleFile) : 0;
        $remaining = $last + OsmImportService::MIN_SECONDS_BETWEEN_REQUESTS - $now;
        if ($remaining > 0) {
            if (!$wait) {
                throw new ApiException("The Overpass API is asked for at most one area per " . OsmImportService::MIN_SECONDS_BETWEEN_REQUESTS . " seconds - please wait $remaining s.", 429, 'lighthouse_import.osm_too_soon');
            }
            sleep($remaining);
            $now += $remaining;
        }

        $dir = dirname($this->throttleFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->throttleFile, (string) $now);
    }
}
