<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\RadarSettingsController;
use Ytan\Exception\ApiException;
use Ytan\Service\RadarConfig;
use Ytan\Service\RadarJobRunner;

final class RadarSettingsControllerTest extends ControllerTestCase
{
    private RadarSettingsController $controller;
    private string $radarDir;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        // app_settings is a singleton row; the structure-only test DB has none
        $this->pdo->exec('INSERT INTO app_settings (id) VALUES (1)');
        // a fake checkout root without tools/radar/.venv: the job is "not installed"
        $this->root = sys_get_temp_dir() . '/ytan-radar-root-' . uniqid();
        $this->radarDir = $this->root . '/storage/radar';
        $this->controller = new RadarSettingsController(new SettingsRepository($this->pdo), $this->radarDir, new RadarJobRunner($this->root));
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter(glob($this->radarDir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->radarDir);
        @rmdir($this->root . '/storage');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testShowGivesTheDefaultsBeforeAnythingWasSaved(): void
    {
        $admin = $this->authPayload($this->createUser(), ['is_admin' => true]);
        $data = $this->decode($this->controller->show($this->request('GET', '/api/v1/settings/radar', $admin), $this->response()))['data'];

        $this->assertSame(60, $data['forecast_minutes']);
        $this->assertSame([60, 5, 4], [$data['history_minutes'], $data['fetch_interval_minutes'], $data['workers']]);
        $this->assertSame(120_000_000, $data['max_mosaic_pixels']);
        $this->assertSame(50_000_000, $data['max_mosaic_pixels_min']);
        $this->assertNull($data['status']); // the job has not run yet
        $this->assertSame(['de', 'dk', 'se'], $data['presets']);
        $this->assertSame([], $data['custom']);
        $this->assertArrayHasKey('fi', $data['preset_regions']);
        $this->assertGreaterThan(0, $data['mosaic_pixels']);
    }

    public function testUpdatePersistsAndWritesTheJobConfig(): void
    {
        $admin = $this->authPayload($this->createUser(), ['is_admin' => true]);
        $body = [
            'history_minutes' => 30,
            'forecast_minutes' => 45,
            'fetch_interval_minutes' => 15,
            'workers' => 8,
            'max_mosaic_pixels' => 80_000_000,
            'presets' => ['dk'],
            'custom' => [['name' => 'Bornholm', 'south' => 54.9, 'west' => 14.6, 'north' => 55.4, 'east' => 15.3]],
        ];
        $this->controller->update($this->request('PUT', '/api/v1/settings/radar', $admin, $body), $this->response());

        $stored = (new SettingsRepository($this->pdo))->radarSettings();
        $this->assertSame(45, $stored['forecast_minutes']);
        $this->assertSame([30, 15, 8], [$stored['history_minutes'], $stored['fetch_interval_minutes'], $stored['workers']]);
        $this->assertSame(80_000_000, $stored['max_mosaic_pixels']);
        $this->assertSame(['dk'], $stored['presets']);
        $this->assertSame('Bornholm', $stored['custom'][0]['name']);

        $job = json_decode((string) file_get_contents($this->radarDir . '/config.json'), true);
        $this->assertSame(45, $job['forecast_minutes']);
        $this->assertSame(8, $job['workers']);
        $this->assertSame(['dk', 'Bornholm'], array_column($job['regions'], 'name'));
    }

    public function testStoredCodesThatNoLongerExistAreDropped(): void
    {
        // e.g. "baltic"/"uk" from before every country got its own entry
        $this->pdo->prepare('UPDATE app_settings SET radar_regions = ? WHERE id = 1')
            ->execute([json_encode(['presets' => ['de', 'baltic', 'uk'], 'custom' => []])]);
        $this->assertSame(['de'], (new SettingsRepository($this->pdo))->radarSettings()['presets']);
    }

    public function testInvalidInputChangesNothing(): void
    {
        $admin = $this->authPayload($this->createUser(), ['is_admin' => true]);
        try {
            $this->controller->update($this->request('PUT', '/api/v1/settings/radar', $admin, ['history_minutes' => 60, 'forecast_minutes' => 7, 'fetch_interval_minutes' => 5, 'workers' => 4, 'max_mosaic_pixels' => 120_000_000, 'presets' => ['de']]), $this->response());
            $this->fail('expected a ValidationException');
        } catch (ValidationException) {
        }
        $this->assertSame(60, (new SettingsRepository($this->pdo))->radarSettings()['forecast_minutes']);
        $this->assertFileDoesNotExist($this->radarDir . '/config.json');
    }

    public function testShowIncludesTheJobStatus(): void
    {
        mkdir($this->radarDir, 0775, true);
        file_put_contents($this->radarDir . '/status.json', json_encode([
            'last_run' => ['start' => '2026-10-18T10:00:00Z', 'outcome' => 'processed'],
            'last_processing' => ['download' => ['bytes' => 7000000], 'processing' => ['workers' => 4]],
            'storage_bytes' => 90000000,
        ]));
        $admin = $this->authPayload($this->createUser(), ['is_admin' => true]);
        $data = $this->decode($this->controller->show($this->request('GET', '/api/v1/settings/radar', $admin), $this->response()))['data'];
        $this->assertSame('processed', $data['status']['last_run']['outcome']);
        $this->assertSame(4, $data['status']['last_processing']['processing']['workers']);
        $this->assertSame(90000000, $data['status']['storage_bytes']);
    }

    public function testRunNowWhileTheJobHoldsItsLockIs409(): void
    {
        mkdir($this->radarDir, 0775, true);
        $lock = fopen($this->radarDir . '/.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB)); // what radar_job.py does for a whole run
        try {
            $admin = $this->authPayload($this->createUser(), ['is_admin' => true]);
            $show = $this->decode($this->controller->show($this->request('GET', '/api/v1/settings/radar', $admin), $this->response()))['data'];
            $this->assertTrue($show['running']);
            try {
                $this->controller->run($this->request('POST', '/api/v1/settings/radar/run', $admin), $this->response());
                $this->fail('expected an ApiException');
            } catch (ApiException $e) {
                $this->assertSame(409, $e->getStatusCode());
                $this->assertSame('radar.job_running', $e->getErrorCode());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertFalse((new RadarJobRunner($this->root))->isRunning());
    }

    public function testRunNowWithoutAnInstalledJobIs503(): void
    {
        $admin = $this->authPayload($this->createUser(), ['is_admin' => true]);
        try {
            $this->controller->run($this->request('POST', '/api/v1/settings/radar/run', $admin), $this->response());
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertSame('radar.job_not_installed', $e->getErrorCode());
        }
    }

    public function testRunNowIsAdminOnly(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->controller->run($this->request('POST', '/api/v1/settings/radar/run', $this->authPayload($this->createUser())), $this->response());
    }

    private function admin(): array
    {
        return $this->authPayload($this->createUser(), ['is_admin' => true]);
    }

    public function testPauseForMinutesEndsOnItsOwnAndReachesTheJob(): void
    {
        $data = $this->decode($this->controller->pause(
            $this->request('POST', '/api/v1/settings/radar/pause', $this->admin(), ['minutes' => 15]), $this->response()))['data'];
        $this->assertTrue($data['paused']);
        $this->assertFalse($data['paused_forever']);
        $until = strtotime($data['paused_until']);
        $this->assertEqualsWithDelta(time() + 15 * 60, $until, 5);
        $job = json_decode((string) file_get_contents($this->radarDir . '/config.json'), true);
        $this->assertSame($data['paused_until'], $job['paused_until']);
        $this->assertFalse($job['paused_forever']);
        // ends on its own: no longer paused once the time has passed
        $this->assertFalse(\Ytan\Service\RadarConfig::isPaused($data, $until + 1));
    }

    public function testPauseForeverAndResume(): void
    {
        $admin = $this->admin();
        $data = $this->decode($this->controller->pause(
            $this->request('POST', '/api/v1/settings/radar/pause', $admin, ['forever' => true]), $this->response()))['data'];
        $this->assertTrue($data['paused']);
        $this->assertTrue($data['paused_forever']);
        $this->assertTrue(json_decode((string) file_get_contents($this->radarDir . '/config.json'), true)['paused_forever']);

        $data = $this->decode($this->controller->resume(
            $this->request('DELETE', '/api/v1/settings/radar/pause', $admin), $this->response()))['data'];
        $this->assertFalse($data['paused']);
        $job = json_decode((string) file_get_contents($this->radarDir . '/config.json'), true);
        $this->assertFalse($job['paused_forever']);
        $this->assertNull($job['paused_until']);
    }

    public function testPauseOnlyForTheOfferedDurations(): void
    {
        try {
            $this->controller->pause($this->request('POST', '/api/v1/settings/radar/pause', $this->admin(), ['minutes' => 45]), $this->response());
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('radar.pause_invalid', $e->getErrorCode());
        }
    }

    public function testRunNowWhilePausedIs409(): void
    {
        $admin = $this->admin();
        $this->controller->pause($this->request('POST', '/api/v1/settings/radar/pause', $admin, ['forever' => true]), $this->response());
        try {
            $this->controller->run($this->request('POST', '/api/v1/settings/radar/run', $admin), $this->response());
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('radar.job_paused', $e->getErrorCode());
        }
    }

    public function testSavingTheSettingsKeepsThePause(): void
    {
        $admin = $this->admin();
        $this->controller->pause($this->request('POST', '/api/v1/settings/radar/pause', $admin, ['forever' => true]), $this->response());
        $this->controller->update($this->request('PUT', '/api/v1/settings/radar', $admin, RadarConfig::defaults()), $this->response());
        $this->assertTrue((new SettingsRepository($this->pdo))->radarSettings()['paused_forever']);
        $this->assertTrue(json_decode((string) file_get_contents($this->radarDir . '/config.json'), true)['paused_forever']);
    }

    public function testPauseIsAdminOnly(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->controller->pause($this->request('POST', '/api/v1/settings/radar/pause', $this->authPayload($this->createUser()), ['minutes' => 15]), $this->response());
    }

    public function testNonAdminIsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->controller->update($this->request('PUT', '/api/v1/settings/radar', $this->authPayload($this->createUser()), RadarConfig::defaults()), $this->response());
    }

    public function testAnonymousIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->controller->show($this->request('GET', '/api/v1/settings/radar'), $this->response());
    }
}
