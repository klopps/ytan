<?php

declare(strict_types=1);

namespace Ytan\Tests\Fixtures;

use Ytan\Service\Ai\AiClient;
use Ytan\Service\Ai\AiException;
use Ytan\Service\Ai\AiResult;

/**
 * Test double for AiClient: answers with a fixed text (or throws), records
 * every prompt it got.
 */
final class FakeAiClient implements AiClient
{
    /** @var list<array{system: string, user: string, max_tokens: int}> */
    public array $calls = [];

    private ?AiException $failure = null;

    public function __construct(
        private string $text = 'Gut fahrbar.',
        private readonly bool $configured = true,
        private readonly string $model = 'claude-test',
    ) {
    }

    public function willAnswer(string $text): void
    {
        $this->text = $text;
        $this->failure = null;
    }

    public function willFail(string $kind = 'other'): void
    {
        $this->failure = new AiException('fake failure', $kind);
    }

    public function configured(): bool
    {
        return $this->configured;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function complete(string $system, string $user, int $maxTokens): AiResult
    {
        $this->calls[] = ['system' => $system, 'user' => $user, 'max_tokens' => $maxTokens];
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new AiResult($this->text, $this->model, 1200, 300, 100, 0);
    }
}
