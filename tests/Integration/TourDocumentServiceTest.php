<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Ytan\Domain\Area\AreaRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Service\ImageStorageService;
use Ytan\Service\StaticMapImageService;
use Ytan\Service\TourDocumentService;
use Ytan\Service\Translator;
use Ytan\Tests\TestCase;

/**
 * Exercises selectPois()/selectAreas() directly against seeded fixtures -
 * the pure-PHP selection logic that decides what goes into a Touren-Dokument
 * PDF - without touching StaticMapImageService (network) or Dompdf
 * (rendering), which generate() alone would pull in.
 */
final class TourDocumentServiceTest extends TestCase
{
    private TourRepository $tours;
    private RouteRepository $routes;
    private PoiRepository $pois;
    private AreaRepository $areas;
    private TourDocumentService $documents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (2, 'Test Type')");

        $this->tours = new TourRepository($this->pdo);
        $this->routes = new RouteRepository($this->pdo);
        $this->pois = new PoiRepository($this->pdo);
        $this->areas = new AreaRepository($this->pdo);

        $imageDir = sys_get_temp_dir() . '/ytan-tourdoc-test-' . uniqid();
        $this->documents = new TourDocumentService(
            $this->tours,
            $this->routes,
            $this->pois,
            $this->areas,
            new ImageStorageService($imageDir . '/poi'),
            new ImageStorageService($imageDir . '/area'),
            new StaticMapImageService($imageDir . '/maps', ''),
            new GithubFlavoredMarkdownConverter(),
            dirname(__DIR__, 2) . '/public/markers',
            new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'de'),
        );
    }

    private function createTour(int $userId, array $overrides = []): array
    {
        return $this->tours->create($userId, array_merge([
            'name' => 'Tour ' . bin2hex(random_bytes(3)),
        ], $overrides));
    }

    /**
     * Defaults to a non-blank description so selectPois()'s "has content"
     * filter doesn't need to be considered by every test that isn't
     * specifically about that filter - tests exercising it override this.
     */
    private function createPoi(int $userId, array $overrides = []): array
    {
        return $this->pois->create($userId, array_merge([
            'poitype_id' => 2,
            'name' => 'Poi ' . bin2hex(random_bytes(3)),
            'description' => 'A test POI.',
            'latitude' => 54.0,
            'longitude' => 10.0,
            'public' => 0,
        ], $overrides));
    }

    /**
     * Defaults to a non-blank description - see createPoi().
     */
    private function createArea(int $userId, array $overrides = []): array
    {
        return $this->areas->create($userId, array_merge([
            'name' => 'Area ' . bin2hex(random_bytes(3)),
            'description' => 'A test area.',
            'public' => 0,
        ], $overrides));
    }

    /**
     * A straight west-east route along lat 54.0, from lng 10.0 to 10.01.
     */
    private function straightRoutePolyline(): array
    {
        return [
            ['lat' => 54.0, 'lng' => 10.0],
            ['lat' => 54.0, 'lng' => 10.01],
        ];
    }

    public function testPoiWithin200MetersOfARouteIsSelected(): void
    {
        $userId = $this->createUser();
        $tour = $this->createTour($userId);
        // ~111m north of the route line, well within 200m.
        $poi = $this->createPoi($userId, ['latitude' => 54.001, 'longitude' => 10.005]);

        $selected = $this->documents->selectPois($tour, [$this->straightRoutePolyline()]);

        $this->assertCount(1, $selected);
        $this->assertSame((int) $poi['id'], (int) $selected[0]['id']);
    }

    public function testPoiBeyond200MetersOfEveryRouteIsNotSelected(): void
    {
        $userId = $this->createUser();
        $tour = $this->createTour($userId);
        // ~1.1km north of the route line - well outside the threshold.
        $this->createPoi($userId, ['latitude' => 54.01, 'longitude' => 10.005]);

        $selected = $this->documents->selectPois($tour, [$this->straightRoutePolyline()]);

        $this->assertCount(0, $selected);
    }

    public function testAreaCrossedByARouteSegmentIsSelected(): void
    {
        $userId = $this->createUser();
        $tour = $this->createTour($userId);
        // A small square straddling the route's midpoint, so the route
        // crosses in one side and out the other.
        $area = $this->createArea($userId, ['points' => json_encode([
            ['lat' => 53.999, 'lng' => 10.004],
            ['lat' => 53.999, 'lng' => 10.006],
            ['lat' => 54.001, 'lng' => 10.006],
            ['lat' => 54.001, 'lng' => 10.004],
        ])]);

        $selected = $this->documents->selectAreas($tour, [$this->straightRoutePolyline()]);

        $this->assertCount(1, $selected);
        $this->assertSame((int) $area['id'], (int) $selected[0]['id']);
    }

    public function testAreaNeverCrossedByAnyRouteIsNotSelected(): void
    {
        $userId = $this->createUser();
        $tour = $this->createTour($userId);
        $this->createArea($userId, ['points' => json_encode([
            ['lat' => 60.0, 'lng' => 20.0],
            ['lat' => 60.0, 'lng' => 20.01],
            ['lat' => 60.01, 'lng' => 20.01],
            ['lat' => 60.01, 'lng' => 20.0],
        ])]);

        $selected = $this->documents->selectAreas($tour, [$this->straightRoutePolyline()]);

        $this->assertCount(0, $selected);
    }

    public function testAreaThatFullyContainsTheRouteIsSelectedWithoutABoundaryCrossing(): void
    {
        $userId = $this->createUser();
        $tour = $this->createTour($userId);
        // A large square fully containing the whole route.
        $area = $this->createArea($userId, ['points' => json_encode([
            ['lat' => 53.9, 'lng' => 9.9],
            ['lat' => 53.9, 'lng' => 10.1],
            ['lat' => 54.1, 'lng' => 10.1],
            ['lat' => 54.1, 'lng' => 9.9],
        ])]);

        $selected = $this->documents->selectAreas($tour, [$this->straightRoutePolyline()]);

        $this->assertCount(1, $selected);
        $this->assertSame((int) $area['id'], (int) $selected[0]['id']);
    }

    public function testPoiNearARouteWithNeitherDescriptionNorImageIsNotSelected(): void
    {
        $userId = $this->createUser();
        $tour = $this->createTour($userId);
        $this->createPoi($userId, [
            'latitude' => 54.001,
            'longitude' => 10.005,
            'description' => '',
        ]);

        $selected = $this->documents->selectPois($tour, [$this->straightRoutePolyline()]);

        $this->assertCount(0, $selected);
    }

    public function testAreaCrossedByARouteWithNeitherDescriptionNorImageIsNotSelected(): void
    {
        $userId = $this->createUser();
        $tour = $this->createTour($userId);
        $this->createArea($userId, [
            'description' => '',
            'points' => json_encode([
                ['lat' => 53.999, 'lng' => 10.004],
                ['lat' => 53.999, 'lng' => 10.006],
                ['lat' => 54.001, 'lng' => 10.006],
                ['lat' => 54.001, 'lng' => 10.004],
            ]),
        ]);

        $selected = $this->documents->selectAreas($tour, [$this->straightRoutePolyline()]);

        $this->assertCount(0, $selected);
    }

    public function testPoiOutsideACustomRadiusIsNotSelected(): void
    {
        $userId = $this->createUser();
        $tour = $this->createTour($userId);
        // ~55m north of the route line - within the default 200m, but
        // outside a custom, narrower radius.
        $this->createPoi($userId, ['latitude' => 54.0005, 'longitude' => 10.005]);

        $selected = $this->documents->selectPois($tour, [$this->straightRoutePolyline()], 20.0);

        $this->assertCount(0, $selected);
    }

    public function testNoPoisOrAreasAreSelectedWhenTheTourHasNoRoutes(): void
    {
        $userId = $this->createUser();
        $tour = $this->createTour($userId);
        $this->createPoi($userId, ['latitude' => 54.0, 'longitude' => 10.0]);
        $this->createArea($userId, ['points' => json_encode([
            ['lat' => 53.9, 'lng' => 9.9],
            ['lat' => 53.9, 'lng' => 10.1],
            ['lat' => 54.1, 'lng' => 10.1],
            ['lat' => 54.1, 'lng' => 9.9],
        ])]);

        $this->assertSame([], $this->documents->selectPois($tour, []));
        $this->assertSame([], $this->documents->selectAreas($tour, []));
    }
}
