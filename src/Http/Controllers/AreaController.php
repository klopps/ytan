<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Area\AreaRepository;
use Ytan\Exception\NotFoundException;
use Ytan\Service\ImageStorageService;

final class AreaController extends BaseController
{
    use EntityImageEndpoints;

    // Mirrored client-side by AREA_PHOTO_MAX_COUNT (public/js/config.js) -
    // kept in sync manually, there being no shared config layer between PHP and JS.
    private const MAX_IMAGES_PER_AREA = 5;

    public function __construct(
        private readonly AreaRepository $areas,
        private readonly ImageStorageService $images,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $scope = $request->getQueryParams()['scope'] ?? ($auth !== null ? 'mine_public' : 'public');
        ['limit' => $limit, 'offset' => $offset] = $this->parsePagination($request);
        $userId = in_array($scope, ['mine', 'mine_public'], true) ? (int) $this->requireAuthUser($request)['sub'] : null;

        $data = match ($scope) {
            'mine' => $this->areas->findByUser($userId, $limit, $offset),
            'mine_public' => $this->areas->findByUserWithPublic($userId, $limit, $offset),
            default => $this->areas->findPublic($limit, $offset),
        };

        $body = ['data' => $data];
        if ($limit !== null) {
            $body['meta'] = [
                'total' => match ($scope) {
                    'mine' => $this->areas->countByUser($userId),
                    'mine_public' => $this->areas->countByUserWithPublic($userId),
                    default => $this->areas->countPublic(),
                },
                'limit' => $limit,
                'offset' => $offset,
            ];
        }

        return $this->json($response, $body);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $area = $this->areas->findById((int) $args['id']);
        // A private area of someone else answers like a missing one.
        if (!$this->canView($request, $area)) {
            throw new NotFoundException("Area {$args['id']} not found.");
        }

        return $this->json($response, ['data' => $area]);
    }

    public function create(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $area = $this->areas->create((int) $auth['sub'], $this->jsonBody($request));

        return $this->json($response, ['data' => $area], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->areas->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);

        return $this->json($response, ['data' => $this->areas->update($id, $this->jsonBody($request))]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->areas->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);

        // files only after the row is gone: delete() can still refuse (stale expected_updated_at)
        $images = $this->areas->getImages($id);
        $expectedUpdatedAt = $request->getQueryParams()['expected_updated_at'] ?? null;
        $this->areas->delete($id, $expectedUpdatedAt);
        foreach ($images as $image) {
            $this->images->delete($id, $image['filename']);
        }

        return $this->json($response, ['data' => ['id' => $id]]);
    }

    // Photos: upload/list/delete/show come from EntityImageEndpoints.
    protected function imageRepository(): object
    {
        return $this->areas;
    }

    protected function imageEntityLabel(): string
    {
        return 'area';
    }

    protected function maxImages(): int
    {
        return self::MAX_IMAGES_PER_AREA;
    }

    protected function canViewEntity(Request $request, array $entity): bool
    {
        return $this->canView($request, $entity);
    }

    /** Public, own, or admin - show() and the photo endpoints. */
    private function canView(Request $request, array $area): bool
    {
        $auth = $request->getAttribute('auth');

        return (int) $area['public'] === 1
            || ($auth !== null && (
                (int) $auth['sub'] === (int) $area['user_id']
                || ($auth['is_admin'] ?? false)
            ));
    }
}
