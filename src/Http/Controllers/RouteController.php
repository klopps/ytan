<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Route\RouteRepository;

final class RouteController extends BaseController
{
    public function __construct(private readonly RouteRepository $routes)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $params = $request->getQueryParams();
        $scope = $params['scope'] ?? ($auth !== null ? 'mine_public' : 'public');

        if (isset($params['tour_id'])) {
            $data = $this->routes->findByTour((int) $params['tour_id']);
        } else {
            $data = match ($scope) {
                'mine' => $this->routes->findByUser($this->requireAuthUser($request)['sub']),
                'mine_public' => $this->routes->findByUserWithPublic($this->requireAuthUser($request)['sub']),
                default => $this->routes->findPublic(),
            };
        }

        return $this->json($response, ['data' => $data]);
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

        return $this->json($response, ['data' => $this->routes->update($id, $this->jsonBody($request))]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $id = (int) $args['id'];
        $existing = $this->routes->findById($id);
        $this->assertOwnerOrAdmin($auth, (int) $existing['user_id']);
        $this->routes->delete($id);

        return $this->json($response, ['data' => ['id' => $id]]);
    }
}
