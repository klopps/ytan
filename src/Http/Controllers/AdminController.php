<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Area\AreaRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Service\ImageReconciliationService;

/**
 * Backend for the /admin area's own tools that aren't already covered by
 * another entity's controller: the /admin/image-cleanup tool (templates/
 * admin-image-cleanup.php, public/js/admin-image-cleanup.js) and the /admin
 * dashboard's stat tiles (templates/admin.php, public/js/admin-dashboard.js).
 * Plain requireAdmin() gate on every method, same as any other admin
 * action - no extra opt-in flag like the /admin/translate tool, since
 * these actions either only ever touch already-orphaned files/rows (see
 * ImageReconciliationService's own doc comment) or are read-only counts.
 */
final class AdminController extends BaseController
{
    public function __construct(
        private readonly ImageReconciliationService $images,
        private readonly PoiRepository $pois,
        private readonly RouteRepository $routes,
        private readonly AreaRepository $areas,
        private readonly TourRepository $tours,
        private readonly UserRepository $users,
    ) {
    }

    public function scanImageOrphans(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->images->scan()]);
    }

    public function deleteImageOrphans(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $items = $this->jsonBody($request)['items'] ?? [];
        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            $entityId = (int) ($item['entity_id'] ?? 0);

            if (($item['kind'] ?? '') === 'file') {
                $this->images->deleteOrphanedFile($type, $entityId, (string) ($item['filename'] ?? ''));
            } elseif (($item['kind'] ?? '') === 'row') {
                $this->images->deleteDanglingRow($type, $entityId, (int) ($item['image_id'] ?? 0));
            }
        }

        return $this->json($response, ['data' => $this->images->scan()]);
    }

    /**
     * Counts for the dashboard's stat tiles - "private" is derived
     * (total - public) rather than a separate query, since every one of
     * these tables only distinguishes the two via the same plain `public`
     * column countAll()/countPublic() already query.
     */
    public function dashboardStats(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $poiTotal = $this->pois->countAll();
        $poiPublic = $this->pois->countPublic();
        $routeTotal = $this->routes->countAll();
        $routePublic = $this->routes->countPublic();
        $areaTotal = $this->areas->countAll();
        $areaPublic = $this->areas->countPublic();
        $tourTotal = $this->tours->countAll();
        $tourPublic = $this->tours->countPublic();

        return $this->json($response, ['data' => [
            'poi' => ['public' => $poiPublic, 'private' => $poiTotal - $poiPublic],
            'route' => ['public' => $routePublic, 'private' => $routeTotal - $routePublic],
            'area' => ['public' => $areaPublic, 'private' => $areaTotal - $areaPublic],
            'tour' => ['public' => $tourPublic, 'private' => $tourTotal - $tourPublic],
            'users' => ['total' => $this->users->countAll()],
        ]]);
    }
}
