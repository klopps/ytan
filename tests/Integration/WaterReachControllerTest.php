<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\WaterReachController;
use Ytan\Service\RequestThrottle;
use Ytan\Service\Water\Autorouter;
use Ytan\Service\Water\WaterReach;
use Ytan\Tests\Fixtures\FakeShoreSource;

/** WaterReachController with an in-memory coast: a land block of 55.0-55.1 N / 10.0-10.1 E. */
final class WaterReachControllerTest extends ControllerTestCase
{
    private WaterReachController $controller;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('INSERT INTO app_settings (id, etmal_km) VALUES (1, 25)');
        $this->users = new UserRepository($this->pdo);
        $source = FakeShoreSource::island(55.0, 10.0, 55.1, 10.1);
        $this->controller = new WaterReachController(
            new WaterReach($source, new Autorouter($source)),
            $this->users,
            new SettingsRepository($this->pdo),
            new RequestThrottle($this->pdo)
        );
    }

    private function call(string $method, ?array $auth, array $body = []): array
    {
        return $this->decode($this->controller->{$method}($this->request('POST', '/x', $auth, $body), $this->response()));
    }

    public function testReachNeedsALogin(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->call('reach', null, ['lat' => 54.99, 'lng' => 10.05]);
    }

    public function testReachUsesTheUsersEtmal(): void
    {
        $userId = $this->createUser();
        $this->users->setEtmalKm($userId, 10);

        $data = $this->call('reach', $this->authPayload($userId), ['lat' => 54.99, 'lng' => 10.05])['data'];

        $this->assertSame(10.0, (float) $data['etmal_km']);
        $this->assertSame([5.0, 10.0], array_map('floatval', array_column($data['lines'], 'km')));
        $this->assertTrue($data['lines'][1]['main']);
        $this->assertNotEmpty($data['lines'][1]['paths']);
    }

    public function testTimedReachGivesHourLinesForTheEtmalAtTheProfilesSpeed(): void
    {
        $userId = $this->createUser();
        $this->users->setEtmalKm($userId, 10);
        $this->users->setDefaultSpeed($userId, 5.0);
        $start = time() + 3600;

        $data = $this->call('reach', $this->authPayload($userId), ['lat' => 54.99, 'lng' => 10.05, 'timed' => ['start' => $start]])['data'];

        $this->assertSame('timed', $data['mode']);
        $this->assertSame($start, $data['start']);
        // 10 km at 5 km/h = 2 h
        $this->assertSame(120, $data['duration_min']);
        $this->assertSame($start + 7200, $data['end']);
        $this->assertSame([1.0, 2.0], array_map('floatval', array_column($data['lines'], 'hours')));
        $this->assertTrue($data['lines'][1]['main']);
        $this->assertNull($data['wind'], 'no wind source in this test');
        $this->assertSame(2.0, (float) $data['current_kmh']['river'], 'the admin default');
    }

    public function testTimedReachChecksStartAndCurrent(): void
    {
        $auth = $this->authPayload($this->createUser());
        foreach ([
            ['timed' => ['start' => time() - 2 * 86400]],
            ['timed' => ['start' => time() + 9 * 86400]],
            ['timed' => ['start' => 'morgen']],
            ['timed' => 'yes'],
            ['timed' => ['start' => time() + 3600, 'current_kmh' => 12]],
        ] as $extra) {
            try {
                $this->call('reach', $auth, ['lat' => 54.99, 'lng' => 10.05] + $extra);
                $this->fail('accepted ' . json_encode($extra));
            } catch (ValidationException $e) {
                $this->assertContains($e->getErrorCode(), ['etmal.start_invalid', 'etmal.timed_invalid', 'route_check.current_invalid']);
            }
        }
    }

    public function testReachFromLandIsRefused(): void
    {
        try {
            $this->call('reach', $this->authPayload($this->createUser()), ['lat' => 55.05, 'lng' => 10.05]);
            $this->fail('accepted a start on land');
        } catch (ValidationException $e) {
            $this->assertSame('etmal.on_land', $e->getErrorCode());
        }
    }

    public function testEtmalIsReadAndSavedOnlyAsAValidNumber(): void
    {
        $auth = $this->authPayload($this->createUser());

        $this->assertSame(['etmal_km' => 25, 'default_km' => 25, 'min_km' => 5, 'max_km' => 60], $this->call('etmal', $auth)['data']);
        $this->assertSame(32, $this->call('saveEtmal', $auth, ['etmal_km' => 32])['data']['etmal_km']);

        foreach ([null, '', 4, 61, 'abc', 2.5] as $value) {
            try {
                $this->call('saveEtmal', $auth, ['etmal_km' => $value]);
                $this->fail('Accepted ' . var_export($value, true));
            } catch (ValidationException $e) {
                $this->assertSame('etmal.invalid', $e->getErrorCode());
            }
        }
        $this->assertSame(32, $this->call('etmal', $auth)['data']['etmal_km'], 'an invalid value changes nothing');
    }

    public function testANewUserStartsWithTheCurrentDefaultLaterChangesKeepTheirValue(): void
    {
        $settings = new SettingsRepository($this->pdo);
        $settings->setEtmalKm(40);
        $first = $this->users->create(['username' => 'etmal_a', 'email' => 'etmal_a@example.invalid']);
        $this->assertSame(40, $this->users->getEtmalKm((int) $first['id']));

        $settings->setEtmalKm(15);
        $second = $this->users->create(['username' => 'etmal_b', 'email' => 'etmal_b@example.invalid']);
        $this->assertSame(15, $this->users->getEtmalKm((int) $second['id']));
        $this->assertSame(40, $this->users->getEtmalKm((int) $first['id']), 'existing users keep theirs');
    }
}
