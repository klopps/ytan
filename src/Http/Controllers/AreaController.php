<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Area\AreaRepository;

final class AreaController extends BaseController
{
    public function __construct(private readonly AreaRepository $areas)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $scope = $request->getQueryParams()['scope'] ?? ($auth !== null ? 'mine_public' : 'public');

        $data = match ($scope) {
            'mine' => $this->areas->findByUser($this->requireAuthUser($request)['sub']),
            'mine_public' => $this->areas->findByUserWithPublic($this->requireAuthUser($request)['sub']),
            default => $this->areas->findPublic(),
        };

        return $this->json($response, ['data' => $data]);
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
}
