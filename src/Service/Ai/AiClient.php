<?php

declare(strict_types=1);

namespace Ytan\Service\Ai;

/**
 * A language model that turns a prompt into text (Routencheck). The one real
 * implementation is AnthropicAiClient; tests use FakeAiClient.
 */
interface AiClient
{
    /** Whether a key is configured at all (no key = the feature can't produce text). */
    public function configured(): bool;

    /** The model asked (also stored with every usage row). */
    public function model(): string;

    /** @throws AiException */
    public function complete(string $system, string $user, int $maxTokens): AiResult;
}
