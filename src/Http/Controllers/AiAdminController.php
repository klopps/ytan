<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Ai\AiUsageRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ValidationException;
use Ytan\Service\Ai\AiClient;
use Ytan\Service\Ai\AiException;
use Ytan\Service\Ai\BriefingRules;

/**
 * /admin/ai (routencheck.md): the Routencheck's switch, daily limit and text
 * cache (PUT /settings/ai), a connection test (POST /settings/ai/test), the
 * traffic-light rules (PUT /settings/briefing-rules) and the token usage per
 * month and user (GET /admin/ai/usage). Admins only.
 */
final class AiAdminController extends BaseController
{
    public const MAX_DAILY_LIMIT = 1000;
    public const MAX_CACHE_HOURS = 24;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AiUsageRepository $usage,
        private readonly AiClient $ai,
    ) {
    }

    public function updateSettings(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        $limit = $body['daily_limit'] ?? null;
        $hours = $body['cache_hours'] ?? null;
        if (!is_numeric($limit) || (int) $limit != $limit || $limit < 0 || $limit > self::MAX_DAILY_LIMIT) {
            throw new ValidationException('The daily limit must be between 0 and ' . self::MAX_DAILY_LIMIT . '.', 'route_check.limit_invalid');
        }
        if (!is_numeric($hours) || (int) $hours != $hours || $hours < 0 || $hours > self::MAX_CACHE_HOURS) {
            throw new ValidationException('The cache time must be between 0 and ' . self::MAX_CACHE_HOURS . ' hours.', 'route_check.cache_invalid');
        }
        $this->settings->setAiSettings((bool) ($body['enabled'] ?? false), (int) $limit, (int) $hours);

        return $this->json($response, ['data' => $this->settings->aiSettings()]);
    }

    /** A tiny request to the model: is the key valid, is there credit, does the model answer? */
    public function test(Request $request, Response $response): Response
    {
        $auth = $this->requireAdmin($request);
        $started = microtime(true);
        try {
            $answer = $this->ai->complete('Answer with one short word.', 'Say "OK".', 200);
        } catch (AiException $e) {
            if ($e->kind !== 'not_configured') {
                $this->usage->record(['user_id' => (int) $auth['sub'], 'username' => (string) ($auth['username'] ?? ''), 'route_id' => null,
                    'model' => $this->ai->model(), 'status' => AiUsageRepository::STATUS_ERROR,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);
            }
            throw new ApiException($e->getMessage(), 502, 'route_check.test_' . $e->kind);
        }
        $this->usage->record(['user_id' => (int) $auth['sub'], 'username' => (string) ($auth['username'] ?? ''), 'route_id' => null,
            'model' => $answer->model, 'input_tokens' => $answer->inputTokens, 'output_tokens' => $answer->outputTokens,
            'cache_read_tokens' => $answer->cacheReadTokens, 'cache_write_tokens' => $answer->cacheWriteTokens,
            'status' => AiUsageRepository::STATUS_OK, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);

        return $this->json($response, ['data' => [
            'model' => $answer->model,
            'text' => mb_substr($answer->text, 0, 100),
            'input_tokens' => $answer->inputTokens,
            'output_tokens' => $answer->outputTokens,
        ]]);
    }

    /** Body {rules: {...}} saves (normalized), {reset: true} goes back to the defaults. */
    public function updateRules(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        if (($body['reset'] ?? false) === true) {
            $this->settings->setBriefingRules(null);
        } elseif (is_array($body['rules'] ?? null)) {
            $this->settings->setBriefingRules($body['rules']);
        } else {
            throw new ValidationException('rules (object) or reset (true) is required.', 'route_check.rules_invalid');
        }

        return $this->json($response, ['data' => ['rules' => $this->settings->briefingRules(), 'defaults' => BriefingRules::defaults()]]);
    }

    public function usage(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $month = $request->getQueryParams()['month'] ?? gmdate('Y-m');
        if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new ValidationException('month must be YYYY-MM.', 'route_check.month_invalid');
        }
        $months = $this->usage->months();
        if (!in_array(gmdate('Y-m'), $months, true)) {
            array_unshift($months, gmdate('Y-m'));
        }

        return $this->json($response, ['data' => [
            'month' => $month,
            'months' => $months,
            'summary_month' => $this->usage->summary($month),
            'summary_all' => $this->usage->summary(null),
            'users' => $this->usage->monthlyByUser($month),
        ]]);
    }
}
