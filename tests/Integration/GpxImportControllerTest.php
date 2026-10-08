<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\GpxImportController;
use Ytan\Service\GpxImportService;

final class GpxImportControllerTest extends ControllerTestCase
{
    private GpxImportController $controller;
    private TourRepository $tours;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tours = new TourRepository($this->pdo);
        $this->controller = new GpxImportController(new RouteRepository($this->pdo), $this->tours, new PoiRepository($this->pdo), new GpxImportService(), $this->pdo);

        // POI type 0 for imported waypoints - the structure-only test DB has
        // no poitype rows, and poi.poitype_id has a foreign key. A plain
        // INSERT of id 0 into an AUTO_INCREMENT column would get the next id.
        $this->pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");
        $this->pdo->exec("INSERT IGNORE INTO poitype (id, name) VALUES (0, 'undefined')");
    }

    private function gpx(bool $withTracks = true): string
    {
        $track = fn (string $name, float $lat) => '<trk><name>' . $name . '</name><trkseg>'
            . '<trkpt lat="' . $lat . '" lon="10.0"/><trkpt lat="' . ($lat + 0.01) . '" lon="10.0"/></trkseg></trk>';

        return '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
            . '<metadata><name>Schlei-Woche</name></metadata>'
            . '<wpt lat="54.05" lon="10.01"><name>Zeltplatz</name></wpt>'
            . '<wpt lat="54.15" lon="10.01"><desc>Anleger mit Kiosk und Toiletten am Westufer</desc></wpt>'
            . '<wpt lat="54.25" lon="10.01"><ele>3</ele></wpt>'
            . ($withTracks ? $track('Etappe 1', 54.0) . $track('Etappe 2', 54.1) . $track('Etappe 3', 54.2) : '')
            . '</gpx>';
    }

    private function import(?array $auth, array $body, bool $withTracks = true): array
    {
        return $this->decode($this->controller->import(
            $this->request('POST', '/api/v1/gpx/import', $auth, $body + ['gpx' => $this->gpx($withTracks)]),
            $this->response()
        ));
    }

    private function poisOf(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM poi WHERE user_id = ? ORDER BY id');
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    private function routeRow(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM route WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }

    public function testPreviewListsTracksWithoutPoints(): void
    {
        $userId = $this->createUser();

        $result = $this->decode($this->controller->preview(
            $this->request('POST', '/api/v1/gpx/preview', $this->authPayload($userId), ['gpx' => $this->gpx()]),
            $this->response()
        ));

        $this->assertSame(200, $result['status']);
        $this->assertSame('Schlei-Woche', $result['data']['name']);
        $this->assertSame(['Etappe 1', 'Etappe 2', 'Etappe 3'], array_column($result['data']['tracks'], 'name'));
        $this->assertArrayNotHasKey('points', $result['data']['tracks'][0]);
    }

    public function testPreviewNeedsLogin(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->controller->preview($this->request('POST', '/api/v1/gpx/preview', null, ['gpx' => $this->gpx()]), $this->response());
    }

    public function testMergeCreatesOnePrivateRouteNamedAfterTheFile(): void
    {
        $userId = $this->createUser();

        $result = $this->import($this->authPayload($userId), ['tracks' => [0, 2], 'mode' => 'merge']);

        $this->assertSame(201, $result['status']);
        $this->assertCount(1, $result['data']['routes']);
        $this->assertNull($result['data']['tour']);
        $row = $this->routeRow($result['data']['routes'][0]['id']);
        $this->assertSame('Schlei-Woche', $row['name']);
        $this->assertSame(0, (int) $row['public']);
        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertCount(4, json_decode($row['points'], true));
    }

    public function testSeparateWithTourCreatesRoutesAndTourInOrder(): void
    {
        $userId = $this->createUser(['tour_create' => 1]);

        $result = $this->import($this->authPayload($userId, ['tour_create' => true]), ['tracks' => [0, 1, 2], 'mode' => 'separate', 'create_tour' => true]);

        $this->assertSame(['Etappe 1', 'Etappe 2', 'Etappe 3'], array_column($result['data']['routes'], 'name'));
        $tour = $this->tours->findById($result['data']['tour']['id']);
        $this->assertSame('Schlei-Woche', $tour['name']);
        $this->assertSame(0, (int) $tour['public']);
        $inTour = (new RouteRepository($this->pdo))->findByTour((int) $tour['id']);
        $this->assertSame(['Etappe 1', 'Etappe 2', 'Etappe 3'], array_column($inTour, 'name'));
        $this->assertGreaterThan(3000, (int) $tour['total_length']);
    }

    public function testSeparateRoutesGetDifferentColorsMergedKeepsDefault(): void
    {
        $auth = $this->authPayload($this->createUser());

        $separate = $this->import($auth, ['tracks' => [0, 1, 2], 'mode' => 'separate']);
        $colors = array_map(fn (array $route) => $this->routeRow($route['id'])['color'], $separate['data']['routes']);
        $this->assertSame(array_slice(GpxImportService::ROUTE_COLORS, 0, 3), $colors);
        $this->assertCount(3, array_unique($colors));

        $merged = $this->import($auth, ['tracks' => [0, 1], 'mode' => 'merge']);
        $this->assertSame('#BF409F', $this->routeRow($merged['data']['routes'][0]['id'])['color']);
    }

    public function testWaypointsBecomePrivateType0PoisOnlyOnRequest(): void
    {
        $userId = $this->createUser();

        $without = $this->import($this->authPayload($userId), ['tracks' => [0], 'mode' => 'separate']);
        $this->assertSame(0, $without['data']['pois']);
        $this->assertSame([], $this->poisOf($userId));

        $with = $this->import($this->authPayload($userId), ['tracks' => [0], 'mode' => 'separate', 'import_waypoints' => true]);
        $this->assertSame(2, $with['data']['pois'], 'the <wpt> without name and desc is skipped');
        $pois = $this->poisOf($userId);
        $this->assertSame(['Zeltplatz', 'IMPORT: Anleger mit Kiosk un'], array_column($pois, 'name'));
        $this->assertSame('Anleger mit Kiosk und Toiletten am Westufer', $pois[1]['description']);
        foreach ($pois as $poi) {
            $this->assertSame(0, (int) $poi['poitype_id']);
            $this->assertSame(0, (int) $poi['public']);
        }
    }

    public function testFileWithWaypointsOnlyImportsJustThePois(): void
    {
        $userId = $this->createUser();

        $result = $this->import($this->authPayload($userId), ['tracks' => [], 'import_waypoints' => true], false);

        $this->assertSame(201, $result['status']);
        $this->assertSame([], $result['data']['routes']);
        $this->assertSame(2, $result['data']['pois']);
    }

    public function testTourNeedsTourCreateRight(): void
    {
        $userId = $this->createUser();

        $this->expectException(ForbiddenException::class);
        $this->import($this->authPayload($userId), ['tracks' => [0], 'mode' => 'separate', 'create_tour' => true]);
    }

    public function testTourOnlyFromSeparateRoutes(): void
    {
        $userId = $this->createUser(['tour_create' => 1]);

        $this->expectException(ValidationException::class);
        $this->import($this->authPayload($userId, ['tour_create' => true]), ['tracks' => [0, 1], 'mode' => 'merge', 'create_tour' => true]);
    }

    public function testRejectsEmptyOrUnknownSelection(): void
    {
        $auth = $this->authPayload($this->createUser());
        foreach ([[], [7]] as $tracks) {
            try {
                $this->import($auth, ['tracks' => $tracks, 'mode' => 'separate']);
                $this->fail('Accepted tracks ' . json_encode($tracks));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
