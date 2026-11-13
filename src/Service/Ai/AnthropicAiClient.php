<?php

declare(strict_types=1);

namespace Ytan\Service\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\RequestOptions;

/**
 * The Routencheck's text from Anthropic's Messages API (official PHP SDK).
 * Key and model come from .env (ANTHROPIC_API_KEY, AI_MODEL; never stored in
 * the database). Low effort - the numbers are already computed, the model only
 * has to explain them - and the server-side refusal fallback on, as Anthropic
 * recommends for the current models; the system prompt is marked cacheable.
 */
final class AnthropicAiClient implements AiClient
{
    public const DEFAULT_MODEL = 'claude-sonnet-5-5';

    private ?Client $client = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly float $timeoutSeconds = 60.0,
    ) {
    }

    public static function fromEnv(): self
    {
        $model = trim((string) ($_ENV['AI_MODEL'] ?? ''));
        $timeout = (float) ($_ENV['AI_TIMEOUT'] ?? 60);

        return new self(
            trim((string) ($_ENV['ANTHROPIC_API_KEY'] ?? '')),
            $model !== '' ? $model : self::DEFAULT_MODEL,
            $timeout > 0 ? $timeout : 60.0,
        );
    }

    public function configured(): bool
    {
        return $this->apiKey !== '';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function complete(string $system, string $user, int $maxTokens): AiResult
    {
        if (!$this->configured()) {
            throw new AiException('No ANTHROPIC_API_KEY configured.', 'not_configured');
        }
        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $this->model,
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                outputConfig: ['effort' => 'low'],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new AiException($e->getMessage(), 'auth', $e);
        } catch (RateLimitException $e) {
            throw new AiException($e->getMessage(), 'rate_limit', $e);
        } catch (APITimeoutException $e) {
            throw new AiException($e->getMessage(), 'timeout', $e);
        } catch (BadRequestException $e) {
            $kind = stripos($e->getMessage(), 'credit balance') !== false ? 'credit' : 'other';
            throw new AiException($e->getMessage(), $kind, $e);
        } catch (APIConnectionException|APIException $e) {
            throw new AiException($e->getMessage(), 'other', $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new AiException('The model declined the request.', 'refusal');
        }
        $text = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }
        if (trim($text) === '') {
            throw new AiException('The model returned no text (stop reason ' . ($message->stopReason ?? '?') . ').', 'other');
        }
        $usage = $message->usage;

        return new AiResult(
            trim($text),
            $message->model,
            $usage->inputTokens,
            $usage->outputTokens,
            (int) ($usage->cacheReadInputTokens ?? 0),
            (int) ($usage->cacheCreationInputTokens ?? 0),
        );
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->apiKey,
            requestOptions: RequestOptions::with(timeout: $this->timeoutSeconds, maxRetries: 1),
        );
    }
}
