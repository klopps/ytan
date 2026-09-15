<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\CaptchaRequiredException;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\CaptchaService;
use Ytan\Service\ImageStorageService;
use Ytan\Service\TourNotificationService;

final class RouteController extends BaseController
{
    // Mirrored client-side by ROUTE_PHOTO_MAX_COUNT (public/js/config.js) -
    // kept in sync manually, there being no shared config layer between PHP and JS.
    private const MAX_IMAGES_PER_ROUTE = 5;

    public function __construct(
        private readonly RouteRepository $routes,
        private readonly TourRepository $tours,
        private readonly CaptchaService $captcha,
        private readonly TourNotificationService $notifications,
        private readonly ImageStorageService $images,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $params = $request->getQueryParams();
        $scope = $params['scope'] ?? ($auth !== null ? 'mine_public' : 'public');

        if (isset($params['tour_id'])) {
            // A tour's own route list is inherently small (bounded by how
            // many routes a tour can reasonably contain) - no pagination
            // needed here, unlike the scope-based branches below.
            $data = array_map(
                fn (array $route) => $this->redactRecordingInfo($route, $auth),
                $this->routes->findByTour((int) $params['tour_id'])
            );

            return $this->json($response, ['data' => $data]);
        }

        ['limit' => $limit, 'offset' => $offset] = $this->parsePagination($request);
        $userId = in_array($scope, ['mine', 'mine_public'], true) ? (int) $this->requireAuthUser($request)['sub'] : null;

        $data = match ($scope) {
            'mine' => $this->routes->findByUser($userId, $limit, $offset),
            'mine_public' => $this->routes->findByUserWithPublic($userId, $limit, $offset),
            default => $this->routes->findPublic($limit, $offset),
        };
        $data = array_map(fn (array $route) => $this->redactRecordingInfo($route, $auth), $data);

        $body = ['data' => $data];
        if ($limit !== null) {
            $body['meta'] = [
                'total' => match ($scope) {
                    'mine' => $this->routes->countByUser($userId),
                    'mine_public' => $this->routes->countByUserWithPublic($userId),
                    default => $this->routes->countPublic(),
                },
                'limit' => $limit,
                'offset' => $offset,
            ];
        }

        return $this->json($response, $body);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $auth = $request->getAttribute('auth');
        $route = $this->redactRecordingInfo($this->routes->findById((int) $args['id']), $auth);

        return $this->json($response, ['data' => $route]);
    }

    public function create(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $route = $this->routes->create((int) $auth['sub'], $this->jsonBody($request));

        return $this->json($response, ['data' => $this->redactRecordingInfo($route, $auth)], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->routes->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);

        $updated = $this->routes->update($id, $this->jsonBody($request));

        // A route's length can change here (re-drawn geometry) - any tour
        // containing it caches a total_length that's now stale (Touren.md:
        // "... ändert sich die Länge einer Route, muss die Gesamtlänge neu
        // berechnet werden"), unlike TourController's own add/remove/reorder
        // endpoints which recalculate as part of the same request.
        $affectedTours = $this->tours->findByRoute($id);
        foreach ($affectedTours as $tour) {
            $this->tours->recalculateTotalLength((int) $tour['id']);
        }
        if ($affectedTours !== []) {
            $this->notifications->notifyRouteChanged($affectedTours, $updated);
        }

        return $this->json($response, ['data' => $this->redactRecordingInfo($updated, $auth)]);
    }

