<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\GeometryService;
use Ytan\Service\ImageStorageService;

/**
 * POST /routes/{id}/split - splits a route at one of its inner waypoints
 * into two routes sharing that point (route.js: right-click / long-press on
 * a waypoint while editing an existing route).
 *
 * Body: {points: [{lat, lng}, ...], index, name_suffixes: [s1, s2],
 *        expected_updated_at?}. `points` is the route's line as currently
 * edited (the split also saves pending geometry edits), `index` the split
 * waypoint (not the first or last).
 *
 * Part 1 is the existing route (same id): points 0..index, name + s1.
 * Part 2 is a new route of the same owner: points index..end, name + s2,
 * with everything else copied - description, public, color, the recording
 * data (recorded_at/recording_duration_seconds; the points carry no times,
 * so the recording can't be divided) and copies of all photos. In every
 * tour containing the route, part 2 is inserted directly behind part 1.
 * Lengths are computed here (haversine) for both parts. One transaction;
 * copied photo files are removed again if it fails.
 *
 * Same right as editing the route: owner or admin.
 */
final class RouteSplitController extends BaseController
{
    private const MAX_SUFFIX_LENGTH = 30;

    public function __construct(
        private readonly RouteRepository $routes,
        private readonly TourRepository $tours,
        private readonly ImageStorageService $images,
        private readonly PDO $db,
    ) {
    }

    public function split(Request $request, Response $response, array $args): Response
    {
        $auth = $this->requireAuthUser($request);
        $routeId = (int) $args['id'];
        $route = $this->routes->findById($routeId);
        $this->assertOwnerOrAdmin($auth, (int) $route['user_id']);

        $body = $this->jsonBody($request);
        $points = $this->points($body['points'] ?? null);
        $index = $body['index'] ?? null;
        if (!is_int($index) || $index < 1 || $index > count($points) - 2) {
            throw new ValidationException('index must be an inner waypoint (not the first or last).');
        }
        [$suffix1, $suffix2] = $this->suffixes($body['name_suffixes'] ?? null);

        $part1 = array_slice($points, 0, $index + 1);
        $part2 = array_slice($points, $index);

        $copiedFiles = [];
        $ownTransaction = !$this->db->inTransaction(); // tests already run inside one
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $updated = $this->routes->update($routeId, [
                'name' => $this->name($route['name'], $suffix1),
                'description' => $route['description'],
                'public' => $route['public'],
                'length' => $this->length($part1),
                'points' => json_encode($part1),
                'color' => $route['color'],
                'expected_updated_at' => $body['expected_updated_at'] ?? null,
            ]);

            $created = $this->routes->create((int) $route['user_id'], [
                'name' => $this->name($route['name'], $suffix2),
                'description' => $route['description'],
                'public' => $route['public'],
                'length' => $this->length($part2),
                'points' => json_encode($part2),
                'color' => $route['color'],
                'recorded_at' => $route['recorded_at'],
                'recording_duration_seconds' => $route['recording_duration_seconds'],
            ]);
            $newId = (int) $created['id'];

            foreach ($this->routes->getImages($routeId) as $image) {
                $filename = $this->images->copy($routeId, $image['filename'], $newId);
                if ($filename !== null) {
                    $copiedFiles[] = $filename;
                    $this->routes->addImage($newId, $filename, $image['mime_type'], (int) $image['size_bytes']);
                }
            }

            // Both parts changed length - every tour of the route gets part
            // 2 right behind part 1, which also recalculates its total.
            foreach ($this->tours->findByRoute($routeId) as $tour) {
                $this->tours->insertRouteAfter((int) $tour['id'], $routeId, $newId);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            foreach ($copiedFiles as $filename) {
                $this->images->delete($newId, $filename);
            }
            throw $e;
        }

        return $this->json($response, ['data' => [
            'routes' => [$this->summary($updated), $this->summary($this->routes->findById($newId))],
        ]], 201);
    }

    /** @return list<array{lat: float, lng: float}> */
    private function points(mixed $points): array
    {
        if (!is_array($points) || count($points) < 3) {
            throw new ValidationException('points must hold at least 3 waypoints.');
        }

        $clean = [];
        foreach ($points as $point) {
            if (!is_array($point) || !is_numeric($point['lat'] ?? null) || !is_numeric($point['lng'] ?? null)
                || abs((float) $point['lat']) > 90 || abs((float) $point['lng']) > 180) {
                throw new ValidationException('Invalid waypoint.');
            }
            $clean[] = ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
        }

        return $clean;
    }

    /** @return array{0: string, 1: string} */
    private function suffixes(mixed $suffixes): array
    {
        if (!is_array($suffixes) || count($suffixes) !== 2) {
            throw new ValidationException('name_suffixes must hold two strings.');
        }
        $suffixes = array_values($suffixes);
        foreach ($suffixes as $suffix) {
            if (!is_string($suffix) || mb_strlen($suffix) > self::MAX_SUFFIX_LENGTH) {
                throw new ValidationException('Invalid name suffix.');
            }
        }

        return [$suffixes[0], $suffixes[1]];
    }

    /** route.name is VARCHAR(150) - shorten the name, not the suffix. */
    private function name(string $name, string $suffix): string
    {
        return mb_substr($name, 0, 150 - mb_strlen($suffix)) . $suffix;
    }

    private function length(array $points): int
    {
        $length = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $length += GeometryService::haversineDistanceMeters($points[$i - 1], $points[$i]);
        }

        return (int) round($length);
    }

    private function summary(array $route): array
    {
        return ['id' => (int) $route['id'], 'name' => $route['name'], 'updated_at' => $route['updated_at']];
    }
}
