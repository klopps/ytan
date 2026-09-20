<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\ImageStorageService;
use Ytan\Service\TourDocumentService;

final class TourController extends BaseController
{
    // Mirrored client-side by TOUR_PHOTO_MAX_COUNT (public/js/config.js) -
    // kept in sync manually, there being no shared config layer between PHP and JS.
    private const MAX_IMAGES_PER_TOUR = 9;

    public function __construct(
        private readonly TourRepository $tours,
        private readonly ImageStorageService $images,
        private readonly TourDocumentService $documents,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $params = $request->getQueryParams();
        $scope = $params['scope'] ?? ($auth !== null ? 'mine_public' : 'public');

        $userId = null;
        if (in_array($scope, ['mine', 'mine_public'], true)) {
            $userId = (int) $this->requireAuthUser($request)['sub'];
        }

        $filters = [
            'search' => $params['search'] ?? null,
        ];
        if (isset($params['min_length'])) {
            $filters['min_length'] = (int) $params['min_length'];
        }
        if (isset($params['max_length'])) {
            $filters['max_length'] = (int) $params['max_length'];
        }
        ['limit' => $limit, 'offset' => $offset] = $this->parsePagination($request);

        $data = $this->tours->search($scope, $userId, $filters, $limit, $offset);
        $data = $this->attachTags($data);

        $body = ['data' => $data];
        if ($limit !== null) {
            $body['meta'] = [
                'total' => $this->tours->countSearch($scope, $userId, $filters),
                'limit' => $limit,
                'offset' => $offset,
            ];
        }

        return $this->json($response, $body);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $tour = $this->tours->findById((int) $args['id']);
        $tour['tags'] = $this->tours->getTags((int) $tour['id']);
        $tour['images'] = $this->tours->getImages((int) $tour['id']);

        return $this->json($response, ['data' => $tour]);
    }

