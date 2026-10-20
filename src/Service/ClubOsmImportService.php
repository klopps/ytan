<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\ClubImport\ClubImportRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ValidationException;
use Ytan\Service\ClubList\ClubOsmMapper;
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
     * @param bool $wait sleep until the next request is allowed instead of refusing (CLI)
     * @return array{fetched:int, skipped:int, inserted:int, updated:int}
     */
    public function import(string $country, bool $wait = false): array
    {
        $country = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new ValidationException('country must be a two-letter ISO 3166-1 code.');
        }

        $this->throttle($wait);

        $sport = '["sport"~"' . ClubOsmMapper::SPORT_PATTERN . '"]';
        $area = '(area.a)';
        $answer = $this->client->query(
            '[out:json][timeout:300];area["ISO3166-1"="' . $country . '"]["boundary"="administrative"]->.a;('
            . 'nwr' . $sport . '["club"]' . $area . ';'
            . 'nwr' . $sport . '["leisure"="sports_club"]' . $area . ';'
            . 'nwr' . $sport . '["amenity"="club"]' . $area . ';'
            . ');out tags center;'
        );

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
        ];
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
