<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\Ai\BriefingRules;
use Ytan\Service\Ai\RouteBriefingService;
use Ytan\Service\Ai\RouteGeometry;

/**
 * Routencheck (routencheck.md): POST /routes/{id}/briefing - traffic light +
 * AI text for one route and day - and the user's experience level
 * (GET/PUT /auth/paddle-level).
 *
 * Gate order: signed in -> feature switched on (/admin/ai) -> right ai_use
 * (admins always) -> route visible (public, own, admin; else 404 like
 * RouteController::show()) -> valid options.
 */
final class AiBriefingController extends BaseController
{
    public const DEFAULT_SPEED_KMH = 6.0;
    public const DEFAULT_START_HOUR = 10;
    public const MAX_CURRENT_KMH = 10;

    public function __construct(
        private readonly RouteRepository $routes,
        private readonly UserRepository $users,
        private readonly SettingsRepository $settings,
        private readonly RouteBriefingService $service,
        private readonly string $locale,
    ) {
    }

    public function briefing(Request $request, Response $response, array $args): Response
    {
        $auth = $this->assertAllowed($request);
        $route = $this->routes->findById((int) $args['id']);
        if (!(bool) $route['public'] && (int) $route['user_id'] !== (int) $auth['sub'] && !($auth['is_admin'] ?? false)) {
            throw new NotFoundException("Route {$args['id']} not found.");
        }
        $points = RouteGeometry::parsePoints($route['points'] ?? null);
        if (count($points) < 2) {
            throw new ValidationException('The route needs at least two waypoints.', 'route_check.too_short');
        }
        $options = $this->options($this->jsonBody($request), (int) $auth['sub']);

        return $this->json($response, ['data' => $this->service->briefing($auth, $route, $points, $options, $this->locale)]);
    }

    public function paddleLevel(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);

        return $this->json($response, ['data' => ['paddle_level' => $this->users->getPaddleLevel((int) $auth['sub'])]]);
    }

    public function setPaddleLevel(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $level = $this->jsonBody($request)['paddle_level'] ?? null;
        if (!BriefingRules::isLevel($level)) {
            throw new ValidationException('Unknown experience level.', 'route_check.level_invalid');
        }
        $this->users->setPaddleLevel((int) $auth['sub'], $level);

        return $this->json($response, ['data' => ['paddle_level' => $level]]);
    }

    /** @return array<string, mixed> */
    private function assertAllowed(Request $request): array
    {
        $auth = $this->requireAuthUser($request);
        if (!$this->settings->aiSettings()['enabled']) {
            throw new ForbiddenException('The Routencheck is switched off.', 'route_check.disabled');
        }
        if (!$this->hasRight($auth, 'ai_use')) {
            throw new ForbiddenException('No permission for the Routencheck.', 'route_check.forbidden');
        }

        return $auth;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{date: string, start_hour: int, reverse: bool, speed_kmh: float, level: string, current_kmh: ?float, with_text: bool}
     */
    private function options(array $body, int $userId): array
    {
        $today = new DateTimeImmutable('today');
        $date = $body['date'] ?? $today->format('Y-m-d');
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        // the forecast covers 7 days; one day of slack for time zones
        if ($parsed === false || $parsed->format('Y-m-d') !== $date || $parsed < $today->modify('-1 day') || $parsed > $today->modify('+7 days')) {
            throw new ValidationException('The date must be within the next 7 days.', 'route_check.date_invalid');
        }

        $hour = $body['start_hour'] ?? self::DEFAULT_START_HOUR;
        if (!is_numeric($hour) || (int) $hour != $hour || $hour < 0 || $hour > 23) {
            throw new ValidationException('The start hour must be between 0 and 23.', 'route_check.start_invalid');
        }

        $speed = $body['speed_kmh'] ?? null;
        if ($speed === null || $speed === '') {
            $speed = $this->users->getDefaultSpeed($userId) ?? self::DEFAULT_SPEED_KMH;
        }
        if (!is_numeric($speed) || $speed < 0.5 || $speed > 30) {
            throw new ValidationException('The speed must be between 0.5 and 30 km/h.', 'route_check.speed_invalid');
        }

        $level = $body['level'] ?? null;
        if ($level === null || $level === '') {
            $level = $this->users->getPaddleLevel($userId);
        }
        if (!BriefingRules::isLevel($level)) {
            throw new ValidationException('Unknown experience level.', 'route_check.level_invalid');
        }

        // river current in km/h; null = the admin's default (BriefingRules river_current_kmh)
        $current = $body['current_kmh'] ?? null;
        if ($current === '') {
            $current = null;
        }
        if ($current !== null && (!is_numeric($current) || $current < 0 || $current > self::MAX_CURRENT_KMH)) {
            throw new ValidationException('The current must be between 0 and 10 km/h.', 'route_check.current_invalid');
        }

        return [
            'date' => $date,
            'start_hour' => (int) $hour,
            'reverse' => (bool) ($body['reverse'] ?? false),
            'speed_kmh' => round((float) $speed, 1),
            'level' => $level,
            'current_kmh' => $current === null ? null : round((float) $current, 1),
            // false = traffic light only (the page asks for the text in a second request)
            'with_text' => ($body['text'] ?? true) !== false,
        ];
    }
}
