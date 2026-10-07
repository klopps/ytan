<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Watch\WatchLinkRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Service\WatchColors;
use Ytan\Service\WatchRoutePayload;

/**
 * Garmin watch data field (watch/): pairing token, the route "sent to the
 * watch", and the device-facing endpoint the data field polls. The device
 * endpoint authenticates with the X-Watch-Token header, not a JWT - the
 * token is baked into a sideloaded build and a data field has no UI to log
 * in with.
 */
final class WatchController extends BaseController
{
    private const UNITS = ['metric', 'nautical'];

    public function __construct(
        private readonly WatchLinkRepository $links,
        private readonly RouteRepository $routes,
    ) {
    }

    public function status(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);

        return $this->json($response, ['data' => $this->statusData((int) $auth['sub'])]);
    }

    public function createToken(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $token = bin2hex(random_bytes(16));
        $this->links->setToken((int) $auth['sub'], $token);

        return $this->json($response, ['data' => ['token' => $token]], 201);
    }

    public function deleteToken(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $this->links->clearToken((int) $auth['sub']);

        return $this->json($response, ['data' => $this->statusData((int) $auth['sub'])]);
    }

    public function setRoute(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $body = $this->jsonBody($request);
        $routeId = filter_var($body['route_id'] ?? null, FILTER_VALIDATE_INT);
        $unit = $body['unit'] ?? 'metric';
        if ($routeId === false || $routeId < 1) {
            throw new ValidationException('route_id must be a route id.');
        }
        if (!in_array($unit, self::UNITS, true)) {
            throw new ValidationException('unit must be metric or nautical.');
        }

        $route = $this->routes->findById($routeId);
        if (!$this->canView($route, (int) $auth['sub'], (bool) ($auth['is_admin'] ?? false))) {
            throw new ForbiddenException();
        }
        $this->links->setRoute((int) $auth['sub'], $routeId, $unit);

        return $this->json($response, ['data' => $this->statusData((int) $auth['sub'])]);
    }

    public function clearRoute(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $this->links->clearRoute((int) $auth['sub']);

        return $this->json($response, ['data' => $this->statusData((int) $auth['sub'])]);
    }

    public function setColors(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $colors = WatchColors::normalize($this->jsonBody($request)['colors'] ?? null);
        $this->links->setColors((int) $auth['sub'], json_encode($colors));

        return $this->json($response, ['data' => $this->statusData((int) $auth['sub'])]);
    }

    public function resetColors(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $this->links->setColors((int) $auth['sub'], null);

        return $this->json($response, ['data' => $this->statusData((int) $auth['sub'])]);
    }

    /**
     * Polled by the watch. Returns the compact WatchRoutePayload shape
     * directly (no {"data": ...} wrapper) - every byte counts there.
     */
    public function deviceRoute(Request $request, Response $response): Response
    {
        $token = trim($request->getHeaderLine('X-Watch-Token'));
        $link = $token === '' ? null : $this->links->findByToken($token);
        if ($link === null) {
            throw new UnauthorizedException();
        }

        $route = null;
        if ($link['route_id'] !== null) {
            $candidate = $this->routes->findById((int) $link['route_id']);
            // The route may have been made private by its owner since it was
            // sent to the watch - then the watch must not see it any more.
            if ($this->canView($candidate, (int) $link['user_id'], (bool) $link['is_admin'])) {
                $route = $candidate;
            }
        }

        $payload = WatchRoutePayload::build($route, (string) $link['unit'], (string) $link['updated_at']);
        $payload['c'] = WatchColors::flatten(WatchColors::effective($link['colors']));
        // Usual speed (m/s) for the ETA until the watch has measured its own.
        if ($link['default_speed_kmh'] !== null) {
            $payload['s'] = round((float) $link['default_speed_kmh'] / 3.6, 2);
        }

        return $this->json($response, $payload);
    }

    private function canView(array $route, int $userId, bool $isAdmin): bool
    {
        return (int) $route['public'] === 1 || (int) $route['user_id'] === $userId || $isAdmin;
    }

    private function statusData(int $userId): array
    {
        $link = $this->links->findByUser($userId);
        $route = null;
        if ($link !== null && $link['route_id'] !== null) {
            $row = $this->routes->findById((int) $link['route_id']);
            $route = ['id' => (int) $row['id'], 'name' => $row['name']];
        }

        return [
            'has_token' => $link !== null && $link['token_hash'] !== null,
            'route' => $route,
            'unit' => $link['unit'] ?? 'metric',
            'colors' => WatchColors::effective($link['colors'] ?? null),
            'default_colors' => WatchColors::DEFAULTS,
        ];
    }
}
