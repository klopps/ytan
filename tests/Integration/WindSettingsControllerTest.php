<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\RadarSettingsController;
use Ytan\Http\Controllers\WindController;
use Ytan\Http\Controllers\WindSettingsController;
use Ytan\Service\RadarJobRunner;
use Ytan\Service\WindDataService;

final class WindSettingsControllerTest extends ControllerTestCase
{
    private WindSettingsController $controller;
    private string $root;
    private string $radarDir;
    private string $windDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('INSERT INTO app_settings (id) VALUES (1)');
        $this->root = sys_get_temp_dir() . '/ytan-wind-root-' . uniqid();
        $this->radarDir = $this->root . '/storage/radar';
        $this->windDir = $this->root . '/storage/wind';
        $this->controller = new WindSettingsController(
            new SettingsRepository($this->pdo),
            $this->radarDir,
            $this->windDir,
            new RadarJobRunner($this->root, 'wind')
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) as $f) {
                if ($f !== '.' && $f !== '..') {
                    $this->rmrf($path . '/' . $f);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    private function admin(): array
    {
        return $this->authPayload($this->createUser(), ['is_admin' => true]);
    }

    private function body(array $override = []): array
    {
        return $override + ['enabled' => true, 'horizon_hours' => 48, 'run_interval_hours' => 3, 'gusts' => false, 'fine' => true];
    }

    public function testShowGivesTheDefaultsBeforeAnythingWasSaved(): void
    {
        $data = $this->decode($this->controller->show($this->request('GET', '/api/v1/settings/wind', $this->admin()), $this->response()))['data'];

        $this->assertSame([true, 48, 3, false, true], [$data['enabled'], $data['horizon_hours'], $data['run_interval_hours'], $data['gusts'], $data['fine']]);
        $this->assertNull($data['status']);
        $this->assertFalse($data['running']);
    }

    public function testUpdatePersistsAndWritesTheWindPartOfTheJobConfig(): void
    {
        $body = $this->body(['horizon_hours' => 120, 'run_interval_hours' => 6, 'gusts' => true, 'fine' => false]);
        $this->controller->update($this->request('PUT', '/api/v1/settings/wind', $this->admin(), $body), $this->response());

        $this->assertEquals($body, (new SettingsRepository($this->pdo))->windSettings());
        $job = json_decode((string) file_get_contents($this->radarDir . '/config.json'), true);
        $this->assertEquals($body, $job["wind"]);
        // the regions of the rain radar travel in the same file
        $this->assertSame(['de', 'dk', 'se'], array_column($job['regions'], 'name'));
    }

    public function testSavingTheRadarSettingsKeepsTheWindSettings(): void
    {
        $this->controller->update($this->request('PUT', '/api/v1/settings/wind', $this->admin(), $this->body(['horizon_hours' => 24])), $this->response());
        $radar = new RadarSettingsController(new SettingsRepository($this->pdo), $this->radarDir, new RadarJobRunner($this->root));
        $radar->update($this->request('PUT', '/api/v1/settings/radar', $this->admin(), [
            'history_minutes' => 60, 'forecast_minutes' => 60, 'fetch_interval_minutes' => 5, 'workers' => 4,
            'max_mosaic_pixels' => 120_000_000, 'presets' => ['de'],
        ]), $this->response());

        $job = json_decode((string) file_get_contents($this->radarDir . '/config.json'), true);
        $this->assertSame(24, $job['wind']['horizon_hours']);
        $this->assertSame(['de'], array_column($job['regions'], 'name'));
    }

    public function testInvalidInputChangesNothing(): void
    {
        try {
            $this->controller->update($this->request('PUT', '/api/v1/settings/wind', $this->admin(), $this->body(['horizon_hours' => 13])), $this->response());
            $this->fail('expected a ValidationException');
        } catch (ValidationException) {
        }
        $this->assertSame(48, (new SettingsRepository($this->pdo))->windSettings()['horizon_hours']);
        $this->assertFileDoesNotExist($this->radarDir . '/config.json');
    }

    public function testShowIncludesTheJobStatus(): void
    {
        mkdir($this->windDir, 0775, true);
        file_put_contents($this->windDir . '/status.json', json_encode(['last_run' => ['outcome' => 'processed'], 'storage_bytes' => 5]));
        $data = $this->decode($this->controller->show($this->request('GET', '/api/v1/settings/wind', $this->admin()), $this->response()))['data'];
        $this->assertSame('processed', $data['status']['last_run']['outcome']);
    }

    public function testOnlyAdminsMaySeeOrChangeThem(): void
    {
        $user = $this->authPayload($this->createUser());
        $this->expectException(ForbiddenException::class);
        $this->controller->update($this->request('PUT', '/api/v1/settings/wind', $user, $this->body()), $this->response());
    }

    public function testSwitchingOffRemovesTheManifestAtOnce(): void
    {
        mkdir($this->windDir, 0775, true);
        file_put_contents($this->windDir . '/manifest.json', '{}');
        $this->controller->update($this->request('PUT', '/api/v1/settings/wind', $this->admin(), $this->body(['enabled' => false])), $this->response());
        $this->assertFileDoesNotExist($this->windDir . '/manifest.json');
    }

    public function testRunWhenSwitchedOffIs409(): void
    {
        $this->controller->update($this->request('PUT', '/api/v1/settings/wind', $this->admin(), $this->body(['enabled' => false])), $this->response());
        try {
            $this->controller->run($this->request('POST', '/api/v1/settings/wind/run', $this->admin()), $this->response());
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame([409, 'wind.job_disabled'], [$e->getStatusCode(), $e->getErrorCode()]);
        }
    }

    public function testRunWithoutAnInstalledJobIs503(): void
    {
        try {
            $this->controller->run($this->request('POST', '/api/v1/settings/wind/run', $this->admin()), $this->response());
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame([503, 'wind.job_not_installed'], [$e->getStatusCode(), $e->getErrorCode()]);
        }
    }

    public function testRunWhileTheJobHoldsItsLockIs409(): void
    {
        mkdir($this->windDir, 0775, true);
        $lock = fopen($this->windDir . '/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->controller->run($this->request('POST', '/api/v1/settings/wind/run', $this->admin()), $this->response());
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame([409, 'wind.job_running'], [$e->getStatusCode(), $e->getErrorCode()]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testBlockResponseCarriesTheGzipHeaders(): void
    {
        mkdir($this->windDir . '/runs/202610182132/L1/000', 0775, true);
        file_put_contents($this->windDir . '/runs/202610182132/L1/000/23_18.bin.gz', 'bytes');
        $wind = new WindController(new WindDataService($this->windDir));
        $response = $wind->block(
            $this->request('GET', '/api/v1/wind/x'),
            $this->response(),
            ['run' => '202610182132', 'level' => 'L1', 'hour' => '000', 'block' => '23_18']
        );

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        $this->assertStringContainsString('immutable', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('bytes', (string) $response->getBody());
    }

    public function testManifestIsNullDataBeforeTheFirstRun(): void
    {
        $wind = new WindController(new WindDataService($this->windDir));
        $response = $wind->manifest($this->request('GET', '/api/v1/wind/manifest'), $this->response());
        $this->assertNull($this->decode($response)['data']);
        $this->assertStringContainsString('max-age=60', $response->getHeaderLine('Cache-Control'));
    }
}
