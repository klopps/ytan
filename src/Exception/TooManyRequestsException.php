<?php

declare(strict_types=1);

namespace Ytan\Exception;

final class TooManyRequestsException extends ApiException
{
    public function __construct(string $message = 'Too many attempts. Please wait a few minutes and try again.', ?string $errorCode = 'auth.too_many_attempts')
    {
        parent::__construct($message, 429, $errorCode);
    }
}
