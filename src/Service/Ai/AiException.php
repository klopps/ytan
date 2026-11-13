<?php

declare(strict_types=1);

namespace Ytan\Service\Ai;

use RuntimeException;

/**
 * A model request that produced no text. $kind tells the admin page and the
 * log what went wrong: 'not_configured' (no key), 'auth' (key rejected),
 * 'credit' (balance used up), 'rate_limit', 'refusal', 'timeout', 'other'.
 */
final class AiException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'other', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
