<?php

declare(strict_types=1);

namespace Ytan\Exception;

/**
 * The Overpass API (OpenStreetMap data) didn't deliver: unreachable,
 * overloaded (HTTP 429/504), or an answer that isn't JSON. Maps to 502 so
 * the admin sees "upstream problem, try again later", not an application bug.
 */
final class OverpassException extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 502, 'lighthouse_import.osm_unavailable');
    }
}
