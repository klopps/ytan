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
    /** @var (\Closure(float, float, int): ?array)|null river/canal lookup handed to the builder */
    private ?\Closure $flow = null;
    /** @var (\Closure(float, float, int): ?array)|null nearest sea coastline lookup handed to the builder */
    private ?\Closure $sea = null;
    /** @var (\Closure(float, float, ?array, int, int, int): ?array)|null tide source handed to the builder */
    private ?\Closure $tide = null;
    /** @var (\Closure(float, float, int): ?array)|null river gauge lookup handed to the builder */
    private ?\Closure $gauge = null;
    /** @var (\Closure(float, float): array)|null replaces forecast() when set (may throw) */
    private ?\Closure $forecastAt = null;

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

                return $this->forecastAt !== null ? ($this->forecastAt)($lat, $lng) : $this->forecast();
            },
            fn () => null,
            fn (float $south, float $north, float $west, float $east, array $types, ?int $userId) => array_map(
                fn (array $poi) => $poi + ['type_label' => 'Typ ' . $poi['poitype_id']],
                (new PoiRepository($this->pdo))->findInBox($south, $north, $west, $east, $types, $userId)
            ),
            $this->flow,
            $this->sea,
            $this->tide,
            $this->gauge,
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

    public function testTheResultNamesEveryWeatherSourceAlongTheRoute(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        // ~33 km north: 5 sample points at 56.03, 56.09, 56.15, 56.21, 56.27
        $routeId = $this->createRoute($userId, ['public' => 1, 'points' => json_encode([['lat' => 56.0, 'lng' => 10.0], ['lat' => 56.3, 'lng' => 10.0]])]);
        $this->forecastAt = fn (float $lat, float $lng) => [
            'source' => $lat < 56.12 ? 'DWD' : 'DMI',
            'has_marine_data' => $lat > 56.2,
        ] + $this->forecast();

        $weather = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false])['data']['weather'];

        $this->assertSame(['DWD', 'DMI'], $weather['sources'], 'in route order, each once');
        $this->assertTrue($weather['marine']);
        $this->assertSame('DWD', $weather['source'], 'the first point, as before');
    }

    public function testWithoutAnyWeatherThereAreNoSources(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->forecastAt = fn (float $lat, float $lng) => throw new \RuntimeException('down');

        $data = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false])['data'];

        $this->assertNull($data['weather']);
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

    public function testOnARiverTheCurrentIsTheAdminsDefaultOrThePaddlersOwn(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId); // 5.6 km heading north
        $this->flow = fn (float $lat, float $lng, int $maxMeters) => ['kind' => 'river', 'bearing' => 180.0, 'distance' => 10];

        $default = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6])['data'];
        $this->assertTrue($default['current']['on_river']);
        $this->assertEquals(2.0, $default['current']['river_kmh'], 'BriefingRules default');
        $this->assertSame('current', $default['reasons'][0]['criterion'], '2 km/h against the course = yellow');
        $this->assertSame('river', $default['sections'][0]['values']['water']);

        $none = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6, 'current_kmh' => 0])['data'];
        $this->assertEquals(0.0, $none['current']['river_kmh']);
        $this->assertNotContains('current', array_column($none['reasons'], 'criterion'));
        $this->assertGreaterThan($none['end'], $default['end'], 'against the current takes longer');
        $this->assertEquals(2.0, RouteBriefingBuilder::aiContext($default)['route']['assumed_river_current_kmh']);
    }

    /** @return array{station: string, water: string, distance_m: int, value_cm: float, time: int, mhw: ?string, hsw: ?string} */
    private function gaugeReading(?string $mhw, ?string $hsw = 'normal'): array
    {
        return ['station' => 'Hitzacker', 'water' => 'Elbe', 'distance_m' => 4200, 'value_cm' => 512.0, 'time' => time() - 600, 'mhw' => $mhw, 'hsw' => $hsw];
    }

    public function testHighWaterAtARiverGaugeMakesTheRouteYellow(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->flow = fn (float $lat, float $lng, int $maxMeters) => ['kind' => 'river', 'bearing' => 0.0, 'distance' => 10];
        $asked = [];
        $this->gauge = function (float $lat, float $lng, int $maxMeters) use (&$asked) {
            $asked[] = $maxMeters;

            return $this->gaugeReading('high');
        };

        $result = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6])['data'];

        $this->assertSame(20000, $asked[0]);
        $this->assertSame(1, $result['rating'], 'calm weather, with the current - only the gauge');
        $this->assertSame(['criterion' => 'river_high_water', 'value' => 'Hitzacker', 'threshold' => null, 'level' => 1], $result['reasons'][0]);
        $this->assertCount(1, $result['current']['gauges'], 'one station, however many sections');
        $this->assertSame('high', $result['current']['gauges'][0]['state']);
        $this->assertSame('Elbe', $result['current']['gauges'][0]['water']);
        $this->assertTrue($result['current']['gauges_rated']);
        $this->assertSame(1, min(array_column($result['windows'], 'rating')), 'every start hour at least yellow (dark ones stay red)');

        $context = RouteBriefingBuilder::aiContext($result);
        $this->assertSame([['gauge' => 'Hitzacker', 'water' => 'Elbe', 'distance_km' => 4.2, 'level_cm' => 510, 'state' => 'high']], $context['river_gauges']);
        $this->assertArrayNotHasKey('river_gauges_measured_days_before', $context['route']);
    }

    public function testAGaugeAboveTheHighestNavigableLevelMakesItRed(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->flow = fn (float $lat, float $lng, int $maxMeters) => ['kind' => 'river', 'bearing' => 0.0, 'distance' => 10];
        $this->gauge = fn () => $this->gaugeReading('high', 'high');

        $result = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6])['data'];

        $this->assertSame(2, $result['rating']);
        $this->assertSame('river_hsw', $result['reasons'][0]['criterion']);
        $this->assertSame('hsw', $result['current']['gauges'][0]['state']);
    }

    public function testForALaterDayTheGaugeIsShownButDoesNotRate(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->flow = fn (float $lat, float $lng, int $maxMeters) => ['kind' => 'river', 'bearing' => 0.0, 'distance' => 10];
        $this->gauge = fn () => $this->gaugeReading('high', 'high');

        $result = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6, 'date' => gmdate('Y-m-d', strtotime('+3 days'))])['data'];

        $this->assertSame(0, $result['rating']);
        $this->assertSame('hsw', $result['current']['gauges'][0]['state']);
        $this->assertFalse($result['current']['gauges_rated']);
        $this->assertTrue(RouteBriefingBuilder::aiContext($result)['route']['river_gauges_measured_days_before']);
    }

    public function testGaugesOfASideWaterAreDroppedAndAtMostThreeShown(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->flow = fn (float $lat, float $lng, int $maxMeters) => ['kind' => 'river', 'bearing' => 0.0, 'distance' => 10];
        // a different gauge for every lookup: 5 on the Elbe, 1 on a canal (in high water)
        $n = 0;
        $this->gauge = function () use (&$n) {
            $n++;
            $reading = $this->gaugeReading('normal');
            $reading['station'] = 'P' . $n;
            $reading['distance_m'] = [1 => 900, 2 => 300, 3 => 5000, 4 => 100, 5 => 700, 6 => 50][$n] ?? 9000;
            if ($n === 6) {
                $reading['water'] = 'Müritz-Elde-Wasserstrasse';
                $reading['mhw'] = 'high';
            }

            return $n <= 6 ? $reading : null;
        };
        $route = json_encode(array_map(fn ($k) => ['lat' => 56.0 + $k * 0.02, 'lng' => 10.0], range(0, 10)));
        $this->pdo->exec('UPDATE route SET points = ' . $this->pdo->quote($route) . ' WHERE id = ' . $routeId);

        $result = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6])['data'];

        $this->assertSame(['P2', 'P4', 'P5'], array_column($result['current']['gauges'], 'station'), 'Elbe only, the 3 closest, in route order');
        $this->assertNotContains('river_high_water', array_column($result['reasons'], 'criterion'));
    }

    public function testNoGaugeOffTheRiverAndAFailingLookupIsHarmless(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $calls = 0;
        $this->gauge = function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('down');
        };

        $offRiver = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false])['data'];
        $this->assertSame(0, $calls, 'no river section, no lookup');
        $this->assertSame([], $offRiver['current']['gauges']);
        $this->assertArrayNotHasKey('river_gauges', array_filter(RouteBriefingBuilder::aiContext($offRiver), fn ($v) => $v !== null));

        $this->flow = fn (float $lat, float $lng, int $maxMeters) => ['kind' => 'river', 'bearing' => 0.0, 'distance' => 10];
        $onRiver = @$this->check($userId, $routeId, ['ai_use' => true], ['text' => false])['data'];
        $this->assertGreaterThan(0, $calls);
        $this->assertSame(0, $onRiver['rating']);
        $this->assertSame([], $onRiver['current']['gauges']);
    }

    public function testNearTheCoastOfATidalSeaTheRiverGetsNoCurrentButAHint(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->flow = fn (float $lat, float $lng, int $maxMeters) => ['kind' => 'river', 'bearing' => 180.0, 'distance' => 10];
        $asked = [];
        // the Elbe estuary at Wedel: North Sea, tidal
        $this->sea = function (float $lat, float $lng, int $maxMeters) use (&$asked) {
            $asked[] = $maxMeters;

            return ['distance' => 21000, 'lat' => 53.581, 'lng' => 9.658];
        };

        $result = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6])['data'];

        $this->assertSame(50000, $asked[0], 'BriefingRules default tidal_sea_km = 50');
        $this->assertTrue($result['current']['on_tidal']);
        $this->assertFalse($result['current']['on_river'], 'no current chips on a tidal river');
        $this->assertNotContains('current', array_column($result['reasons'], 'criterion'), 'no current against the course');
        $this->assertSame('river', $result['sections'][0]['values']['water']);
        $this->assertTrue($result['sections'][0]['values']['tidal']);
        $this->assertNull($result['sections'][0]['values']['current']);
        $context = RouteBriefingBuilder::aiContext($result);
        $this->assertTrue($context['route']['partly_on_tidal_river']);
        $this->assertTrue($context['sections'][0]['tidal_river']);
        $this->assertArrayNotHasKey('assumed_river_current_kmh', $context['route']);
    }

    /** Tidal river with tide times: low water 07:00, high water 13:00, low water 19:00 tomorrow (UTC = local). */
    private function tidalRiverWithTimes(float $meanRange = 3.8, string $source = 'bsh'): void
    {
        $date = gmdate('Y-m-d', strtotime('+1 day'));
        $this->flow = fn (float $lat, float $lng, int $maxMeters) => ['kind' => 'river', 'bearing' => 180.0, 'distance' => 10];
        $this->sea = fn (float $lat, float $lng, int $maxMeters) => ['distance' => 21000, 'lat' => 53.581, 'lng' => 9.658];
        $this->tide = fn (float $lat, float $lng, ?array $coast, int $from, int $to, int $maxStation) => [
            'station' => 'Hamburg, St. Pauli, Elbe', 'source' => $source, 'attribution' => '©BSH',
            'lat' => 53.54556, 'lng' => 9.97, 'distance_m' => 300, 'mean_range_m' => $meanRange,
            'events' => [
                ['time' => strtotime("$date 07:00:00 UTC"), 'type' => 'NW', 'height_m' => 3.5],
                ['time' => strtotime("$date 13:00:00 UTC"), 'type' => 'HW', 'height_m' => 7.2],
                ['time' => strtotime("$date 19:00:00 UTC"), 'type' => 'NW', 'height_m' => 3.5],
            ],
        ];
    }

    public function testWithTideTimesTheTidalCurrentDependsOnTheHour(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId); // heading north = up the river (it flows south)
        $this->tidalRiverWithTimes();

        // 10:00 = half flood: the water runs up the river, with the course
        $flood = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6])['data'];
        $this->assertTrue($flood['current']['on_tidal']);
        $this->assertEquals(3.0, $flood['current']['tidal_kmh'], 'BriefingRules default');
        $this->assertSame('Hamburg, St. Pauli, Elbe', $flood['current']['tides'][0]['station']);
        $this->assertSame(['07:00', '13:00', '19:00'], array_column($flood['current']['tides'][0]['events'], 'time'));
        $this->assertSame('flood', $flood['sections'][0]['values']['tide_phase']);
        $this->assertGreaterThan(2.5, $flood['sections'][0]['values']['current']);
        $this->assertNotContains('current', array_column($flood['reasons'], 'criterion'));

        // 16:00 = half ebb: against the course, 3 km/h = yellow
        $ebb = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6, 'start_hour' => 16])['data'];
        $this->assertSame('ebb', $ebb['sections'][0]['values']['tide_phase']);
        $this->assertSame('current', $ebb['reasons'][0]['criterion']);
        $this->assertSame(1, $ebb['rating']);

        // the start hour bar sees it too
        $windows = array_column($flood['windows'], 'reason', 'hour');
        $this->assertSame('current', $windows[16]);
        $this->assertNotSame('current', $windows[10]);

        $context = RouteBriefingBuilder::aiContext($ebb);
        $this->assertEquals(3.0, $context['route']['assumed_max_tidal_current_kmh']);
        $this->assertSame(['NW 07:00', 'HW 13:00', 'NW 19:00'], $context['tides'][0]['high_low_water']);
        $this->assertSame('ebb', $context['sections'][0]['tide_phase']);
        $this->assertFalse($flood['current']['tides'][0]['model'], 'a gauge');
        $this->assertArrayNotHasKey('model_times_approximate', $context['tides'][0]);
    }

    public function testOpenMeteoTideTimesAreMarkedAsAModel(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->tidalRiverWithTimes(3.8, 'open-meteo');

        $result = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6])['data'];

        $this->assertTrue($result['current']['tides'][0]['model']);
        $this->assertTrue(RouteBriefingBuilder::aiContext($result)['tides'][0]['model_times_approximate']);
    }

    public function testThePaddlerCanChangeTheTidalCurrent(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->tidalRiverWithTimes();

        $strong = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6, 'start_hour' => 16, 'tidal_current_kmh' => 5])['data'];

        $this->assertEquals(5.0, $strong['current']['tidal_kmh']);
        $this->assertSame(2, $strong['rating'], '5 km/h against the course = red');
    }

    public function testASmallMeanRangeIsNoTidalRiver(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->tidalRiverWithTimes(0.3);

        $result = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6])['data'];

        $this->assertFalse($result['current']['on_tidal']);
        $this->assertTrue($result['current']['on_river'], 'the normal river current again');
        $this->assertSame([], $result['current']['tides']);
    }

    public function testNearATidelessSeaOrWithTheCheckOffTheRiverKeepsItsCurrent(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        $this->flow = fn (float $lat, float $lng, int $maxMeters) => ['kind' => 'river', 'bearing' => 180.0, 'distance' => 10];
        // the Warnow at Rostock: Baltic, no tide
        $this->sea = fn (float $lat, float $lng, int $maxMeters) => ['distance' => 9990, 'lat' => 54.178, 'lng' => 12.107];

        $baltic = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6])['data'];
        $this->assertFalse($baltic['current']['on_tidal']);
        $this->assertTrue($baltic['current']['on_river']);
        $this->assertSame('current', $baltic['reasons'][0]['criterion']);

        $this->settings->setBriefingRules(['tidal_sea_km' => 0]);
        $this->sea = fn () => $this->fail('no coastline lookup with the check switched off');
        $off = $this->check($userId, $routeId, ['ai_use' => true], ['text' => false, 'speed_kmh' => 6])['data'];
        $this->assertFalse($off['current']['on_tidal']);
        $this->assertTrue($off['current']['on_river']);
    }

    public function testWithoutARiverThereIsNoCurrent(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $this->flow = fn () => null;

        $result = $this->check($userId, $this->route($userId), ['ai_use' => true], ['text' => false])['data'];

        $this->assertFalse($result['current']['on_river']);
        $this->assertArrayNotHasKey('assumed_river_current_kmh', RouteBriefingBuilder::aiContext($result)['route']);
    }

    public function testInvalidOptions(): void
    {
        $userId = $this->createUser(['ai_use' => 1]);
        $routeId = $this->route($userId);
        foreach ([['date' => '2020-01-01'], ['date' => 'morgen'], ['start_hour' => 24], ['speed_kmh' => 50], ['level' => 'pro'], ['current_kmh' => 11], ['current_kmh' => -1]] as $body) {
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
