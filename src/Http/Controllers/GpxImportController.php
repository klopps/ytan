<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\GpxImportService;

/**
 * GPX import (gpx-import.js): the client sends the file's text as
 * {"gpx": "..."} to both steps, so nothing is stored between them.
 *
 * POST /gpx/preview - what the dialog lists: document name/desc/time and
 *   each <trk>/<rte> with name/desc/time, point count and length.
 * POST /gpx/import  - {gpx, tracks: [index, ...], mode: "merge"|"separate",
 *   create_tour: bool, import_waypoints: bool}: "merge" joins the chosen
 *   tracks (in file order) into one route, "separate" makes one route per
 *   track and, with create_tour, puts them into a new tour in that order.
 *   import_waypoints adds every named/described <wpt> as a POI (type 0);
 *   with it, "tracks" may be empty (a file of waypoints only).
 *
 * Same rights as drawing: any signed-in user may import routes and POIs;
 * the tour needs tour_create (or admin), like "New tour". Everything is
 * created private.
 */
final class GpxImportController extends BaseController
{
    public function __construct(
        private readonly RouteRepository $routes,
        private readonly TourRepository $tours,
        private readonly PoiRepository $pois,
        private readonly GpxImportService $gpx,
        private readonly PDO $db,
    ) {
    }

    public function preview(Request $request, Response $response): Response
    {
        $this->requireAuthUser($request);
        $parsed = $this->gpx->parse((string) ($this->jsonBody($request)['gpx'] ?? ''));

        return $this->json($response, ['data' => $this->gpx->summary($parsed)]);
    }

    public function import(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $body = $this->jsonBody($request);
        $parsed = $this->gpx->parse((string) ($body['gpx'] ?? ''));

        $mode = $body['mode'] ?? 'separate';
        if (!in_array($mode, ['merge', 'separate'], true)) {
            throw new ValidationException('mode must be "merge" or "separate".');
        }
        $createTour = (bool) ($body['create_tour'] ?? false);
        if ($createTour && $mode !== 'separate') {
            throw new ValidationException('A tour can only be created from separate routes.');
        }
        if ($createTour) {
            $this->assertTourRight($auth, 'tour_create');
        }

        $importWaypoints = (bool) ($body['import_waypoints'] ?? false) && $parsed['waypoints'] !== [];
        $tracks = $this->selectedTracks($parsed, $body['tracks'] ?? null, $importWaypoints);
        if ($createTour && $tracks === []) {
            throw new ValidationException('A tour needs at least one track.');
        }
        $userId = (int) $auth['sub'];

        // One fallback name for the whole import: the file's own, else the first chosen track's.
        $documentName = $parsed['name'] ?? $tracks[0]['name'] ?? 'GPX';

        $routeData = [];
        if ($tracks === []) {
            // Waypoints only - no route.
        } elseif ($mode === 'merge') {
            $routeData[] = $this->gpx->toRoute($tracks, $documentName, $parsed['desc'] ?? $tracks[0]['desc'] ?? '');
        } else {
            foreach ($tracks as $n => $track) {
                $routeData[] = $this->gpx->toRoute(
                    [$track],
                    $track['name'] ?? ($documentName . ' ' . ($n + 1)),
                    $track['desc'] ?? ''
                ) + ['color' => GpxImportService::routeColor($n)];
            }
        }

        // Tests already run inside a transaction (TestCase) - only open our own if none is active.
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $created = [];
            foreach ($routeData as $data) {
                $route = $this->routes->create($userId, $data + ['public' => 0]);
                $created[] = ['id' => (int) $route['id'], 'name' => $route['name']];
            }

            $poiCount = 0;
            if ($importWaypoints) {
                foreach ($parsed['waypoints'] as $waypoint) {
                    $this->pois->create($userId, $this->gpx->toPoi($waypoint) + ['public' => 0]);
                    $poiCount++;
                }
            }

            $tour = null;
            if ($createTour) {
                $row = $this->tours->create($userId, ['name' => mb_substr($documentName, 0, 150), 'description' => $parsed['desc'] ?? null]);
                foreach ($created as $route) {
                    $this->tours->addRoute((int) $row['id'], $route['id']);
                }
                $tour = ['id' => (int) $row['id'], 'name' => $row['name']];
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->json($response, ['data' => ['routes' => $created, 'tour' => $tour, 'pois' => $poiCount]], 201);
    }

    /**
     * The chosen tracks in file order; each must exist and have at least
     * two points (a route needs a line). None chosen is only allowed when
     * waypoints are imported instead.
     *
     * @return list<array>
     */
    private function selectedTracks(array $parsed, mixed $indices, bool $importWaypoints): array
    {
        if ($importWaypoints && ($indices === null || $indices === [])) {
            return [];
        }
        if (!is_array($indices) || $indices === []) {
            throw new ValidationException('Choose at least one track.');
        }

        $wanted = array_unique(array_map('intval', $indices));
        $tracks = [];
        foreach ($parsed['tracks'] as $track) {
            if (in_array($track['index'], $wanted, true)) {
                if (count($track['points']) < 2) {
                    throw new ValidationException('Track "' . ($track['name'] ?? ($track['index'] + 1)) . '" has fewer than two points.');
                }
                $tracks[] = $track;
            }
        }
        if (count($tracks) !== count($wanted)) {
            throw new ValidationException('Unknown track index.');
        }

        return $tracks;
    }
}
