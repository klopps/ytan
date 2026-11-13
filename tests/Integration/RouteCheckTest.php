<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Ai\AiUsageRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\AiAdminController;
use Ytan\Http\Controllers\AiBriefingController;
use Ytan\Service\Ai\AiTextCache;
use Ytan\Service\Ai\RouteBriefingBuilder;
use Ytan\Service\Ai\RouteBriefingService;
use Ytan\Tests\Fixtures\FakeAiClient;

/**
 * Routencheck end to end below HTTP: AiBriefingController + RouteBriefingService
 * with a fake weather source and FakeAiClient - the gates (switch, right,
 * visibility, options), the daily limit, the text cache, failures, the usage
 * rows - and AiAdminController (settings, rules, usage per month and user).
 */
final class RouteCheckTest extends ControllerTestCase
{
    private FakeAiClient $ai;
    private string $cacheDir;
    private SettingsRepository $settings;
    private AiUsageRepository $usage;
    private int $weatherCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('DELETE FROM app_settings');
        $this->pdo->exec('INSERT INTO app_settings (id, google_search_requires_login, track_distance_filter_precise_m, track_distance_filter_balanced_m, track_distance_filter_battery_m, route_label_font_size_min, route_label_font_size_max) VALUES (1, 0, 20, 50, 100, 8, 20)');
        $this->pdo->exec("INSERT IGNORE INTO poitype (id, name) VALUES (2, 'Anlandestelle'), (3, 'Wasser'), (10, 'Shelter')");
        $this->settings = new SettingsRepository($this->pdo);
        $this->settings->setAiSettings(true, 2, 3);
        $this->usage = new AiUsageRepository($this->pdo);
        $this->ai = new FakeAiClient('**Gut fahrbar** bis 14 Uhr.');
        $this->cacheDir = sys_get_temp_dir() . '/ytan-ai-cache-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->cacheDir);
        parent::tearDown();
    }

    private function controller(): AiBriefingController
    {
        $builder = new RouteBriefingBuilder(
            function (float $lat, float $lng): array {
                $this->weatherCalls++;

                return $this->forecast();
            },
            fn () => null,
            fn (float $south, float $north, float $west, float $east, array $types, ?int $userId) => array_map(
                fn (array $poi) => $poi + ['type_label' => 'Typ ' . $poi['poitype_id']],
                (new PoiRepository($this->pdo))->findInBox($south, $north, $west, $east, $types, $userId)
            ),
        );
        $service = new RouteBriefingService($builder, $this->ai, new AiTextCache($this->cacheDir), $this->usage, $this->settings);

        return new AiBriefingController(new RouteRepository($this->pdo), new UserRepository($this->pdo), $this->settings, $service, 'de');
    }

    private function admin(): AiAdminController
    {
        return new AiAdminController($this->settings, $this->usage, $this->ai);
    }

    /** A week of calm hourly weather around tomorrow, local time = UTC. */
    private function forecast(): array
    {
        $hourly = [];
        $daily = [];
        for ($d = -1; $d <= 7; $d++) {
            $date = gmdate('Y-m-d', strtotime("$d day"));
            $daily[] = ['date' => $date, 'sunrise' => $date . 'T07:00', 'sunset' => $date . 'T18:00'];
            for ($h = 0; $h < 24; $h++) {
                $hourly[] = ['time' => sprintf('%sT%02d:00', $date, $h), 'wind_speed' => 10.0, 'wind_gusts' => 18.0, 'wind_direction' => 270.0, 'weather_code' => 1];
            }
        }

        return ['source' => 'Test', 'model' => null, 'generated_at' => null, 'utc_offset_seconds' => 0, 'hourly' => $hourly, 'daily' => $daily];
    }

    private function route(int $owner, int $public = 1): int
    {
        return $this->createRoute($owner, ['public' => $public, 'points' => json_encode([['lat' => 56.0, 'lng' => 10.0], ['lat' => 56.05, 'lng' => 10.0]])]);
    }

    private function body(array $extra = []): array
    {
        return $extra + ['date' => gmdate('Y-m-d', strtotime('+1 day')), 'start_hour' => 10];
    }

    private function check(int $userId, int $routeId, array $authOverrides = [], array $body = []): array
    {
        return $this->decode($this->controller()->briefing(
            $this->request('POST', "/api/v1/routes/$routeId/briefing", $this->authPayload($userId, $authOverrides), $this->body($body)),
            $this->response(),
            ['id' => (string) $routeId]
        ));
    }

    public function testATrafficLightAndTheAiText(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->pdo->exec("INSERT INTO poi (poitype_id, user_id, name, description, public, latitude, longitude) VALUES (10, $userId, 'Shelter Bucht', '', 1, 56.02, 10.005)");
        $poiId = (int) $this->pdo->lastInsertId();

        $result = $this->check($userId, $routeId, ['ai_use' => true]);

        $this->assertSame(200, $result['status']);
        $data = $result['data'];
        $this->assertSame(0, $data['rating']);
        $this->assertSame('ok', $data['text_status']);
        $this->assertSame('**Gut fahrbar** bis 14 Uhr.', $data['text']);
        $this->assertFalse($data['text_cached']);
        $this->assertSame('10:00', $data['start']);
        $this->assertSame('experienced', $data['level']);
        $this->assertSame(6.0, (float) $data['speed_kmh']);
        $this->assertCount(2, $data['sections']);
        $this->assertSame([$poiId], array_column($data['pois'], 'id'));
        $this->assertSame('landing', $data['pois'][0]['role']);
        $this->assertSame(['per_day' => 2, 'used_today' => 1], $data['limit']);

        // the prompt carries the assessment, not the route's name or owner
        $prompt = $this->ai->calls[0]['user'];
        $this->assertStringContainsString('German', $prompt);
        $this->assertStringContainsString('Shelter Bucht', $prompt);
        $this->assertStringContainsString('"overall_rating":"green"', $prompt);

        $row = $this->pdo->query('SELECT * FROM ai_usage')->fetch();
        $this->assertSame((string) $userId, (string) $row['user_id']);
        $this->assertSame('1200', (string) $row['input_tokens']);
        $this->assertSame('ok', $row['status']);
    }

    public function testTheSameRequestIsAnsweredFromTheCache(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $other = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);

        $this->check($userId, $routeId, ['ai_use' => true]);
        $second = $this->check($other, $routeId, ['ai_use' => true]);

        $this->assertCount(1, $this->ai->calls);
        $this->assertTrue($second['data']['text_cached']);
        $this->assertSame('**Gut fahrbar** bis 14 Uhr.', $second['data']['text']);
        $this->assertSame(0, $this->usage->countToday($other), 'a cache hit does not count');

        // another start hour is another context = a new text
        $this->check($userId, $routeId, ['ai_use' => true], ['start_hour' => 12]);
        $this->assertCount(2, $this->ai->calls);
    }

    public function testTheDailyLimitStopsOnlyTheText(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->check($userId, $routeId, ['ai_use' => true], ['start_hour' => 8]);
        $this->check($userId, $routeId, ['ai_use' => true], ['start_hour' => 9]);

        $third = $this->check($userId, $routeId, ['ai_use' => true], ['start_hour' => 11]);

        $this->assertCount(2, $this->ai->calls);
        $this->assertSame('limit', $third['data']['text_status']);
        $this->assertNull($third['data']['text']);
        $this->assertSame(0, $third['data']['rating'], 'the traffic light still comes');
    }

    public function testAFailingModelStillAnswersWithTheTrafficLight(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->ai->willFail('credit');

        $result = $this->check($userId, $routeId, ['ai_use' => true]);

        $this->assertSame('error', $result['data']['text_status']);
        $this->assertSame('credit', $result['data']['text_error']);
        $this->assertSame(0, $result['data']['rating']);
        $this->assertSame('error', $this->pdo->query('SELECT status FROM ai_usage')->fetchColumn());
        $this->assertSame(0, $this->usage->countToday($userId), 'a failed request does not count');
    }

    public function testWithoutAKeyThereIsNoText(): void
    {
        $this->ai = new FakeAiClient('x', false);
        $userId = $this->createUser(['ai_use' => 1]);

        $result = $this->check($userId, $this->route($userId), ['ai_use' => true]);

        $this->assertSame('not_configured', $result['data']['text_status']);
        $this->assertSame([], $this->ai->calls);
    }

    public function testTrafficLightOnlyAsksNoModel(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);

        $result = $this->check($userId, $this->route($userId), ['ai_use' => true], ['text' => false]);

        $this->assertSame('skipped', $result['data']['text_status']);
        $this->assertSame(0, $result['data']['rating']);
        $this->assertSame([], $this->ai->calls);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ai_usage')->fetchColumn());
    }

    public function testTheFeatureSwitchedOffIsForbidden(): void
    {
        $this->settings->setAiSettings(false, 2, 3);
        $userId = $this->createUser(['ai_use' => 1]);
        $this->expectException(ForbiddenException::class);
        $this->check($userId, $this->route($userId), ['ai_use' => true]);
    }

    public function testWithoutTheRightItIsForbiddenButAdminsMay(): void
    {
        $userId = $this->createUser();
        $routeId = $this->route($userId);
        $admin = $this->createUser(['is_admin' => 1]);
        $this->assertSame(200, $this->check($admin, $routeId, ['is_admin' => true])['status']);

        $this->expectException(ForbiddenException::class);
        $this->check($userId, $routeId);
    }

    public function testSomeoneElsesPrivateRouteIsNotFound(): void
    {
        $owner = $this->createUser();
        $userId = $this->createUser(['ai_use' => 1]);
        $this->expectException(NotFoundException::class);
        $this->check($userId, $this->route($owner, 0), ['ai_use' => true]);
    }

    public function testInvalidOptions(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        foreach ([['date' => '2020-01-01'], ['date' => 'morgen'], ['start_hour' => 24], ['speed_kmh' => 50], ['level' => 'pro']] as $body) {
            try {
                $this->check($userId, $routeId, ['ai_use' => true], $body);
                $this->fail('expected a validation error for ' . json_encode($body));
            } catch (ValidationException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $short = $this->createRoute($userId, ['public' => 1, 'points' => '[{"lat":56,"lng":10}]']);
        $this->expectException(ValidationException::class);
        $this->check($userId, $short, ['ai_use' => true]);
    }

    public function testStoredLevelAndSpeedAreTheDefaults(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $users = new UserRepository($this->pdo);
        $users->setDefaultSpeed($userId, 4.5);
        $this->decode($this->controller()->setPaddleLevel(
            $this->request('PUT', '/api/v1/auth/paddle-level', $this->authPayload($userId), ['paddle_level' => 'beginner']),
            $this->response()
        ));

        $data = $this->check($userId, $this->route($userId), ['ai_use' => true])['data'];

        $this->assertSame('beginner', $data['level']);
        $this->assertSame(4.5, (float) $data['speed_kmh']);
        $this->assertEquals(3.8, $data['thresholds']['wind']['yellow']);
    }

    public function testReversedRouteStartsAtTheOtherEnd(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $data = $this->check($userId, $this->route($userId), ['ai_use' => true], ['reverse' => true])['data'];

        $this->assertTrue($data['reverse']);
        $this->assertEquals([56.05, 10.0], $data['sections'][0]['points'][0]);
    }

    public function testAdminSettingsRulesAndUsage(): void
    {
        $admin = $this->createUser(['is_admin' => 1, 'username' => 'chef']);
        $auth = $this->authPayload($admin, ['is_admin' => true, 'username' => 'chef']);
        $paddler = $this->createUser(['ai_use' => 1, 'username' => 'paddler']);
        $idle = $this->createUser(['ai_use' => 1, 'username' => 'idle']);
        $this->createUser(['username' => 'nobody']);
        $routeId = $this->route($paddler);
        $this->check($paddler, $routeId, ['ai_use' => true, 'username' => 'paddler']);
        $this->check($paddler, $routeId, ['ai_use' => true, 'username' => 'paddler']); // cache hit

        $saved = $this->decode($this->admin()->updateSettings($this->request('PUT', '/api/v1/settings/ai', $auth, ['enabled' => true, 'daily_limit' => 50, 'cache_hours' => 6]), $this->response()));
        $this->assertSame(['enabled' => true, 'daily_limit' => 50, 'cache_hours' => 6], $saved['data']);

        $rules = $this->decode($this->admin()->updateRules($this->request('PUT', '/api/v1/settings/briefing-rules', $auth, ['rules' => ['wind' => ['yellow' => 4, 'red' => 7]]]), $this->response()));
        $this->assertEquals(['yellow' => 4.0, 'red' => 7.0], $rules['data']['rules']['wind']);
        $this->assertEquals(['yellow' => 4.0, 'red' => 7.0], $this->settings->briefingRules()['wind']);
        $this->admin()->updateRules($this->request('PUT', '/api/v1/settings/briefing-rules', $auth, ['reset' => true]), $this->response());
        $this->assertEquals(5.0, $this->settings->briefingRules()['wind']['yellow']);

        $usage = $this->decode($this->admin()->usage($this->request('GET', '/api/v1/admin/ai/usage', $auth, null, ['month' => gmdate('Y-m')]), $this->response()))['data'];
        $this->assertSame(1, $usage['summary_month']['requests']);
        $this->assertSame(1, $usage['summary_month']['cache_hits']);
        $this->assertEquals(1600.0, $usage['summary_month']['avg_total']); // input incl. cache reads + output
        $names = array_column($usage['users'], 'username');
        $this->assertSame('paddler', $names[0]);
        $this->assertContains('idle', $names);
        $this->assertContains('chef', $names);
        $this->assertNotContains('nobody', $names);
        $paddlerRow = $usage['users'][0];
        $this->assertSame(1, $paddlerRow['requests']);
        $this->assertSame(1600, $paddlerRow['total_tokens']);

        // a deleted user's usage stays, under the stored name
        $this->pdo->exec("DELETE FROM route WHERE id = $routeId");
        $this->pdo->exec("DELETE FROM user WHERE id = $paddler");
        $usage = $this->decode($this->admin()->usage($this->request('GET', '/api/v1/admin/ai/usage', $auth, null, ['month' => gmdate('Y-m')]), $this->response()))['data'];
        $this->assertSame('paddler', $usage['users'][0]['username']);
        $this->assertTrue($usage['users'][0]['deleted']);
        $this->assertNull($usage['users'][0]['user_id']);
        unset($idle);
    }

    public function testAdminEndpointsValidateAndNeedAnAdmin(): void
    {
        $admin = $this->createUser(['is_admin' => 1]);
        $auth = $this->authPayload($admin, ['is_admin' => true]);
        try {
            $this->admin()->updateSettings($this->request('PUT', '/api/v1/settings/ai', $auth, ['enabled' => true, 'daily_limit' => -1, 'cache_hours' => 3]), $this->response());
            $this->fail('negative limit accepted');
        } catch (ValidationException) {
        }
        try {
            $this->admin()->usage($this->request('GET', '/api/v1/admin/ai/usage', $auth, null, ['month' => '2026-13']), $this->response());
            $this->fail('invalid month accepted');
        } catch (ValidationException) {
        }
        $this->expectException(ForbiddenException::class);
        $user = $this->createUser(['ai_use' => 1]);
        $this->admin()->usage($this->request('GET', '/api/v1/admin/ai/usage', $this->authPayload($user, ['ai_use' => true])), $this->response());
    }

    public function testConnectionTest(): void
    {
        $admin = $this->createUser(['is_admin' => 1]);
        $auth = $this->authPayload($admin, ['is_admin' => true]);
        $ok = $this->decode($this->admin()->test($this->request('POST', '/api/v1/settings/ai/test', $auth), $this->response()));
        $this->assertSame('claude-test', $ok['data']['model']);
        $this->assertSame(1200, $ok['data']['input_tokens']);

        $this->ai->willFail('auth');
        try {
            $this->admin()->test($this->request('POST', '/api/v1/settings/ai/test', $auth), $this->response());
            $this->fail('expected the failure to surface');
        } catch (ApiException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame('route_check.test_auth', $e->getErrorCode());
        }
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM ai_usage')->fetchColumn());
    }

    public function testFindInBoxShowsPublicAndOwnPoisOnly(): void
    {
        $me = $this->createUser();
        $other = $this->createUser();
        $insert = $this->pdo->prepare('INSERT INTO poi (poitype_id, user_id, name, description, public, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([2, $other, 'public landing', '', 1, 56.01, 10.01]);
        $insert->execute([2, $other, 'private landing', '', 0, 56.01, 10.01]);
        $insert->execute([2, $me, 'my landing', '', 0, 56.01, 10.01]);
        $insert->execute([3, $other, 'water', '', 1, 56.01, 10.01]);
        $insert->execute([2, $other, 'far away', '', 1, 57.0, 10.01]);

        $names = array_column((new PoiRepository($this->pdo))->findInBox(55.9, 56.1, 9.9, 10.1, [2], $me), 'name');
        sort($names);
        $this->assertSame(['my landing', 'public landing'], $names);
        $this->assertSame(['public landing'], array_column((new PoiRepository($this->pdo))->findInBox(55.9, 56.1, 9.9, 10.1, [2], null), 'name'));
    }
}
