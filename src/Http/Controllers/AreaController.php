<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Area\AreaRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\ImageStorageService;

final class AreaController extends BaseController
{
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
        return $this->json($response, ['data' => $this->areas->findById((int) $args['id'])]);
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
        $this->areas->delete($id);

        return $this->json($response, ['data' => ['id' => $id]]);
    }

    public function uploadImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $areaId = (int) $args['id'];
        $area = $this->areas->findById($areaId);
        $this->assertOwnerOrAdmin($auth, (int) $area['user_id']);

        if ($this->areas->countImages($areaId) >= self::MAX_IMAGES_PER_AREA) {
            throw new ValidationException('This area already has the maximum of ' . self::MAX_IMAGES_PER_AREA . ' photos.');
        }

        $file = $request->getUploadedFiles()['image'] ?? null;
        if ($file === null) {
            throw new ValidationException('No image uploaded.');
        }

        $stored = $this->images->store($areaId, $file);
        $images = $this->areas->addImage($areaId, $stored['filename'], $stored['mime_type'], $stored['size_bytes']);

        return $this->json($response, ['data' => $images], 201);
    }

    public function listImages(Request $request, Response $response, array $args): Response
    {
        $areaId = (int) $args['id'];
        $area = $this->areas->findById($areaId);
        $this->assertCanViewImages($request, $area);

        return $this->json($response, ['data' => $this->areas->getImages($areaId)]);
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $areaId = (int) $args['id'];
        $area = $this->areas->findById($areaId);
        $this->assertOwnerOrAdmin($auth, (int) $area['user_id']);

        $image = $this->areas->findImage($areaId, (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $this->images->delete($areaId, $image['filename']);
        $this->areas->removeImage($areaId, (int) $args['imageId']);

        return $this->json($response, ['data' => $this->areas->getImages($areaId)]);
    }

    public function showImage(Request $request, Response $response, array $args): Response
    {
        $areaId = (int) $args['id'];
        $area = $this->areas->findById($areaId);
        $this->assertCanViewImages($request, $area);

        $image = $this->areas->findImage($areaId, (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $file = $this->images->read($areaId, $image['filename']);
        if ($file === null) {
            throw new NotFoundException('Image not found.');
        }

        $response->getBody()->write($file['contents']);

        return $response->withHeader('Content-Type', $image['mime_type']);
    }

    /**
     * A public area's photos are visible to anyone (incl. anonymous
     * visitors); a private area's photos only to its owner or an admin -
     * same shape as TourController::showImage()'s $canView check.
     */
    private function assertCanViewImages(Request $request, array $area): void
    {
        $auth = $request->getAttribute('auth');

        $canView = (int) $area['public'] === 1
            || ($auth !== null && (
                (int) $auth['sub'] === (int) $area['user_id']
                || ($auth['is_admin'] ?? false)
            ));
        if (!$canView) {
            throw new ForbiddenException();
        }
    }
}
