<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Service\AdminHealthService;

/**
 * GET /api/v1/admin/health - the /admin dashboard's "Probleme" card
 * (admin-dashboard.js): failed/stalled jobs and stale data, see AdminHealthService.
 */
final class AdminHealthController extends BaseController
{
    public function __construct(private readonly AdminHealthService $health)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => ['issues' => $this->health->issues(), 'checked_at' => gmdate('Y-m-d\TH:i:s\Z')]]);
    }
}
