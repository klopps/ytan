<?php

declare(strict_types=1);

namespace Ytan\Exception;

class ValidationException extends ApiException
{
    public function __construct(string $message = 'Invalid request data.')
    {
        parent::__construct($message, 422);
    }
}
