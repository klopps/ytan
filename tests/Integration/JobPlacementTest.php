<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\RadarSettingsController;
use Ytan\Http\Controllers\WindSettingsController;
use Ytan\Service\JobPlacement;
use Ytan\Service\RadarConfig;
use Ytan\Service\RadarJobRunner;

/**
 * Where a scheduled job runs (JobPlacement): the per-job switch on
 * /admin/radar and /admin/wind, its way into storage/radar/config.json
 * (read by tools/ytan_jobs.py here and by the YTAN worker), "update now" for
 * an external job, and the worker's heartbeat.
 */
final class JobPlacementTest extends ControllerTestCase
{
    private string $root;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('INSERT INTO app_settings (id) VALUES (1)');
        $this->root = sys_get_temp_dir() . '/ytan-placement-' . uniqid();
        mkdir($this->root . '/storage/radar', 0777, true);
        $this->settings = new SettingsRepository($this->pdo);
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

    private function wind(): WindSettingsController
    {
        return new WindSettingsController($this->settings, $this->root . '/storage/radar', $this->root . '/storage/wind', new RadarJobRunner($this->root, 'wind'));
    }

    private function radar(): RadarSettingsController
    {
        return new RadarSettingsController($this->settings, $this->root . '/storage/radar', new RadarJobRunner($this->root));
    }

    private function jobConfig(): array
    {
        return json_decode((string) file_get_contents($this->root . '/storage/radar/config.json'), true);
    }

    public function testNormalizeKeepsKnownJobsOnceInOrder(): void
    {
        $this->assertSame(['radar', 'wind'], JobPlacement::normalize(['wind', 'x', 'radar', 'wind', 7]));
        $this->assertSame(['wind'], JobPlacement::with(['radar'], 'radar', false) + JobPlacement::with([], 'wind', true));
    }

    public function testSwitchingAJobToTheWorkerIsStoredAndHandedToBothSides(): void
    {
        $data = $this->decode($this->wind()->placement($this->request('PUT', '/api/v1/settings/wind/placement', $this->admin(), ['external' => true]), $this->response()))['data'];

        $this->assertTrue($data['external']);
        $this->assertSame(['wind'], $this->settings->externalJobs());
        $this->assertSame(['wind'], $this->jobConfig()['external_jobs']);

        // saving other radar settings keeps the placement in config.json
        $this->radar()->resume($this->request('DELETE', '/api/v1/settings/radar/pause', $this->admin()), $this->response());
        $this->assertSame(['wind'], $this->jobConfig()['external_jobs']);

        $this->radar()->placement($this->request('PUT', '/api/v1/settings/radar/placement', $this->admin(), ['external' => true]), $this->response());
        $this->assertSame(['radar', 'wind'], $this->jobConfig()['external_jobs']);

        $data = $this->decode($this->wind()->placement($this->request('PUT', '/api/v1/settings/wind/placement', $this->admin(), ['external' => false]), $this->response()))['data'];
        $this->assertFalse($data['external']);
        $this->assertSame(['radar'], $this->jobConfig()['external_jobs']);
    }

    public function testPlacementNeedsABoolean(): void
    {
        $this->expectException(ValidationException::class);
        $this->wind()->placement($this->request('PUT', '/api/v1/settings/wind/placement', $this->admin(), ['external' => 'yes']), $this->response());
    }

    public function testUpdateNowForAnExternalJobLeavesARequestForTheWorker(): void
    {
        $this->settings->setExternalJobs(['wind']);
        $response = $this->wind()->run($this->request('POST', '/api/v1/settings/wind/run', $this->admin()), $this->response());

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($this->decode($response)['data']['external']);
        $this->assertFileExists($this->root . '/storage/wind/run-request');
        $show = $this->decode($this->wind()->show($this->request('GET', '/api/v1/settings/wind', $this->admin()), $this->response()))['data'];
        $this->assertTrue($show['run_requested']);

        // back on this server: the pending request is dropped
        $this->wind()->placement($this->request('PUT', '/api/v1/settings/wind/placement', $this->admin(), ['external' => false]), $this->response());
        $this->assertFileDoesNotExist($this->root . '/storage/wind/run-request');
    }

    public function testWriteJobConfigWithoutAPlacementKeepsTheOneInTheFile(): void
    {
        $dir = $this->root . '/storage/radar';
        RadarConfig::writeJobConfig($dir, RadarConfig::defaults(), null, ['radar']);
        RadarConfig::writeJobConfig($dir, RadarConfig::defaults());
        $this->assertSame(['radar'], $this->jobConfig()['external_jobs']);
    }

    public function testTheWorkersHeartbeatAndWhenItCountsAsAway(): void
    {
        $storage = $this->root . '/storage';
        $this->assertNull(JobPlacement::workerStatus($storage));
        file_put_contents($storage . '/worker-status.json', json_encode(['tick' => gmdate('c', 1_800_000_000), 'host' => 'homeassistant']));
        $this->assertFalse(JobPlacement::workerStatus($storage, 1_800_000_000 + 60)['stale']);
        $this->assertTrue(JobPlacement::workerStatus($storage, 1_800_000_000 + JobPlacement::WORKER_STALE_SECONDS + 1)['stale']);
        $this->assertSame('homeassistant', JobPlacement::payload($storage, 'wind', [])['worker']['host']);
    }
}
