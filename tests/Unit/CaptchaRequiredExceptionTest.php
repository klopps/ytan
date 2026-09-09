<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\CaptchaRequiredException;

final class CaptchaRequiredExceptionTest extends TestCase
{
    public function testCarriesA422StatusAndTheChallengeInItsPayload(): void
    {
        $exception = new CaptchaRequiredException(2, '3 + 4 = ?', 'the-token');

        $this->assertSame(422, $exception->getStatusCode());
        $this->assertSame(
            [
                'tour_count' => 2,
                'captcha' => ['question' => '3 + 4 = ?', 'token' => 'the-token'],
            ],
            $exception->getPayload()
        );
        $this->assertStringContainsString('2 tour(s)', $exception->getMessage());
    }
}
