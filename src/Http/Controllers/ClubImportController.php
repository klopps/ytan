<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\ClubImport\ClubImportRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\ClubImportFile;
use Ytan\Service\Log\ImportLog;
use Ytan\Service\ClubImportService;

/**
 * Backend of the admin tool "Club import" (templates/admin-club-import.php):
 * lists the staged canoe/rowing clubs (paginated, filterable), returns the
 * ids of all matches (loading a country from OpenStreetMap runs in the
 * background, OsmImportJobController), imports a
 * selection as POIs and moves the staged list between systems as a file.
 * Admin only.
 */
final class ClubImportController extends BaseController
{
    private const FILTERS = ['name', 'city', 'website', 'country', 'kind', 'status'];

    public function __construct(
        private readonly ClubImportRepository $staging,
        private readonly ClubImportService $importService,
        private readonly ImportLog $log = new ImportLog(),
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
        $authUser = $this->requireAdmin($request);
        $body = $this->jsonBody($request);

        return $this->json($response, ['data' => $this->log->run(
            $authUser,
            'read transfer file',
            ['entries' => $body['entries'] ?? null],
            fn () => $this->staging->upsertMany(ClubImportFile::parse($body))
        )]);
    }

    public function import(Request $request, Response $response): Response
    {
        $authUser = $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        $ids = $body['ids'] ?? null;

        return $this->json($response, ['data' => $this->log->run(
            $authUser,
            'import as POIs',
            ['selected' => is_array($ids) ? count($ids) : null, 'public' => (bool) ($body['public'] ?? false)],
            function () use ($ids, $authUser, $body) {
                if (!is_array($ids) || $ids === []) {
                    throw new ValidationException('ids must be a non-empty list.');
                }

                return $this->importService->import(
                    array_map('intval', $ids),
                    (int) $authUser['sub'],
                    (bool) ($body['public'] ?? false)
                );
            }
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
