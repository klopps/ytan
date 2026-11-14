<?php

declare(strict_types=1);

namespace Ytan\Service\Ai;

use Ytan\Domain\Ai\AiUsageRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Service\Log\LogWriter;

/**
 * Routencheck for one route and day (routencheck.md): the traffic light and
 * its reasons from RouteBriefingBuilder (no AI, always), then the AI text -
 * from the cache when the same context was answered within the configured
 * hours, else from the model unless the user's daily limit is used up. Every
 * request is written to ai_usage (also cache hits and failures) and one line
 * to storage/logs/ai.log.
 *
 * The answer never fails because of the AI: without text, `text` is null and
 * `text_status` says why (limit, error, not_configured, no_data).
 */
final class RouteBriefingService
{
    /** Bump when the prompt changes, so cached texts of the old prompt are not reused. */
    public const PROMPT_VERSION = 3;
    private const MAX_TOKENS = 3000;

    public function __construct(
        private readonly RouteBriefingBuilder $builder,
        private readonly AiClient $ai,
        private readonly AiTextCache $cache,
        private readonly AiUsageRepository $usage,
        private readonly SettingsRepository $settings,
        private readonly ?LogWriter $log = null,
    ) {
    }

    /**
     * @param array<string, mixed> $authUser JWT payload
     * @param array<string, mixed> $route route row
     * @param list<array{0: float, 1: float}> $points
     * @param array{date: string, start_hour: int, reverse: bool, speed_kmh: float, level: string, current_kmh?: ?float} $options
     * @return array<string, mixed>
     */
    public function briefing(array $authUser, array $route, array $points, array $options, string $locale): array
    {
        $started = microtime(true);
        $settings = $this->settings->aiSettings();
        $rules = $this->settings->briefingRules();
        $result = $this->builder->build($points, $options + ['user_id' => (int) $authUser['sub']], $rules);

        $userId = (int) $authUser['sub'];
        $username = (string) ($authUser['username'] ?? '');
        $usedToday = $this->usage->countToday($userId);
        $result['limit'] = ['per_day' => $settings['daily_limit'], 'used_today' => $usedToday];
        $result['text'] = null;
        $result['text_created_at'] = null;
        $result['text_cached'] = false;

        if ($result['rating'] === null) {
            $result['text_status'] = 'no_data';

            return $result;
        }
        if (!($options['with_text'] ?? true)) {
            // the page asks for the traffic light first and the text in a second request
            $result['text_status'] = 'skipped';

            return $result;
        }
        if (!$this->ai->configured()) {
            $result['text_status'] = 'not_configured';

            return $result;
        }

        $system = self::systemPrompt();
        $user = self::userPrompt(RouteBriefingBuilder::aiContext($result), $locale);
        $key = AiTextCache::key($system . "\0v" . self::PROMPT_VERSION, $user, $this->ai->model());
        $usageRow = ['user_id' => $userId, 'username' => $username, 'route_id' => (int) $route['id'], 'model' => $this->ai->model()];

        $cached = $this->cache->get($key, $settings['cache_hours'] * 3600);
        if ($cached !== null) {
            $this->usage->record($usageRow + ['cache_hit' => true, 'status' => AiUsageRepository::STATUS_OK, 'duration_ms' => self::ms($started)]);
            $this->logLine($username, $route, 'cache hit', $started);
            $result['text'] = $cached['text'];
            $result['text_created_at'] = $cached['created_at'];
            $result['text_cached'] = true;
            $result['text_status'] = 'ok';

            return $result;
        }

        if ($usedToday >= $settings['daily_limit']) {
            $this->usage->record($usageRow + ['status' => AiUsageRepository::STATUS_LIMIT, 'duration_ms' => self::ms($started)]);
            $this->logLine($username, $route, 'daily limit reached (' . $usedToday . '/' . $settings['daily_limit'] . ')', $started);
            $result['text_status'] = 'limit';

            return $result;
        }

        try {
            $answer = $this->ai->complete($system, $user, self::MAX_TOKENS);
        } catch (AiException $e) {
            $this->usage->record($usageRow + ['status' => AiUsageRepository::STATUS_ERROR, 'duration_ms' => self::ms($started)]);
            $this->logLine($username, $route, 'ERROR ' . $e->kind . ': ' . $e->getMessage(), $started);
            $result['text_status'] = 'error';
            $result['text_error'] = $e->kind;

            return $result;
        }

        $this->usage->record($usageRow + [
            'model' => $answer->model,
            'input_tokens' => $answer->inputTokens,
            'output_tokens' => $answer->outputTokens,
            'cache_read_tokens' => $answer->cacheReadTokens,
            'cache_write_tokens' => $answer->cacheWriteTokens,
            'status' => AiUsageRepository::STATUS_OK,
            'duration_ms' => self::ms($started),
        ]);
        $this->logLine($username, $route, sprintf('ok, %s, tokens in=%d out=%d cache_read=%d cache_write=%d',
            $answer->model, $answer->inputTokens, $answer->outputTokens, $answer->cacheReadTokens, $answer->cacheWriteTokens), $started);
        $result['text'] = $answer->text;
        $result['text_created_at'] = $this->cache->put($key, $answer->text);
        $result['text_status'] = 'ok';
        $result['limit']['used_today'] = $usedToday + 1;

        return $result;
    }

    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are an experienced sea kayak guide. A paddler planned a route and asks whether and when to paddle it on a given day.
You get a JSON assessment that a program already computed from weather forecasts, the coastline and a fixed rule set:
an overall traffic light (green/yellow/red, grey = no data), one rating per route section with its reasons, the rating for
every possible start hour, daylight, places near the route (landing sites, shelters, campsites, danger spots, portages) and
the thresholds that apply to this paddler.

Rules:
- The ratings are decided. Never change, soften or contradict a colour; explain them.
- Use only the given data. Do not invent weather, places, distances or local knowledge.
- Reason codes: wind/gust (m/s), wave (m), thunder, dark/dusk (time vs. sunrise/sunset), cold_water (water temperature),
  offshore (wind blowing from land out to open water - drift risk), beam_sea (waves from the side).
  lee = land close upwind (sheltered), exposed = long open water upwind.
  wind_relative: head/beam/tail relative to the course.
  current (km/h against the course on a river). water: river/canal. current_kmh: river current along the course
  (+ with it, - against it); it is computed from an ASSUMED river current (route.assumed_river_current_kmh, a
  rough default the paddler can change) - say that the real current depends on the water level. Canals: no current.
- Mention when parts have no data (grey) and what that means.
- The assessment is a planning aid, not a safety guarantee; the paddler decides on the water.

The app shows your text next to the full section list, the start hour bar and the list of places, so do not repeat
those lists: pick out what matters and say why. Keep the whole answer under 180 words - it is read on a phone.

Answer in Markdown with exactly these parts:
1. One sentence with the overall verdict.
2. "### " heading for the best start times: one or two sentences (from the start hour ratings).
3. "### " heading for the sections that need attention: at most 3 bullets of one line each (km, time, why);
   only yellow, red or grey sections - if all are green, one sentence saying so.
4. "### " heading for bail-out and landing options: at most 3 bullets of one line each (only from the given places;
   say so in one sentence if there are none).
5. "### " heading for further notes (daylight, cold water, data gaps): at most 2 bullets - leave this part out if there is
   nothing to say.
Write the headings in the requested language.
PROMPT;
    }

    /** @param array<string, mixed> $context */
    public static function userPrompt(array $context, string $locale): string
    {
        $language = match ($locale) {
            'de' => 'German',
            default => 'English',
        };

        return "Language of the answer: $language.\n\nAssessment:\n"
            . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function logLine(string $username, array $route, string $what, float $started): void
    {
        $this->log?->line(sprintf('user=%s route=%d: %s (%d ms)', $username, (int) $route['id'], $what, self::ms($started)));
    }

    private static function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
