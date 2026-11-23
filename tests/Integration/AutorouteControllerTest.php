<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\TooManyRequestsException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\AutorouteController;
use Ytan\Http\Controllers\SettingsController;
use Ytan\Service\RequestThrottle;
use Ytan\Service\Water\Autorouter;
use Ytan\Tests\Fixtures\FakeShoreSource;

/**
 * AutorouteController with an in-memory coast: a land block of
 * 55.0-55.1 N / 10.0-10.1 E, water all around.
 */
final class AutorouteControllerTest extends ControllerTestCase
{
    private AutorouteController $controller;
    private SettingsRepository $settings;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('INSERT INTO app_settings (id, google_search_requires_login, track_distance_filter_precise_m, track_distance_filter_balanced_m, track_distance_filter_battery_m, route_label_font_size_min, route_label_font_size_max, autoroute_shore_m) VALUES (1, 0, 20, 50, 100, 8, 20, 100)');
        $this->settings = new SettingsRepository($this->pdo);
        $this->users = new UserRepository($this->pdo);
        $this->controller = new AutorouteController(
            new Autorouter(FakeShoreSource::island(55.0, 10.0, 55.1, 10.1)),
            $this->users,
            $this->settings,
            new RequestThrottle($this->pdo)
        );
    }

    /** @return list<array{lat: float, lng: float}> */
    private static function points(array ...$pairs): array
    {
        return array_map(static fn (array $p): array => ['lat' => $p[0], 'lng' => $p[1]], $pairs);
    }

    private function call(string $method, array $auth, array $body): array
    {
        $request = $this->request('POST', '/api/v1/routes/autoroute', $auth, $body);

        return $this->decode($this->controller->{$method}($request, $this->response()));
    }

    public function testNeedsALogin(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->controller->check($this->request('POST', '/x', null, ['points' => self::points([54.9, 9.9], [54.9, 10.0])]), $this->response());
    }

    public function testCheckSaysOkForWaterAndNamesTheProblems(): void
    {
        $auth = $this->authPayload($this->createUser());

        $ok = $this->call('check', $auth, ['points' => self::points([54.99, 9.95], [54.99, 10.15])]);
        $this->assertSame(['ok' => true, 'problems' => []], $ok['data']);

        $land = $this->call('check', $auth, ['points' => self::points([55.05, 10.05], [54.99, 10.15])]);
        $this->assertFalse($land['data']['ok']);
        $this->assertSame(['start_on_land'], $land['data']['problems']);
    }

    public function testRouteRefusesAStartOnLandWith422(): void
    {
        $auth = $this->authPayload($this->createUser());

        try {
            $this->call('route', $auth, ['points' => self::points([55.05, 10.05], [54.99, 10.15]), 'shore_m' => 100, 'enabled' => true]);
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('autoroute.start_on_land', $e->getErrorCode());
        }
    }

    public function testRouteGoesAroundTheLandAndRemembersThePreferences(): void
    {
        $userId = $this->createUser();
        $auth = $this->authPayload($userId);

        $answer = $this->call('route', $auth, ['points' => self::points([55.05, 9.95], [55.05, 10.15]), 'shore_m' => 250, 'enabled' => false]);

        $this->assertSame(200, $answer['status']);
        $this->assertSame(1, $answer['data']['changed_sections']);
        $this->assertGreaterThan($answer['data']['length_before'], $answer['data']['length_after']);
        $this->assertSame(['lat' => 55.05, 'lng' => 9.95], $answer['data']['points'][0]);
        $this->assertSame(['shore_m' => 250, 'max_m' => null, 'enabled' => false], $this->users->getAutoroutePrefs($userId));
    }

    public function testAValueEqualToTheDefaultIsStoredAsTheDefault(): void
    {
        $userId = $this->createUser();
        $this->call('route', $this->authPayload($userId), ['points' => self::points([54.9, 9.9], [54.9, 10.0]), 'shore_m' => 100, 'enabled' => true]);

        $this->assertSame(['shore_m' => null, 'max_m' => null, 'enabled' => true], $this->users->getAutoroutePrefs($userId));

        $this->call('route', $this->authPayload($userId), ['points' => self::points([54.9, 9.9], [54.9, 10.0]), 'shore_m' => 100, 'max_m' => 400, 'enabled' => true]);
        $this->assertSame(400, $this->users->getAutoroutePrefs($userId)['max_m']);
        $this->call('route', $this->authPayload($userId), ['points' => self::points([54.9, 9.9], [54.9, 10.0]), 'shore_m' => 100, 'max_m' => 1000, 'enabled' => true]);
        $this->assertNull($this->users->getAutoroutePrefs($userId)['max_m'], 'the default maximum is stored as the default');
    }

    public function testPreferencesFollowTheAdminDefaultUntilTheUserSetsOne(): void
    {
        $userId = $this->createUser();
        $auth = $this->authPayload($userId);

        $first = $this->decode($this->controller->preferences($this->request('GET', '/x', $auth), $this->response()));
        $this->assertSame(
            ['shore_m' => 100, 'custom' => false, 'max_m' => 1000, 'max_custom' => false, 'enabled' => true, 'default_m' => 100, 'default_max_m' => 1000],
            $first['data']
        );

        $this->settings->setAutorouteShoreMeters(300);
        $second = $this->decode($this->controller->preferences($this->request('GET', '/x', $auth), $this->response()));
        $this->assertSame(300, $second['data']['shore_m'], 'a user without an own value gets the new default');

        $saved = $this->decode($this->controller->savePreferences($this->request('PUT', '/x', $auth, ['shore_m' => 50, 'enabled' => false]), $this->response()));
        $this->assertSame(
            ['shore_m' => 50, 'custom' => true, 'max_m' => 1000, 'max_custom' => false, 'enabled' => false, 'default_m' => 300, 'default_max_m' => 1000],
            $saved['data']
        );

        $reset = $this->decode($this->controller->savePreferences($this->request('PUT', '/x', $auth, ['shore_m' => null]), $this->response()));
        $this->assertSame(300, $reset['data']['shore_m']);
        $this->assertFalse($reset['data']['custom']);
        $this->assertFalse($reset['data']['enabled'], 'a body without "enabled" keeps it');
    }

    public function testInvalidInputIsRefused(): void
    {
        $auth = $this->authPayload($this->createUser());

        foreach ([
            ['points' => [['lat' => 1, 'lng' => 2]]],
            ['points' => 'x'],
            ['points' => [['lat' => 95, 'lng' => 2], ['lat' => 1, 'lng' => 2]]],
            ['points' => [['lat' => 'a', 'lng' => 2], ['lat' => 1, 'lng' => 2]]],
        ] as $body) {
            try {
                $this->call('check', $auth, $body);
                $this->fail('expected a ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame('autoroute.points_invalid', $e->getErrorCode());
            }
        }

        $this->expectException(ValidationException::class);
        $this->controller->savePreferences($this->request('PUT', '/x', $auth, ['shore_m' => 5000]), $this->response());
    }

    public function testRefusesAnEnormousRoute(): void
    {
        $this->expectException(ValidationException::class);
        $this->call('check', $this->authPayload($this->createUser()), ['points' => self::points([50.0, 5.0], [60.0, 25.0])]);
    }

    public function testRoutingIsThrottled(): void
    {
        $auth = $this->authPayload($this->createUser());
        $body = ['points' => self::points([54.9, 9.9], [54.9, 10.0]), 'shore_m' => 100, 'enabled' => true];

        for ($i = 0; $i < 15; $i++) {
            $this->call('route', $auth, $body);
        }
        $this->expectException(TooManyRequestsException::class);
        $this->call('route', $auth, $body);
    }

    public function testAdminCanSetTheDefaultAndOthersCannot(): void
    {
        $settings = new SettingsController($this->settings);
        $admin = $this->authPayload($this->createUser(), ['is_admin' => true]);

        $answer = $this->decode($settings->updateAutoroute($this->request('PUT', '/x', $admin, ['shore_m' => 150]), $this->response()));
        $this->assertSame(['shore_m' => 150, 'max_m' => 1000], $answer['data'], 'a body without max_m keeps it');

        $answer = $this->decode($settings->updateAutoroute($this->request('PUT', '/x', $admin, ['shore_m' => 150, 'max_m' => 0]), $this->response()));
        $this->assertSame(0, $answer['data']['max_m']);
        $this->assertSame(150, $this->settings->autorouteShoreMeters());

        try {
            $settings->updateAutoroute($this->request('PUT', '/x', $admin, ['shore_m' => 2001]), $this->response());
            $this->fail('expected a ValidationException');
        } catch (ValidationException) {
            $this->assertSame(150, $this->settings->autorouteShoreMeters());
        }

        $this->expectException(\Ytan\Exception\ForbiddenException::class);
        $settings->updateAutoroute($this->request('PUT', '/x', $this->authPayload($this->createUser()), ['shore_m' => 10]), $this->response());
    }
}
