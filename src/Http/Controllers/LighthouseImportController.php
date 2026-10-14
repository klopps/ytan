<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\LighthouseImportFile;
use Ytan\Service\LighthouseImportService;

/**
 * Backend of the admin tool "Lighthouse import" (templates/admin-lighthouse-import.php):
 * lists the staged entries of the official lighthouse lists (paginated and
 * filterable), returns the ids of all matches (for "select all") and
 * imports a selection as lighthouse POIs. Admin only. An entry whose
 * international number already belongs to a lighthouse (or occurs twice in
 * the selection) is a conflict: while on_conflict is "ask" (the default) and
 * a conflicting entry has no entry in decisions, the request imports nothing
 * and returns the conflicts in data.conflicts; the admin then repeats it with
 * decisions (staging id => "add", "overwrite" or "skip") for them. Sectors the list gives no range for get the range of their colour
 * from default_ranges (W/R/G, nautical miles; default 3/2/2), and the POI's
 * description says so.
 */
final class LighthouseImportController extends BaseController
{
    private const FILTERS = ['dk_no', 'international_id', 'region', 'subregion', 'name', 'location', 'source', 'status'];

    public function __construct(
        private readonly LighthouseImportRepository $staging,
        private readonly LighthouseImportService $importService,
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
                'limit' => $limit,
                'offset' => $offset,
                'regions' => $this->staging->regions(),
                'subregions' => $this->staging->subregions(),
            ],
        ]);
    }

    public function ids(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->staging->ids($this->filters($request))]);
    }

    /** The transfer file with every staged entry (to be read by importFile() on another system). */
    public function exportFile(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => LighthouseImportFile::build($this->staging->all())]);
    }

    /**
     * Reads a transfer file (the request body is the file's JSON): new
     * entries are added, existing ones (same source + source_key) refreshed,
     * and entries already imported stay marked as imported.
     */
    public function importFile(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        $bySource = LighthouseImportFile::parse($this->jsonBody($request));
        $inserted = 0;
        $updated = 0;
        foreach ($bySource as $source => $entries) {
            $result = $this->staging->upsertMany((string) $source, $entries);
            $inserted += $result['inserted'];
            $updated += $result['updated'];
        }

        return $this->json($response, ['data' => ['inserted' => $inserted, 'updated' => $updated]]);
    }

    public function import(Request $request, Response $response): Response
    {
        $authUser = $this->requireAdmin($request);
        $body = $this->jsonBody($request);

        $ids = $body['ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            throw new ValidationException('ids must be a non-empty list.');
        }

        $onConflict = (string) ($body['on_conflict'] ?? LighthouseImportService::CONFLICT_ASK);
        if (!in_array($onConflict, [LighthouseImportService::CONFLICT_ASK, ...LighthouseImportService::ACTIONS], true)) {
            throw new ValidationException('on_conflict must be ask, add, overwrite or skip.');
        }

        $decisions = [];
        $given = $body['decisions'] ?? [];
        if (!is_array($given)) {
            throw new ValidationException('decisions must be an object of staging id => add|overwrite|skip.');
        }
        foreach ($given as $id => $action) {
            if (!is_string($action) || !in_array($action, LighthouseImportService::ACTIONS, true)) {
                throw new ValidationException('decisions must be an object of staging id => add|overwrite|skip.');
            }
            $decisions[(int) $id] = $action;
        }

        $result = $this->importService->import(
            array_map('intval', $ids),
            (int) $authUser['sub'],
            (bool) ($body['public'] ?? false),
            $onConflict,
            $this->defaultRanges($body['default_ranges'] ?? null),
            $decisions
        );

        return $this->json($response, ['data' => $result]);
    }

    /**
     * The ranges assumed for sectors without one: {"W": 3, "R": 2, "G": 2}
     * (nautical miles); colours left out keep their default.
     *
     * @return array<string,float>
     */
    private function defaultRanges(mixed $given): array
    {
        $ranges = LighthouseImportService::DEFAULT_RANGES;
        if ($given === null) {
            return $ranges;
        }
        if (!is_array($given)) {
            throw new ValidationException('default_ranges must be an object.');
        }
        foreach (array_keys($ranges) as $colour) {
            if (!array_key_exists($colour, $given)) {
                continue;
            }
            $value = $given[$colour];
            if (!is_numeric($value) || (float) $value <= 0 || (float) $value > 50) {
                throw new ValidationException("default_ranges.$colour must be a number between 0 and 50.");
            }
            $ranges[$colour] = (float) $value;
        }

        return $ranges;
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
