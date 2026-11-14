<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\GeometryService;
use Ytan\Service\ImageStorageService;
use Ytan\Service\RouteMerger;
use Ytan\Service\TourNotificationService;

/**
 * POST /routes/{id}/merge - joins another route into route {id} (route.js:
 * route context menu "Mit anderer Route zusammenfügen", then tap the second
 * route, then the dialog).
 *
 * Body: {other_id, name, expected_updated_at?, other_expected_updated_at?}.
 * Route {id} is the one that STAYS (same id, owner, description, public,
 * colour, recording data); it gets the joined line (RouteMerger: only end
 * points are joined, the kept route keeps its direction, the other one is
 * reversed if needed; joined points closer than the admin setting
 * route_merge_midpoint_m become their midpoint), the new length and `name`.
 * The other route is deleted; its photos are copied to the kept route as
 * far as the 5-photo limit allows, and in every tour it was in, the kept
 * route takes its place (or it is just removed when the tour already holds
 * the kept route). One transaction; copied photo files are removed again if
 * it fails, the other route's files only after the commit.
 *
 * Right: the caller must be allowed to edit BOTH routes (owner or admin).
 */
final class RouteMergeController extends BaseController
{
    /** Same limit as RouteController's photo upload. */
    private const MAX_IMAGES_PER_ROUTE = 5;

    /** route.name is VARCHAR(150). */
    private const MAX_NAME_LENGTH = 150;

    public function __construct(
        private readonly RouteRepository $routes,
        private readonly TourRepository $tours,
        private readonly ImageStorageService $images,
        private readonly SettingsRepository $settings,
        private readonly TourNotificationService $notifications,
        private readonly PDO $db,
    ) {
    }

    public function merge(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $keptId = (int) $args['id'];
        $body = $this->jsonBody($request);

        $otherId = $body['other_id'] ?? null;
        if (!is_int($otherId) || $otherId <= 0) {
            throw new ValidationException('other_id must be a route id.');
        }
        if ($otherId === $keptId) {
            throw new ValidationException('A route cannot be merged with itself.');
        }
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ValidationException('name must be 1 to ' . self::MAX_NAME_LENGTH . ' characters long.');
        }

        $kept = $this->routes->findById($keptId);
        $other = $this->routes->findById($otherId);
        $this->assertOwnerOrAdmin($auth, (int) $kept['user_id']);
        $this->assertOwnerOrAdmin($auth, (int) $other['user_id']);

        $merged = RouteMerger::merge(
            $this->points($kept),
            $this->points($other),
            (float) $this->settings->routeMergeMidpointMeters(),
        );

        $affectedTours = [];
        foreach (array_merge($this->tours->findByRoute($keptId), $this->tours->findByRoute($otherId)) as $tour) {
            $affectedTours[(int) $tour['id']] = $tour;
        }

        $copiedFiles = [];
        $imagesSkipped = 0;
        $otherImages = $this->routes->getImages($otherId);
        $ownTransaction = !$this->db->inTransaction(); // tests already run inside one
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $updated = $this->routes->update($keptId, [
                'name' => $name,
                'description' => $kept['description'],
                'public' => $kept['public'],
                'length' => GeometryService::polylineLengthMeters($merged['points']),
                'points' => json_encode($merged['points']),
                'color' => $kept['color'],
                'expected_updated_at' => $body['expected_updated_at'] ?? null,
            ]);

            $free = self::MAX_IMAGES_PER_ROUTE - $this->routes->countImages($keptId);
            foreach ($otherImages as $image) {
                if ($free <= 0) {
                    $imagesSkipped++;
                    continue;
                }
                $filename = $this->images->copy($otherId, $image['filename'], $keptId);
                if ($filename !== null) {
                    $copiedFiles[] = $filename;
                    $this->routes->addImage($keptId, $filename, $image['mime_type'], (int) $image['size_bytes']);
                    $free--;
                }
            }

            foreach ($this->tours->findByRoute($otherId) as $tour) {
                $this->tours->replaceRoute((int) $tour['id'], $otherId, $keptId);
            }

            // tour_route/route_image rows of the other route go with it (ON DELETE CASCADE).
            $this->routes->delete($otherId, $body['other_expected_updated_at'] ?? null);

            foreach (array_keys($affectedTours) as $tourId) {
                $this->tours->recalculateTotalLength($tourId);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            foreach ($copiedFiles as $filename) {
                $this->images->delete($keptId, $filename);
            }
            throw $e;
        }

        foreach ($otherImages as $image) {
            $this->images->delete($otherId, $image['filename']);
        }
        if ($affectedTours !== []) {
            $this->notifications->notifyRouteChanged(array_values($affectedTours), $updated);
        }

        return $this->json($response, ['data' => [
            'route' => ['id' => (int) $updated['id'], 'name' => $updated['name'], 'updated_at' => $updated['updated_at']],
            'deleted_id' => $otherId,
            'join' => $merged['join'],
            'gap_m' => (int) round($merged['gap_m']),
            'other_first' => $merged['other_first'],
            'images_skipped' => $imagesSkipped,
        ]]);
    }

    /** @return list<array{lat: float, lng: float}> */
    private function points(array $route): array
    {
        $points = json_decode((string) $route['points'], true);
        $clean = [];
        foreach (is_array($points) ? $points : [] as $point) {
            if (is_array($point) && is_numeric($point['lat'] ?? null) && is_numeric($point['lng'] ?? null)) {
                $clean[] = ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
            }
        }
        if ($clean === []) {
            throw new ValidationException('Route ' . $route['id'] . ' has no waypoints.');
        }

        return $clean;
    }
}
