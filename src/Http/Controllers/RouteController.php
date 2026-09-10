<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\CaptchaRequiredException;
use Ytan\Service\CaptchaService;
use Ytan\Service\TourNotificationService;

final class RouteController extends BaseController
{
    public function __construct(
        private readonly RouteRepository $routes,
        private readonly TourRepository $tours,
        private readonly CaptchaService $captcha,
        private readonly TourNotificationService $notifications,
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
            return $this->json($response, ['data' => $this->routes->findByTour((int) $params['tour_id'])]);
        }

        ['limit' => $limit, 'offset' => $offset] = $this->parsePagination($request);
        $userId = in_array($scope, ['mine', 'mine_public'], true) ? (int) $this->requireAuthUser($request)['sub'] : null;

        $data = match ($scope) {
            'mine' => $this->routes->findByUser($userId, $limit, $offset),
            'mine_public' => $this->routes->findByUserWithPublic($userId, $limit, $offset),
            default => $this->routes->findPublic($limit, $offset),
        };

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
        return $this->json($response, ['data' => $this->routes->findById((int) $args['id'])]);
    }

    public function create(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $route = $this->routes->create((int) $auth['sub'], $this->jsonBody($request));

        return $this->json($response, ['data' => $route], 201);
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

        return $this->json($response, ['data' => $updated]);
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
}
