<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Tour\TourRepository;

final class TourController extends BaseController
{
    public function __construct(private readonly TourRepository $tours)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $scope = $request->getQueryParams()['scope'] ?? ($auth !== null ? 'mine_public' : 'public');

        $data = match ($scope) {
            'mine' => $this->tours->findByUser($this->requireAuthUser($request)['sub']),
            'mine_public' => $this->tours->findByUserWithPublic($this->requireAuthUser($request)['sub']),
            default => $this->tours->findPublic(),
        };

        return $this->json($response, ['data' => $data]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->json($response, ['data' => $this->tours->findById((int) $args['id'])]);
    }
}