    /**
     * Two-phase when the route belongs to >=1 tours (Touren.md's "warn +
     * simple captcha before deleting a tour route" flow): a first call with
     * no (or an invalid/expired) captcha_token/captcha_answer throws
     * CaptchaRequiredException, which App.php's error handler turns into a
     * 422 carrying a fresh challenge; the client re-issues the same DELETE
     * with that token + the user's answer attached to actually proceed.
     * Routes not in any tour delete immediately, same as before.
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->routes->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);

        $affectedTours = $this->tours->findByRoute($id);

        if ($affectedTours !== []) {
            $params = $request->getQueryParams();
            $verified = isset($params['captcha_token'], $params['captcha_answer'])
                && $this->captcha->verify((string) $params['captcha_token'], (int) $params['captcha_answer'], "route:$id");

            if (!$verified) {
                $challenge = $this->captcha->issueChallenge("route:$id");
                throw new CaptchaRequiredException(count($affectedTours), $challenge['question'], $challenge['token']);
            }
        }

        $wasPublic = (bool) $existing['public'];

        $this->routes->delete($id);

        foreach ($affectedTours as $tour) {
            $this->tours->recalculateTotalLength((int) $tour['id']);
        }

        if ($affectedTours !== []) {
            $this->notifications->notifyRouteDeleted($affectedTours, $existing);
        }
        if ($wasPublic) {
            $this->notifications->notifyRouteAutoUnpublished($existing);
        }

        return $this->json($response, ['data' => ['id' => $id]]);
    }

    /**
     * recorded_at/recording_duration_seconds/recorded_by_username (see
     * RouteRepository's SELECT_WITH_RECORDER join and migration 017/018)
     * are visible only to admins and users with the route_view_recording
     * right - everyone else gets a route row with these three keys
     * removed entirely, not just null-ed, so the frontend can't
     * distinguish "not recorded" from "recorded, but hidden from you".
     */
    private function redactRecordingInfo(array $route, ?array $auth): array
    {
        if (!$this->hasRight($auth, 'route_view_recording')) {
            unset($route['recorded_at'], $route['recording_duration_seconds'], $route['recorded_by_username']);
        }

        return $route;
    }

    public function uploadImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $routeId = (int) $args['id'];
        $route = $this->routes->findById($routeId);
        $this->assertOwnerOrAdmin($auth, (int) $route['user_id']);

        if ($this->routes->countImages($routeId) >= self::MAX_IMAGES_PER_ROUTE) {
            throw new ValidationException('This route already has the maximum of ' . self::MAX_IMAGES_PER_ROUTE . ' photos.');
        }

        $file = $request->getUploadedFiles()['image'] ?? null;
        if ($file === null) {
            throw new ValidationException('No image uploaded.');
        }

        $stored = $this->images->store($routeId, $file);
        $images = $this->routes->addImage($routeId, $stored['filename'], $stored['mime_type'], $stored['size_bytes']);

        return $this->json($response, ['data' => $images], 201);
    }

    public function listImages(Request $request, Response $response, array $args): Response
    {
        $routeId = (int) $args['id'];
        $route = $this->routes->findById($routeId);
        $this->assertCanViewImages($request, $route);

        return $this->json($response, ['data' => $this->routes->getImages($routeId)]);
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $routeId = (int) $args['id'];
        $route = $this->routes->findById($routeId);
        $this->assertOwnerOrAdmin($auth, (int) $route['user_id']);

        $image = $this->routes->findImage($routeId, (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $this->images->delete($routeId, $image['filename']);
        $this->routes->removeImage($routeId, (int) $args['imageId']);

        return $this->json($response, ['data' => $this->routes->getImages($routeId)]);
    }

    public function showImage(Request $request, Response $response, array $args): Response
    {
        $routeId = (int) $args['id'];
        $route = $this->routes->findById($routeId);
        $this->assertCanViewImages($request, $route);

        $image = $this->routes->findImage($routeId, (int) $args['imageId']);
        if ($image === null) {
            throw new NotFoundException('Image not found.');
        }

        $file = $this->images->read($routeId, $image['filename']);
        if ($file === null) {
            throw new NotFoundException('Image not found.');
        }

        $response->getBody()->write($file['contents']);

        return $response->withHeader('Content-Type', $image['mime_type']);
    }

    /**
     * A public route's photos are visible to anyone (incl. anonymous
     * visitors); a private route's photos only to its owner or an admin -
     * same shape as TourController::showImage()'s $canView check.
     */
    private function assertCanViewImages(Request $request, array $route): void
    {
        $auth = $request->getAttribute('auth');

        $canView = (int) $route['public'] === 1
            || ($auth !== null && (
                (int) $auth['sub'] === (int) $route['user_id']
                || ($auth['is_admin'] ?? false)
            ));
        if (!$canView) {
            throw new ForbiddenException();
        }
    }
}
