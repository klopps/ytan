<?php

declare(strict_types=1);

namespace Ytan\Exception;

/**
 * A national lights service (Väylävirasto in Finland, Kystverket in Norway)
 * didn't deliver what the import needs: unreachable, an error status, an
 * answer that is not the expected JSON/GML. Maps to 502: an upstream problem,
 * not an application bug.
 */
final class NationalLightsException extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 502, 'lighthouse_import.national_unavailable');
    }
}
