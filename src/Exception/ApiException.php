<?php

declare(strict_types=1);

namespace Ytan\Exception;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Machine-readable identifier (e.g. "auth.invalid_credentials") the
     * frontend can translate via its own t() catalog under the "error.*"
     * namespace, falling back to this exception's English getMessage() when
     * null (not every throw site has been migrated) or when no matching
     * "error.<code>" key exists. See src/App.php's error handler, which
     * merges this into the JSON error body's "code" field when set.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
