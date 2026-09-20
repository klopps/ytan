<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\AppUpdateRequiredException;

final class AppUpdateRequiredExceptionTest extends TestCase
{
    public function testCarriesA426StatusAndItsErrorCode(): void
    {
        $exception = new AppUpdateRequiredException();

        $this->assertSame(426, $exception->getStatusCode());
        $this->assertSame('app.update_required', $exception->getErrorCode());
        $this->assertNotSame('', $exception->getMessage());
    }
}
