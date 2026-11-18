<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Area\AreaRepository;
use Ytan\Domain\MapChangeRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\MapChangesController;

/** GET /map/changes - the live map's change feed. */
final class MapChangesControllerTest extends ControllerTestCase
{
    private MapChangesController $controller;
    private PoiRepository $pois;
    private RouteRepository $routes;
    private AreaRepository $areas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO poitype (id, name) VALUES (2, 'Test Type')");
        $this->pois = new PoiRepository($this->pdo);
        $this->routes = new RouteRepository($this->pdo);
        $this->areas = new AreaRepository($this->pdo);
        $this->controller = new MapChangesController(new MapChangeRepository($this->pdo), $this->pois, $this->routes, $this->areas);
    }

    private function feed(?string $since, ?array $auth = null): array
    {
        $params = $since !== null ? ['since' => $since] : [];
        $response = $this->controller->changes($this->request('GET', '/api/v1/map/changes', $auth, null, $params), $this->response());

        return $this->decode($response)['data'];
    }

    private function poi(int $userId, int $public, string $name = 'Poi'): array
    {
        return $this->pois->create($userId, [
            'poitype_id' => 2, 'name' => $name, 'public' => $public, 'latitude' => 54.3, 'longitude' => 10.1,
        ]);
    }

    private function since(): string
    {
        return $this->feed(null)['cursor'];
    }

    public function testWithoutSinceOnlyAHandsOutACursorBehindTheDatabaseClock(): void
    {
        $data = $this->feed(null);

        $this->assertSame(['cursor'], array_keys($data));
        $now = new \DateTimeImmutable((string) $this->pdo->query('SELECT NOW(3)')->fetchColumn());
        $lag = $now->getTimestamp() - (new \DateTimeImmutable($data['cursor']))->getTimestamp();
        $this->assertEqualsWithDelta(MapChangesController::OVERLAP_SECONDS, $lag, 1);
    }

    public function testPublicChangesReachEveryoneAndPrivateOnesOnlyTheirOwner(): void
    {
        $since = $this->since();
        $owner = $this->createUser();
        $public = $this->poi($owner, 1, 'Public');
        $private = $this->poi($owner, 0, 'Private');

        $anonymous = $this->feed($since);
        $this->assertSame([$public['id']], array_column($anonymous['pois'], 'id'));
        $this->assertContains((int) $private['id'], $anonymous['removed']['pois']);
        $this->assertArrayHasKey('water', $anonymous['pois'][0]); // same shape as GET /pois/{id}
        $this->assertArrayHasKey('changed_at', $anonymous['pois'][0]);

        $mine = $this->feed($since, $this->authPayload($owner));
        $this->assertEqualsCanonicalizing([$public['id'], $private['id']], array_column($mine['pois'], 'id'));
        $this->assertSame([], $mine['removed']['pois']);

        $someoneElse = $this->feed($since, $this->authPayload($this->createUser()));
        $this->assertSame([$public['id']], array_column($someoneElse['pois'], 'id'));
    }

    public function testAnOldRowShowsUpAgainOnceItIsChangedAndMakingItPrivateRemovesIt(): void
    {
        $owner = $this->createUser();
        $poi = $this->poi($owner, 1);
        $this->pdo->exec("UPDATE poi SET changed_at = NOW(3) - INTERVAL 1 HOUR WHERE id = {$poi['id']}");
        $since = $this->since();
        $this->assertSame([], $this->feed($since)['pois']);

        $this->pois->update((int) $poi['id'], ['poitype_id' => 2, 'name' => 'Now private', 'public' => 0, 'latitude' => 54.3, 'longitude' => 10.1]);

        $this->assertSame([], $this->feed($since)['pois']);
        $this->assertSame([(int) $poi['id']], $this->feed($since)['removed']['pois']);
        $this->assertSame('Now private', $this->feed($since, $this->authPayload($owner))['pois'][0]['name']);
    }

    public function testDeletionsLeaveATombstone(): void
    {
        $owner = $this->createUser();
        $routeId = $this->createRoute($owner, ['public' => 1]);
        $area = $this->areas->create($owner, ['name' => 'Area', 'public' => 1, 'points' => '[]']);
        $poi = $this->poi($owner, 1);
        $since = $this->since();

        $this->routes->delete($routeId);
        $this->areas->delete((int) $area['id']);
        $this->pois->delete((int) $poi['id']);

        $data = $this->feed($since);
        $this->assertSame([$routeId], $data['removed']['routes']);
        $this->assertSame([(int) $area['id']], $data['removed']['areas']);
        $this->assertSame([(int) $poi['id']], $data['removed']['pois']);
        $this->assertSame([], $data['reload']);
    }

    public function testRecordingInfoIsRedactedLikeTheRouteEndpoints(): void
    {
        $owner = $this->createUser();
        $since = $this->since();
        $this->routes->create($owner, ['name' => 'Recorded', 'public' => 1, 'points' => '[]', 'recorded_at' => '2026-11-01 10:00:00', 'recording_duration_seconds' => 60]);

        $hidden = $this->feed($since, $this->authPayload($owner))['routes'][0];
        $this->assertArrayNotHasKey('recorded_at', $hidden);

        $shown = $this->feed($since, $this->authPayload($owner, ['route_view_recording' => true]))['routes'][0];
        $this->assertSame('2026-11-01 10:00:00', $shown['recorded_at']);
    }

    public function testTooManyChangesOrAnOldCursorAskForAReload(): void
    {
        $owner = $this->createUser();
        $values = implode(',', array_fill(0, MapChangesController::MAX_ROWS + 1, "(2, $owner, 'Bulk', 1, 54.3, 10.1)"));
        $since = $this->since();
        $this->pdo->exec("INSERT INTO poi (poitype_id, user_id, name, public, latitude, longitude) VALUES $values");
        $this->createRoute($owner, ['public' => 1]);

        $data = $this->feed($since);
        $this->assertSame(['pois'], $data['reload']);
        $this->assertSame([], $data['pois']);
        $this->assertCount(1, $data['routes']);

        $old = (new \DateTimeImmutable($since))->modify('-2 days')->format('Y-m-d H:i:s.v');
        $this->assertSame(['pois', 'routes', 'areas'], $this->feed($old)['reload']);
    }

    public function testAMalformedCursorIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->feed("2026-11-18' OR 1=1");
    }

    public function testOldTombstonesArePrunedWhenANewOneIsWritten(): void
    {
        $changes = new MapChangeRepository($this->pdo);
        $this->pdo->exec("INSERT INTO map_deletion (entity, entity_id, deleted_at) VALUES ('poi', 1, NOW(3) - INTERVAL 2 DAY)");

        $changes->recordDeletion('poi', 2);

        $this->assertSame([2], array_map('intval', $this->pdo->query('SELECT entity_id FROM map_deletion')->fetchAll(\PDO::FETCH_COLUMN)));
    }
}
