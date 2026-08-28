<?php

declare(strict_types=1);

namespace Ytan\Exception;

class UnauthorizedException extends ApiException
{
    public function __construct(string $message = 'Authentication required.')
    {
        parent::__construct($message, 401);
    }
}
