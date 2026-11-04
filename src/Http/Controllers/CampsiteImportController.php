<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\CampsiteImport\CampsiteImportRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\CampsiteImportFile;
use Ytan\Service\CampsiteImportService;
use Ytan\Service\CampsiteOsmImportService;
use Ytan\Service\Water\WaterDistanceService;

/**
 * Backend of the admin tool "Campingplatz-Import" (templates/admin-campsite-import.php):
 * lists the staged campsites (paginated, filterable), returns the ids of all
 * matches, loads the campsites of one country from OpenStreetMap - their
 * distances to water are computed while loading, from the local water index -
 * imports a selection as POIs and moves the staged list between systems as a
 * file. Every listed entry comes with its possible duplicates (`neighbours`).
 * Admin only.
 */
final class CampsiteImportController extends BaseController
{
    private const FILTERS = [
        'name', 'place', 'description', 'country', 'category', 'tents', 'caravans', 'cabins',
        'water', 'max_sea', 'max_river', 'max_lake', 'max_any', 'status', 'duplicate',
    ];

    public function __construct(
        private readonly CampsiteImportRepository $staging,
        private readonly CampsiteImportService $importService,
        private readonly ?CampsiteOsmImportService $osmImport = null,
        private readonly ?WaterDistanceService $water = null,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $filters = $this->filters($request);
        ['limit' => $limit, 'offset' => $offset] = $this->parsePagination($request);
        $rows = $this->staging->search($filters, $limit, $offset);
        $neighbours = $this->staging->neighbours($rows);
        foreach ($rows as &$row) {
            $row['neighbours'] = $neighbours[(int) $row['id']] ?? [];
        }
        unset($row);

        return $this->json($response, [
            'data' => $rows,
            'meta' => [
                'total' => $this->staging->count($filters),
                'open' => $this->staging->countOpen($filters),
                'unchecked' => $this->staging->countUnchecked(),
                'water_index' => $this->water !== null && $this->water->available() ? $this->water->version() ?? '' : null,
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

        return $this->json($response, ['data' => CampsiteImportFile::build($this->staging->all())]);
    }

    /** Reads a transfer file (the request body is its JSON): new entries are added, existing ones refreshed. */
    public function importFile(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->staging->upsertMany(CampsiteImportFile::parse($this->jsonBody($request)))]);
    }

    /** Loads all campsites of one country from OpenStreetMap: body {country}. */
    public function loadOsm(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        if ($this->osmImport === null) {
            throw new ValidationException('The OpenStreetMap import is not configured.');
        }
        // The query alone may take minutes (place/street lookups); Windows counts wall time.
        set_time_limit(CampsiteOsmImportService::REQUEST_TIMEOUT_SECONDS + 60);

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