    public function create(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $this->assertTourRight($auth, 'tour_create');

        $data = $this->jsonBody($request);
        $this->assertValidTourData($data);

        $tour = $this->tours->create((int) $auth['sub'], $data);
        $this->tours->setTags((int) $tour['id'], $data['tags'] ?? []);
        $tour = $this->tours->findById((int) $tour['id']);
        $tour['tags'] = $this->tours->getTags((int) $tour['id']);

        return $this->json($response, ['data' => $tour], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->tours->findById($id);
        $this->assertCanManageTour($auth, $existing);

        $data = $this->jsonBody($request);
        $this->assertValidTourData($data);

        $tour = $this->tours->update($id, $data);
        $this->tours->setTags($id, $data['tags'] ?? []);
        $tour['tags'] = $this->tours->getTags($id);

        return $this->json($response, ['data' => $tour]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->tours->findById($id);
        $this->assertCanManageTour($auth, $existing);

        foreach ($this->tours->getImages($id) as $image) {
            $this->images->delete($id, $image['filename']);
        }
        $this->tours->delete($id);

        return $this->json($response, ['data' => ['id' => $id]]);
    }

    public function publish(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $tour = $this->tours->findById($id);
        $this->assertCanPublishTour($auth, $tour);

        $makePublic = (bool) ($this->jsonBody($request)['public'] ?? false);
        $tour = $this->tours->setPublished($id, $makePublic, $makePublic ? (int) $auth['sub'] : null);
        $tour['tags'] = $this->tours->getTags($id);

        return $this->json($response, ['data' => $tour]);
    }

    public function copy(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $this->assertTourRight($auth, 'tour_copy');

        $id = (int) $args['id'];
        $source = $this->tours->findById($id);

        $canView = (int) $auth['sub'] === (int) $source['user_id']
            || (int) $source['public'] === 1
            || ($auth['is_admin'] ?? false)
            || ($auth['tour_manage'] ?? false);
        if (!$canView) {
            throw new ForbiddenException();
        }

        $copy = $this->tours->copy($id, (int) $auth['sub']);
        $copy['tags'] = $this->tours->getTags((int) $copy['id']);

        return $this->json($response, ['data' => $copy], 201);
    }

    public function addRoute(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $tourId = (int) $args['id'];
        $tour = $this->tours->findById($tourId);
        $this->assertCanManageTour($auth, $tour);

        $routeId = (int) ($this->jsonBody($request)['route_id'] ?? 0);
        if ($routeId <= 0) {
            throw new ValidationException('route_id is required.');
        }

        return $this->json($response, ['data' => $this->tours->addRoute($tourId, $routeId)]);
    }

    public function removeRoute(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $tourId = (int) $args['id'];
        $tour = $this->tours->findById($tourId);
        $this->assertCanManageTour($auth, $tour);

        return $this->json($response, ['data' => $this->tours->removeRoute($tourId, (int) $args['routeId'])]);
    }

    public function reorderRoutes(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $tourId = (int) $args['id'];
        $tour = $this->tours->findById($tourId);
        $this->assertCanManageTour($auth, $tour);

        $routeIds = $this->jsonBody($request)['route_ids'] ?? [];

        return $this->json($response, ['data' => $this->tours->reorderRoutes($tourId, $routeIds)]);
    }

    public function uploadImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $tourId = (int) $args['id'];
        $tour = $this->tours->findById($tourId);
        $this->assertCanManageTour($auth, $tour);

        if ($this->tours->countImages($tourId) >= self::MAX_IMAGES_PER_TOUR) {
            throw new ValidationException('This tour already has the maximum of ' . self::MAX_IMAGES_PER_TOUR . ' photos.');
        }

        $file = $request->getUploadedFiles()['image'] ?? null;
        if ($file === null) {
            throw new ValidationException('No image uploaded.');
        }

        $stored = $this->images->store($tourId, $file);
        $images = $this->tours->addImage($tourId, $stored['filename'], $stored['mime_type'], $stored['size_bytes']);

        return $this->json($response, ['data' => $images], 201);
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $tourId = (int) $args['id'];
        $tour = $this->tours->findById($tourId);
        $this->assertCanManageTour($auth, $tour);

        $image = $this->tours->findImage($tourId, (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $this->images->delete($tourId, $image['filename']);
        $this->tours->removeImage($tourId, (int) $args['imageId']);

        return $this->json($response, ['data' => $this->tours->getImages($tourId)]);
    }

    public function reorderImages(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $tourId = (int) $args['id'];
        $tour = $this->tours->findById($tourId);
        $this->assertCanManageTour($auth, $tour);

        $imageIds = $this->jsonBody($request)['image_ids'] ?? [];
        $this->tours->reorderImages($tourId, $imageIds);

        return $this->json($response, ['data' => $this->tours->getImages($tourId)]);
    }

    public function showImage(Request $request, Response $response, array $args): Response
    {
        $tourId = (int) $args['id'];
        $tour = $this->tours->findById($tourId);
        $this->assertCanView($request->getAttribute('auth'), $tour);

        $image = $this->tours->findImage($tourId, (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $file = $this->images->read($tourId, $image['filename']);
        if ($file === null) {
            throw new NotFoundException('Image not found.');
        }

        $response->getBody()->write($file['contents']);

        return $response->withHeader('Content-Type', $image['mime_type']);
    }

    public function document(Request $request, Response $response, array $args): Response
    {
        $tourId = (int) $args['id'];
        $tour = $this->tours->findById($tourId);
        $this->assertCanView($request->getAttribute('auth'), $tour);

        $poiRadius = TourDocumentService::DEFAULT_POI_PROXIMITY_METERS;
        $poiRadiusParam = $request->getQueryParams()['poi_radius'] ?? null;
        if ($poiRadiusParam !== null) {
            if (!is_numeric($poiRadiusParam) || (float) $poiRadiusParam <= 0) {
                throw new ValidationException('poi_radius must be a positive number.');
            }
            $poiRadius = (float) $poiRadiusParam;
        }

        $pdf = $this->documents->generate($tourId, $poiRadius);

        $response->getBody()->write($pdf);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="tour-' . $tourId . '.pdf"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Same visibility rule as everywhere else in this controller: public,
     * or the owner/admin/tour_manage. Shared by showImage() and
     * document(), which need an identical check.
     */
    private function assertCanView(?array $auth, array $tour): void
    {
        $canView = (int) $tour['public'] === 1
            || ($auth !== null && (
                (int) $auth['sub'] === (int) $tour['user_id']
                || ($auth['is_admin'] ?? false)
                || ($auth['tour_manage'] ?? false)
            ));
        if (!$canView) {
            throw new ForbiddenException();
        }
    }

    /**
     * `is_admin`/`tour_manage` can edit/delete any tour; otherwise the
     * caller must both own it AND still hold `tour_create` - Touren.md
     * bundles "erstellen, bearbeiten und löschen" under one right, so
     * losing it also loses edit/delete on tours already created with it.
     */
    private function assertCanManageTour(array $auth, array $tour): void
    {
        if (($auth['is_admin'] ?? false) || ($auth['tour_manage'] ?? false)) {
            return;
        }
        if ((int) $auth['sub'] === (int) $tour['user_id'] && ($auth['tour_create'] ?? false)) {
            return;
        }
        throw new ForbiddenException();
    }

    private function assertCanPublishTour(array $auth, array $tour): void
    {
        if (($auth['is_admin'] ?? false) || ($auth['tour_manage'] ?? false)) {
            return;
        }
        if ((int) $auth['sub'] === (int) $tour['user_id'] && ($auth['tour_publish'] ?? false)) {
            return;
        }
        throw new ForbiddenException();
    }

    private function assertValidTourData(array $data): void
    {
        if (!isset($data['name']) || mb_strlen(trim((string) $data['name'])) < 3) {
            throw new ValidationException('Name must be at least 3 characters.');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $tours
     */
    private function attachTags(array $tours): array
    {
        $ids = array_map(fn (array $t) => (int) $t['id'], $tours);
        $tagsByTour = $this->tours->getTagsForTours($ids);

        foreach ($tours as &$tour) {
            $tour['tags'] = $tagsByTour[(int) $tour['id']] ?? [];
        }

        return $tours;
    }
}
