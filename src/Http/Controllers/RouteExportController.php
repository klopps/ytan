<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Service\GpxExportService;

/**
 * GET /routes/{id}/gpx - a route as a GPX 1.1 download. Its own controller
 * (rather than one more RouteController method) because it needs the site
 * settings, which nothing else about routes does.
 *
 * Allowed when the route is visible to the caller (public, own, or admin -
 * the same rule as RouteController's photo endpoints) AND one of:
 * - the site setting gpx_export_public is on (anyone, even signed out),
 * - export_routes_public and the route is public,
 * - export_routes_own and the route is the caller's own.
 * is_admin implies both rights (BaseController::hasRight()).
 */
final class RouteExportController extends BaseController
{
    public function __construct(
        private readonly RouteRepository $routes,
        private readonly SettingsRepository $settings,
        private readonly string $appName,
    ) {
    }

    public function gpx(Request $request, Response $response, array $args): Response
    {
        $auth = $request->getAttribute('auth');
        $route = $this->routes->findById((int) $args['id']);

        if (!self::canExport($auth, $route, $this->settings->gpxExportPublic())) {
            throw new ForbiddenException();
        }

        $response->getBody()->write(GpxExportService::build($route, $this->appName));

        $filename = GpxExportService::filename($route);

        return $response
            ->withHeader('Content-Type', 'application/gpx+xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="route-' . (int) $route['id'] . '.gpx"; filename*=UTF-8\'\'' . rawurlencode($filename))
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Mirrored client-side by route.js's canExportRoute() (which only
     * decides whether to offer the menu item - this is the actual check).
     */
    private static function canExport(?array $auth, array $route, bool $exportPublic): bool
    {
        $isPublic = (int) $route['public'] === 1;
        $isOwner = $auth !== null && (int) $auth['sub'] === (int) $route['user_id'];
        $isAdmin = $auth !== null && ($auth['is_admin'] ?? false);

        $visible = $isPublic || $isOwner || $isAdmin;
        if (!$visible) {
            return false;
        }

        return $exportPublic
            || $isAdmin
            || ($isPublic && ($auth['export_routes_public'] ?? false))
            || ($isOwner && ($auth['export_routes_own'] ?? false));
    }
}
