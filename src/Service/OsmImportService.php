<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ValidationException;
use Ytan\Service\LighthouseList\OsmLightMapper;
use Ytan\Service\Overpass\OverpassClient;

/**
 * Loads the lights of one area from OpenStreetMap (Overpass API, OpenSeaMap's
 * seamark tags) into the staging table of the lighthouse list import as
 * source "osm" - from there the admin tool filters, selects and imports them
 * like the entries of the PDF lists.
 *
 * Meant for deliberate, area-wise use by an admin, not for bulk or periodic
 * fetching (the public Overpass instances are shared): the area is limited
 * and consecutive requests are at least MIN_SECONDS_BETWEEN_REQUESTS apart
 * (the usage policy asks to wait 30 s after an overload answer).
 */
final class OsmImportService
{
    public const MIN_SECONDS_BETWEEN_REQUESTS = 30;
    /** Largest area of a request in square degrees (10° x 10°). */
    public const MAX_AREA_SQUARE_DEGREES = 100.0;

    /** @var callable():int */
    private $clock;

    /**
     * @param string $throttleFile where the time of the last request is kept
     * @param (callable():int)|null $clock current Unix time, injectable for tests
     */
    public function __construct(
        private readonly OverpassClient $client,
        private readonly LighthouseImportRepository $staging,
        private readonly OsmLightMapper $mapper,
        private readonly string $throttleFile,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param string $label name of the area, stored as the entries' region
     * @return array{fetched:int, skipped:int, inserted:int, updated:int}
     */
    public function import(float $south, float $west, float $north, float $east, string $label): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 100) {
            throw new ValidationException('label must be 1-100 characters.');
        }
        if ($south < -90 || $north > 90 || $west < -180 || $east > 180 || $south >= $north || $west >= $east) {
            throw new ValidationException('The area is not a valid bounding box (south < north, west < east).');
        }
        if (($north - $south) * ($east - $west) > self::MAX_AREA_SQUARE_DEGREES) {
            throw new ValidationException('The area is too large (at most 10 x 10 degrees per request).');
        }

        $this->throttle();

        // One equality clause per seamark type, not one regex: measured on the
        // main instance the union answers in about a second, the regex takes
        // 15-30 s (and runs into its timeouts more often).
        $bbox = sprintf('(%.5F,%.5F,%.5F,%.5F)', $south, $west, $north, $east);
        $clauses = '';
        foreach (OsmLightMapper::lightTypes() as $type) {
            $clauses .= 'nwr["seamark:type"="' . $type . '"]' . $bbox . ';';
        }
        // Lighthouses mapped as landmarks (or plain man_made=lighthouse) that carry light data.
        $clauses .= 'nwr["seamark:type"="landmark"][~"^seamark:light:(.+:)?character$"~"."]' . $bbox . ';';
        $clauses .= 'nwr["man_made"="lighthouse"][~"^seamark:light:(.+:)?character$"~"."]' . $bbox . ';';
        $answer = $this->client->query('[out:json][timeout:60];(' . $clauses . ');out tags center;');

        $entries = [];
        foreach ($answer['elements'] as $element) {
            $entry = is_array($element) ? $this->mapper->map($element, $label) : null;
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        $saved = $this->staging->upsertMany(OsmLightMapper::SOURCE, $entries);

        return [
            'fetched' => count($answer['elements']),
            'skipped' => count($answer['elements']) - count($entries),
            'inserted' => $saved['inserted'],
            'updated' => $saved['updated'],
        ];
    }

    /**
     * Refuses a request that comes too soon after the previous one and
     * records this one's time (also if it then fails - an overloaded server
     * shouldn't be hammered either).
     */
    private function throttle(): void
    {
        $now = ($this->clock)();
        $last = is_file($this->throttleFile) ? (int) @file_get_contents($this->throttleFile) : 0;
        $wait = $last + self::MIN_SECONDS_BETWEEN_REQUESTS - $now;
        if ($wait > 0) {
            throw new ApiException("The Overpass API is asked for at most one area per " . self::MIN_SECONDS_BETWEEN_REQUESTS . " seconds - please wait $wait s.", 429, 'lighthouse_import.osm_too_soon');
        }

        $dir = dirname($this->throttleFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->throttleFile, (string) $now);
    }
}
