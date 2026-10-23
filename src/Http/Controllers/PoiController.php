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
use Ytan\Service\Water\PoiWaterDistanceService;

final class PoiController extends BaseController
{
    // Mirrored client-side by POI_PHOTO_MAX_COUNT (public/js/config.js) -
    // kept in sync manually, there being no shared config layer between PHP and JS.
    private const MAX_IMAGES_PER_POI = 5;

    /** data_source columns PoiRepository joins onto every row, => key in `data_sources`. */
    private const SOURCE_FIELDS = [
        'source_name' => 'name',
        'source_url' => 'url',
        'source_license' => 'license',
        'source_license_url' => 'license_url',
        'source_attribution' => 'attribution',
    ];

    /**
     * Moves the joined data_source columns out of a POI list: every imported
     * lighthouse repeats them (~190 KB in a full list), so the list sends
     * them once per source as `data_sources` ({code: {name, url, ...}}) and
     * each row keeps only its `data_source` code. poi.js re-attaches them.
     *
     * @param array<int, array<string, mixed>> $pois
     * @return array{pois: array<int, array<string, mixed>>, sources: array<string, array<string, mixed>>}
     */
    private static function extractDataSources(array $pois): array
    {
        $sources = [];
        foreach ($pois as &$poi) {
            $code = $poi['data_source'] ?? null;
            if ($code !== null && $code !== '' && !isset($sources[$code])) {
                foreach (self::SOURCE_FIELDS as $column => $key) {
                    $sources[$code][$key] = $poi[$column] ?? null;
                }
            }
            foreach (self::SOURCE_FIELDS as $column => $key) {
                unset($poi[$column]);
            }
        }
        unset($poi);

        return ['pois' => $pois, 'sources' => $sources];
    }

    /**
     * Replaces the joined poi_water_distance columns (water_sea_m/_river_m/
     * _lake_m) by one compact `water` field: [sea, river, lake] in metres
     * (each null = farther than 100 km) or null when nothing is stored.
     * Shown in the POI InfoWindow (poi.js).
     *
     * @param array<string, mixed> $poi
     * @return array<string, mixed>
     */
    private static function packWaterDistances(array $poi): array
    {
        $water = [];
        foreach (['water_sea_m', 'water_river_m', 'water_lake_m'] as $column) {
            $water[] = isset($poi[$column]) ? (int) $poi[$column] : null;
            unset($poi[$column]);
        }
        // A row exists only when computed - but all three can still be null.
        $poi['water'] = $water === [null, null, null] ? null : $water;

        return $poi;
    }

    public function __construct(
        private readonly PoiRepository $pois,
        private readonly ImageStorageService $images,
        private readonly ?PoiWaterDistanceService $waterDistances = null,
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

        $data = array_map(self::packWaterDistances(...), $data);
        ['pois' => $data, 'sources' => $sources] = self::extractDataSources($data);
        $body = ['data' => $data, 'data_sources' => (object) $sources];
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
        $poi = $this->pois->findById((int) $args['id']);
        // A private POI of someone else answers like a missing one.
        if (!$this->canView($request, $poi)) {
            throw new NotFoundException("POI {$args['id']} not found.");
        }

        return $this->json($response, ['data' => self::packWaterDistances($poi)]);
    }

    public function create(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $poi = $this->pois->create((int) $auth['sub'], $this->jsonBody($request));
        $poi = $this->withFreshWaterDistances($poi);

        return $this->json($response, ['data' => self::packWaterDistances($poi)], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->pois->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);

        $poi = $this->pois->update($id, $this->jsonBody($request));
        $poi = $this->withFreshWaterDistances($poi, $existing);

        return $this->json($response, ['data' => self::packWaterDistances($poi)]);
    }

    /**
     * Updates poi_water_distance for a saved POI and re-reads the row, so the
     * answer carries the distances just computed (or dropped).
     *
     * @param array<string, mixed> $poi
     * @param array<string, mixed>|null $before
     * @return array<string, mixed>
     */
    private function withFreshWaterDistances(array $poi, ?array $before = null): array
    {
        if ($this->waterDistances === null) {
            return $poi;
        }
        $this->waterDistances->afterSave($poi, $before);

        return $this->pois->findById((int) $poi['id']);
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
        $expectedUpdatedAt = $request->getQueryParams()['expected_updated_at'] ?? null;
        $this->pois->delete($id, $expectedUpdatedAt);

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
        if (!$this->canView($request, $poi)) {
            throw new ForbiddenException();
        }
    }

    /** Public, own, or admin - show() and the photo endpoints. */
    private function canView(Request $request, array $poi): bool
    {
        $auth = $request->getAttribute('auth');

        return (int) $poi['public'] === 1
            || ($auth !== null && (
                (int) $auth['sub'] === (int) $poi['user_id']
                || ($auth['is_admin'] ?? false)
            ));
    }
}
