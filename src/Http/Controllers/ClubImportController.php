<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\ClubImport\ClubImportRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\ClubImportFile;
use Ytan\Service\ClubImportService;
use Ytan\Service\ClubOsmImportService;

/**
 * Backend of the admin tool "Club import" (templates/admin-club-import.php):
 * lists the staged canoe/rowing clubs (paginated, filterable), returns the
 * ids of all matches, loads one country from OpenStreetMap, imports a
 * selection as POIs and moves the staged list between systems as a file.
 * Admin only.
 */
final class ClubImportController extends BaseController
{
    private const FILTERS = ['name', 'city', 'website', 'country', 'kind', 'status'];

    public function __construct(
        private readonly ClubImportRepository $staging,
        private readonly ClubImportService $importService,
        private readonly ?ClubOsmImportService $osmImport = null,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $filters = $this->filters($request);
        ['limit' => $limit, 'offset' => $offset] = $this->parsePagination($request);

        return $this->json($response, [
            'data' => $this->staging->search($filters, $limit, $offset),
            'meta' => [
                'total' => $this->staging->count($filters),
                'open' => $this->staging->countOpen($filters),
                'limit' => $limit,
                'offset' => $offset,
                'countries' => $this->staging->countries(),
            ],
        ]);
    }

    public function ids(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->staging->ids($this->filters($request))]);
    }

    public function exportFile(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => ClubImportFile::build($this->staging->all())]);
    }

    /** Reads a transfer file (the request body is its JSON): new entries are added, existing ones refreshed. */
    public function importFile(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->staging->upsertMany(ClubImportFile::parse($this->jsonBody($request)))]);
    }

    /** Loads the clubs of one country from OpenStreetMap into the staging table: body {country}. */
    public function loadOsm(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        if ($this->osmImport === null) {
            throw new ValidationException('The OpenStreetMap import is not configured.');
        }

        return $this->json($response, ['data' => $this->osmImport->import((string) ($this->jsonBody($request)['country'] ?? ''))]);
    }

    public function import(Request $request, Response $response): Response
    {
        $authUser = $this->requireAdmin($request);
        $body = $this->jsonBody($request);

        $ids = $body['ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            throw new ValidationException('ids must be a non-empty list.');
        }

        return $this->json($response, ['data' => $this->importService->import(
            array_map('intval', $ids),
            (int) $authUser['sub'],
            (bool) ($body['public'] ?? false)
        )]);
    }

    /** @return array<string,string> */
    private function filters(Request $request): array
    {
        $params = $request->getQueryParams();
        $filters = [];
        foreach (self::FILTERS as $name) {
            if (isset($params[$name]) && is_string($params[$name])) {
                $filters[$name] = $params[$name];
            }
        }

        return $filters;
    }
}
