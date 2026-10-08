<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Service\GpxExportService;

/**
 * GPX 1.1 downloads: GET /routes/{id}/gpx and GET /tours/{id}/gpx. Its own
 * controller (rather than methods on RouteController/TourController)
 * because it needs the site settings, which nothing else about routes or
 * tours does.
 *
 * Same rule for both, applied to the route or the tour as a whole: it must
 * be visible to the caller, AND one of
 * - the site setting gpx_export_public is on (anyone, even signed out),
 * - the caller is admin,
 * - export_routes_public and it is public,
 * - export_routes_own and it is the caller's own.
 * A tour's export contains all of its routes, private ones of its creator
 * included - the same as the tour PDF (TourController::document()).
 */
final class GpxExportController extends BaseController
{
    public function __construct(
        private readonly RouteRepository $routes,
        private readonly TourRepository $tours,
        private readonly SettingsRepository $settings,
        private readonly string $appName,
    ) {
    }

    public function routeGpx(Request $request, Response $response, array $args): Response
    {
        $auth = $request->getAttribute('auth');
        $route = $this->routes->findById((int) $args['id']);

        // Visible = public, own, or admin - the same rule as RouteController's photo endpoints.
        $isOwner = $this->isOwner($auth, $route);
        $visible = (int) $route['public'] === 1 || $isOwner || $this->isAdmin($auth);
        if (!$visible || !$this->mayExport($auth, (int) $route['public'] === 1, $isOwner)) {
            throw new ForbiddenException();
        }

        return $this->gpxResponse(
            $response,
            GpxExportService::build($route, $this->appName),
            'route',
            (int) $route['id'],
            (string) $route['name']
        );
    }

    public function tourGpx(Request $request, Response $response, array $args): Response
    {
        $auth = $request->getAttribute('auth');
        $tour = $this->tours->findById((int) $args['id']);

        // Visible = public, own, admin or tour_manage - TourController::assertCanView().
        $isOwner = $this->isOwner($auth, $tour);
        $visible = (int) $tour['public'] === 1 || $isOwner || $this->isAdmin($auth)
            || ($auth !== null && ($auth['tour_manage'] ?? false));
        if (!$visible || !$this->mayExport($auth, (int) $tour['public'] === 1, $isOwner)) {
            throw new ForbiddenException();
        }

        return $this->gpxResponse(
            $response,
            GpxExportService::buildTracks(
                (string) $tour['name'],
                (string) ($tour['description'] ?? ''),
                $this->routes->findByTour((int) $tour['id']),
                $this->appName
            ),
            'tour',
            (int) $tour['id'],
            (string) $tour['name']
        );
    }

    /**
     * The rights/setting part of the rule (visibility is checked by the
     * caller). Mirrored client-side by route.js's canExportRoute() and
     * tour-admin.js's canExportTour(), which only decide whether to offer
     * the export.
     */
    private function mayExport(?array $auth, bool $isPublic, bool $isOwner): bool
    {
        return $this->settings->gpxExportPublic()
            || $this->isAdmin($auth)
            || ($isPublic && ($auth['export_routes_public'] ?? false))
            || ($isOwner && ($auth['export_routes_own'] ?? false));
    }

    private function isOwner(?array $auth, array $row): bool
    {
        return $auth !== null && (int) $auth['sub'] === (int) $row['user_id'];
    }

    private function isAdmin(?array $auth): bool
    {
        return $auth !== null && ($auth['is_admin'] ?? false);
    }

    private function gpxResponse(Response $response, string $gpx, string $kind, int $id, string $name): Response
    {
        $response->getBody()->write($gpx);

        return $response
            ->withHeader('Content-Type', 'application/gpx+xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $kind . '-' . $id . '.gpx"; filename*=UTF-8\'\'' . rawurlencode(GpxExportService::filename($name, $kind, $id)))
            ->withHeader('Cache-Control', 'no-store');
    }
}
