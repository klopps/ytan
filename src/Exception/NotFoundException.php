<?php

declare(strict_types=1);

namespace Ytan\Exception;

class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Resource not found.', ?string $code = null)
    {
        parent::__construct($message, 404, $code);
    }
}
