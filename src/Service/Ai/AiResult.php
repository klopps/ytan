<?php

declare(strict_types=1);

namespace Ytan\Service\Ai;

/** The text a model wrote plus what it cost in tokens (from the API's usage block). */
final class AiResult
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheReadTokens = 0,
        public readonly int $cacheWriteTokens = 0,
    ) {
    }
}
