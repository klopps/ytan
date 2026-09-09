<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\CaptchaService;

final class CaptchaServiceTest extends TestCase
{
    private CaptchaService $captcha;

    protected function setUp(): void
    {
        $this->captcha = new CaptchaService('unit-test-secret');
    }

    public function testIssueChallengeReturnsAQuestionAndToken(): void
    {
        $challenge = $this->captcha->issueChallenge('route:1');

        $this->assertMatchesRegularExpression('/^\d \+ \d = \?$/', $challenge['question']);
        $this->assertNotEmpty($challenge['token']);
    }

    public function testVerifyAcceptsTheCorrectAnswer(): void
    {
        $challenge = $this->captcha->issueChallenge('route:1');
        [$a, $b] = $this->parseQuestion($challenge['question']);

        $this->assertTrue($this->captcha->verify($challenge['token'], $a + $b, 'route:1'));
    }

    public function testVerifyRejectsAWrongAnswer(): void
    {
        $challenge = $this->captcha->issueChallenge('route:1');
        [$a, $b] = $this->parseQuestion($challenge['question']);

        $this->assertFalse($this->captcha->verify($challenge['token'], $a + $b + 1, 'route:1'));
    }

    /**
     * A captcha solved for one route must not be replayable against a
     * different one - the context is baked into the signed token.
     */
    public function testVerifyRejectsAMismatchedContext(): void
    {
        $challenge = $this->captcha->issueChallenge('route:1');
        [$a, $b] = $this->parseQuestion($challenge['question']);

        $this->assertFalse($this->captcha->verify($challenge['token'], $a + $b, 'route:2'));
    }

    public function testVerifyRejectsAGarbageToken(): void
    {
        $this->assertFalse($this->captcha->verify('not-a-real-token', 5, 'route:1'));
    }

    public function testVerifyRejectsATokenSignedWithADifferentSecret(): void
    {
        $other = new CaptchaService('a-different-secret');
        $challenge = $other->issueChallenge('route:1');
        [$a, $b] = $this->parseQuestion($challenge['question']);

        $this->assertFalse($this->captcha->verify($challenge['token'], $a + $b, 'route:1'));
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parseQuestion(string $question): array
    {
        preg_match('/^(\d) \+ (\d) = \?$/', $question, $m);

        return [(int) $m[1], (int) $m[2]];
    }
}
