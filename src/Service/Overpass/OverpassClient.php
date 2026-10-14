<?php

declare(strict_types=1);

namespace Ytan\Service\Overpass;

/**
 * Injection seam for the one outbound call the OpenStreetMap lighthouse
 * import makes - an Overpass QL query - so tests never touch the network
 * (same idea as JsonHttpClient for the weather/geocoding services).
 */
interface OverpassClient
{
    /**
     * @param string $query Overpass QL, expected to ask for `[out:json]`
     * @return array<string,mixed> the decoded JSON answer (its "elements" list)
     * @throws \Ytan\Exception\OverpassException when no endpoint delivers usable JSON
     */
    public function query(string $query): array;
}
