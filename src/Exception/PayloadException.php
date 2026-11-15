<?php

declare(strict_types=1);

namespace Ytan\Exception;

/**
 * An ApiException that carries extra fields for the JSON error body
 * (captcha challenge, conflicting record, mismatching translation keys).
 * App::create()'s error handler merges getPayload() into {"error": {...}}.
 */
interface PayloadException
{
    /** @return array<string, mixed> */
    public function getPayload(): array;
}
