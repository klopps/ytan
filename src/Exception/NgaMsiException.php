<?php

declare(strict_types=1);

namespace Ytan\Exception;

/**
 * The NGA Maritime Safety Information web service didn't deliver the
 * List of Lights (unreachable, blocked, an error status, an answer that isn't
 * the expected JSON). Maps to 502: an upstream problem, not an application bug.
 */
final class NgaMsiException extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 502, 'lighthouse_import.nga_unavailable');
    }
}
