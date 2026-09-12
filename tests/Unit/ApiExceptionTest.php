<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\ApiException;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;

/**
 * ApiException::getErrorCode() (Phase 4 of the i18n effort - see
 * resources/i18n/{en,de}.json's "error.*" keys and src/App.php's error
 * handler, which merges this into the JSON error body's "code" field) is
 * an optional machine-readable identifier the frontend can translate,
 * separate from PHP's own built-in (int) Exception::getCode(). Defaults to
 * null so throw sites that haven't been migrated yet keep working exactly
 * as before - the frontend falls back to the English getMessage() in that
 * case (see i18n.js's translateApiError()).
 */
final class ApiExceptionTest extends TestCase
{
    public function testGetErrorCodeDefaultsToNull(): void
    {
        $exception = new ApiException('Something went wrong.', 500);

        $this->assertNull($exception->getErrorCode());
    }

    public function testGetErrorCodeReturnsTheExplicitlySetValue(): void
    {
        $exception = new ApiException('Something went wrong.', 500, 'some.code');

        $this->assertSame('some.code', $exception->getErrorCode());
    }

    public function testValidationExceptionCarriesAnOptionalCode(): void
    {
        $withCode = new ValidationException('Bad input.', 'validation.bad_input');
        $withoutCode = new ValidationException('Bad input.');

        $this->assertSame(422, $withCode->getStatusCode());
        $this->assertSame('validation.bad_input', $withCode->getErrorCode());
        $this->assertNull($withoutCode->getErrorCode());
    }

    public function testUnauthorizedExceptionCarriesAnOptionalCode(): void
    {
        $exception = new UnauthorizedException('Nope.', 'auth.nope');

        $this->assertSame(401, $exception->getStatusCode());
        $this->assertSame('auth.nope', $exception->getErrorCode());
    }

    public function testForbiddenExceptionCarriesAnOptionalCode(): void
    {
        $exception = new ForbiddenException('Nope.', 'forbidden.nope');

        $this->assertSame(403, $exception->getStatusCode());
        $this->assertSame('forbidden.nope', $exception->getErrorCode());
    }

    public function testNotFoundExceptionCarriesAnOptionalCode(): void
    {
        $exception = new NotFoundException('Gone.', 'not_found.gone');

        $this->assertSame(404, $exception->getStatusCode());
        $this->assertSame('not_found.gone', $exception->getErrorCode());
    }
}
