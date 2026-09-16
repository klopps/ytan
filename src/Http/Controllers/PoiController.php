<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\ImageStorageService;

final class PoiController extends BaseController
{
    // Mirrored client-side by POI_PHOTO_MAX_COUNT (public/js/config.js) -
    // kept in sync manually, there being no shared config layer between PHP and JS.
    private const MAX_IMAGES_PER_POI = 5;

    public function __construct(
        private readonly PoiRepository $pois,
        private readonly ImageStorageService $images,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $scope = $request->getQueryParams()['scope'] ?? ($auth !== null ? 'mine_public' : 'public');
        ['limit' => $limit, 'offset' => $offset] = $this->parsePagination($request);

        if ($scope === 'all') {
            $this->requireAdmin($request);
        }
        $userId = in_array($scope, ['mine', 'mine_public'], true) ? (int) $this->requireAuthUser($request)['sub'] : null;

        $data = match ($scope) {
            'mine' => $this->pois->findByUser($userId, $limit, $offset),
            'mine_public' => $this->pois->findByUserWithPublic($userId, $limit, $offset),
            'all' => $this->pois->findAll($limit, $offset),
            default => $this->pois->findPublic($limit, $offset),
        };

        $body = ['data' => $data];
        if ($limit !== null) {
            $body['meta'] = [
                'total' => match ($scope) {
                    'mine' => $this->pois->countByUser($userId),
                    'mine_public' => $this->pois->countByUserWithPublic($userId),
                    'all' => $this->pois->countAll(),
                    default => $this->pois->countPublic(),
                },
                'limit' => $limit,
                'offset' => $offset,
            ];
        }

        return $this->json($response, $body);
    }

    public function bounds(Request $request, Response $response): Response
    {
        return $this->json($response, ['data' => $this->pois->bounds()]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['data' => $this->pois->findById((int) $args['id'])]);
    }

    public function create(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $poi = $this->pois->create((int) $auth['sub'], $this->jsonBody($request));

        return $this->json($response, ['data' => $poi], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->pois->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);

        return $this->json($response, ['data' => $this->pois->update($id, $this->jsonBody($request))]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->pois->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);

        foreach ($this->pois->getImages($id) as $image) {
            $this->images->delete($id, $image['filename']);
        }
        $this->pois->delete($id);

        return $this->json($response, ['data' => ['id' => $id]]);
    }

    public function uploadImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $poiId = (int) $args['id'];
        $poi = $this->pois->findById($poiId);
        $this->assertOwnerOrAdmin($auth, (int) $poi['user_id']);

        if ($this->pois->countImages($poiId) >= self::MAX_IMAGES_PER_POI) {
            throw new ValidationException('This POI already has the maximum of ' . self::MAX_IMAGES_PER_POI . ' photos.');
        }

        $file = $request->getUploadedFiles()['image'] ?? null;
        if ($file === null) {
            throw new ValidationException('No image uploaded.');
        }

        $stored = $this->images->store($poiId, $file);
        $images = $this->pois->addImage($poiId, $stored['filename'], $stored['mime_type'], $stored['size_bytes']);

        return $this->json($response, ['data' => $images], 201);
    }

    public function listImages(Request $request, Response $response, array $args): Response
    {
        $poiId = (int) $args['id'];
        $poi = $this->pois->findById($poiId);
        $this->assertCanViewImages($request, $poi);

        return $this->json($response, ['data' => $this->pois->getImages($poiId)]);
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $poiId = (int) $args['id'];
        $poi = $this->pois->findById($poiId);
        $this->assertOwnerOrAdmin($auth, (int) $poi['user_id']);

        $image = $this->pois->findImage($poiId, (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $this->images->delete($poiId, $image['filename']);
        $this->pois->removeImage($poiId, (int) $args['imageId']);

        return $this->json($response, ['data' => $this->pois->getImages($poiId)]);
    }

    public function showImage(Request $request, Response $response, array $args): Response
    {
        $poiId = (int) $args['id'];
        $poi = $this->pois->findById($poiId);
        $this->assertCanViewImages($request, $poi);

        $image = $this->pois->findImage($poiId, (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $file = $this->images->read($poiId, $image['filename']);
        if ($file === null) {
            throw new NotFoundException('Image not found.');
        }

        $response->getBody()->write($file['contents']);

        return $response->withHeader('Content-Type', $image['mime_type']);
    }

    /**
     * A public POI's photos are visible to anyone (incl. anonymous
     * visitors); a private POI's photos only to its owner or an admin -
     * same shape as TourController::showImage()'s $canView check.
     */
    private function assertCanViewImages(Request $request, array $poi): void
    {
        $auth = $request->getAttribute('auth');

        $canView = (int) $poi['public'] === 1
            || ($auth !== null && (
                (int) $auth['sub'] === (int) $poi['user_id']
                || ($auth['is_admin'] ?? false)
            ));
        if (!$canView) {
            throw new ForbiddenException();
        }
    }
}
