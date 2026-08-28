<?php

declare(strict_types=1);

namespace Ytan\Exception;

class ForbiddenException extends ApiException
{
    public function __construct(string $message = 'Not allowed to perform this action.')
    {
        parent::__construct($message, 403);
    }
}
