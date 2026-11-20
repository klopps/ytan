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
 * Where a scheduled job runs (JobPlacement): the simple per-job switch on /admin/radar and
 * /admin/wind on top of the priority lists, their way into storage/radar/config.json (read by
 * tools/ytan_jobs.py here and by the YTAN workers), "update now" for a job a worker works on,
 * and what the workers report (storage/workers/{id}/). The election itself and the lists'
 * validation: tests/Unit/WorkerElectionTest.php; the admin page's API: WorkerSettingsControllerTest.
 */
final class JobPlacementTest extends ControllerTestCase
{
    use \Ytan\Tests\RemovesDirectories;

    private string $root;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('INSERT INTO app_settings (id) VALUES (1)');
        $this->root = sys_get_temp_dir() . '/ytan-placement-' . uniqid();
        mkdir($this->root . '/storage/radar', 0777, true);
        $this->settings = new SettingsRepository($this->pdo);
        $this->settings->setWorkerRegistry([['id' => 'pi', 'label' => 'Home Assistant', 'note' => ''], ['id' => 'nuc', 'label' => '', 'note' => '']]);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
        parent::tearDown();
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

    /** A worker's report as the worker writes it (storage/workers/{id}/worker-status.json). */
    private function report(string $id, int $ago = 30, array $extra = []): void
    {
        $dir = $this->root . '/storage/workers/' . $id;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/worker-status.json', json_encode($extra + ['tick' => gmdate('Y-m-d\TH:i:s\Z', time() - $ago),
            'id' => $id, 'host' => $id . '-host', 'capabilities' => ['radar', 'wind']]));
    }

    public function testNormalizeKeepsKnownJobsOnceInOrder(): void
    {
        $this->assertSame(['radar', 'wind'], JobPlacement::normalize(['wind', 'x', 'radar', 'wind', 7]));
    }

    public function testASettingsRowThatWasNeverSavedMeansEveryJobOnTheServer(): void
    {
        $this->assertSame(JobPlacement::defaultLists(), $this->settings->jobWorkers());
        $this->assertSame([], $this->settings->externalJobs());
    }

    public function testSwitchingAJobToAWorkerIsStoredAndHandedToBothSides(): void
    {
        $data = $this->decode($this->wind()->placement($this->request('PUT', '/api/v1/settings/wind/placement', $this->admin(), ['external' => true]), $this->response()))['data'];

        $this->assertSame([['id' => 'pi', 'enabled' => true], ['id' => 'server', 'enabled' => false]], $this->settings->jobWorkers()['wind']);
        $this->assertSame(['wind'], $this->settings->externalJobs());
        $this->assertSame($this->settings->jobWorkers(), $this->jobConfig()['job_workers']);
        $this->assertSame(['wind'], $this->jobConfig()['external_jobs']); // the old key, for workers that predate the lists
        $this->assertSame('pi', $data['list'][0]['id']);
        $this->assertNull($data['active']); // nobody has reported yet

        // saving other radar settings keeps the lists in config.json
        $this->radar()->resume($this->request('DELETE', '/api/v1/settings/radar/pause', $this->admin()), $this->response());
        $this->assertSame(['wind'], $this->jobConfig()['external_jobs']);

        $this->radar()->placement($this->request('PUT', '/api/v1/settings/radar/placement', $this->admin(), ['external' => true]), $this->response());
        $this->assertSame(['radar', 'wind'], $this->jobConfig()['external_jobs']);

        $data = $this->decode($this->wind()->placement($this->request('PUT', '/api/v1/settings/wind/placement', $this->admin(), ['external' => false]), $this->response()))['data'];
        $this->assertSame(['radar'], $this->jobConfig()['external_jobs']);
        $this->assertFalse($data['external']);
        $this->assertSame('server', $this->settings->jobWorkers()['wind'][0]['id']);
    }

    public function testPlacementNeedsABooleanAndARegisteredWorker(): void
    {
        try {
            $this->wind()->placement($this->request('PUT', '/api/v1/settings/wind/placement', $this->admin(), ['external' => 'yes']), $this->response());
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('job.placement_invalid', $e->getErrorCode());
        }
        $this->settings->setWorkerRegistry([]);
        $this->expectException(ValidationException::class);
        $this->wind()->placement($this->request('PUT', '/api/v1/settings/wind/placement', $this->admin(), ['external' => true]), $this->response());
    }

    public function testUpdateNowGoesToTheWorkerThatWorksOnTheJob(): void
    {
        $this->settings->setJobWorkers(['wind' => [['id' => 'pi', 'enabled' => true], ['id' => 'server', 'enabled' => true]]] + JobPlacement::defaultLists());
        $this->report('pi');
        $response = $this->wind()->run($this->request('POST', '/api/v1/settings/wind/run', $this->admin()), $this->response());

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($this->decode($response)['data']['external']);
        $this->assertFileExists($this->root . '/storage/wind/run-request');
        $show = $this->decode($this->wind()->show($this->request('GET', '/api/v1/settings/wind', $this->admin()), $this->response()))['data'];
        $this->assertTrue($show['run_requested']);
        $this->assertSame('pi', $show['active']);
        $this->assertSame('pi-host', $show['worker']['host']);

        // back on this server: the pending request is dropped
        $this->wind()->placement($this->request('PUT', '/api/v1/settings/wind/placement', $this->admin(), ['external' => false]), $this->response());
        $this->assertFileDoesNotExist($this->root . '/storage/wind/run-request');
    }

    public function testUpdateNowWithNobodyAliveFallsBackToTheServerOnlyWhenItIsListed(): void
    {
        $this->settings->setJobWorkers(['wind' => [['id' => 'pi', 'enabled' => true], ['id' => 'server', 'enabled' => true]]] + JobPlacement::defaultLists());
        $this->assertTrue(JobPlacement::startsLocally($this->root . '/storage', 'wind', $this->settings->jobWorkers()));

        $this->settings->setJobWorkers(['wind' => [['id' => 'pi', 'enabled' => true], ['id' => 'server', 'enabled' => false]]] + JobPlacement::defaultLists());
        $this->assertFalse(JobPlacement::startsLocally($this->root . '/storage', 'wind', $this->settings->jobWorkers()));
        $response = $this->wind()->run($this->request('POST', '/api/v1/settings/wind/run', $this->admin()), $this->response());
        $this->assertTrue($this->decode($response)['data']['external']);
        $this->assertFileExists($this->root . '/storage/wind/run-request'); // waits until a worker comes back
    }

    public function testWriteJobConfigWithoutListsKeepsTheOnesInTheFile(): void
    {
        $dir = $this->root . '/storage/radar';
        $lists = ['radar' => [['id' => 'pi', 'enabled' => true], ['id' => 'server', 'enabled' => false]]] + JobPlacement::defaultLists();
        RadarConfig::writeJobConfig($dir, RadarConfig::defaults(), null, $lists);
        RadarConfig::writeJobConfig($dir, RadarConfig::defaults());
        $this->assertSame($lists, $this->jobConfig()['job_workers']);
        $this->assertSame(['radar'], $this->jobConfig()['external_jobs']);
    }

    public function testTheWorkersDirectoryExistsOnceAnAdminPageLooked(): void
    {
        // rsync creates only the last path component: storage/workers/ must be there before the first worker connects
        $this->assertDirectoryDoesNotExist($this->root . '/storage/workers');
        $this->decode($this->wind()->show($this->request('GET', '/api/v1/settings/wind', $this->admin()), $this->response()));
        $this->assertDirectoryExists($this->root . '/storage/workers');
    }

    public function testWhatTheWorkersReportAndWhenTheyCountAsAway(): void
    {
        $storage = $this->root . '/storage';
        $this->assertSame([], JobPlacement::workers($storage));
        $this->report('pi', 60);
        $this->report('nuc', JobPlacement::WORKER_STALE_SECONDS + 60);
        mkdir($storage . '/workers/Not A Name', 0777, true); // not a worker name: ignored
        $workers = JobPlacement::workers($storage);
        $this->assertSame(['nuc', 'pi'], array_keys($workers));
        $this->assertFalse($workers['pi']['status']['stale']);
        $this->assertTrue($workers['nuc']['status']['stale']);

        // busy with a long job: not "away" before WORKER_RUNNING_STALE_SECONDS
        $this->report('nuc', JobPlacement::WORKER_STALE_SECONDS + 60, ['running' => ['job' => 'wind']]);
        $this->assertFalse(JobPlacement::workers($storage)['nuc']['status']['stale']);
        $this->report('nuc', JobPlacement::WORKER_RUNNING_STALE_SECONDS + 60, ['running' => ['job' => 'wind']]);
        $this->assertTrue(JobPlacement::workers($storage)['nuc']['status']['stale']);
    }

    public function testANameConflictMarkerCountsOnlyWhileItIsFreshAndResetFreesTheName(): void
    {
        $storage = $this->root . '/storage';
        $this->report('pi');
        file_put_contents($storage . '/workers/pi/conflict.json', json_encode(['tick' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'host' => 'thief']));
        $this->assertSame('thief', JobPlacement::workers($storage)['pi']['conflict']['host']);
        // informational: the one that was there first goes on working
        $this->settings->setJobWorkers(['wind' => [['id' => 'pi', 'enabled' => true]]] + JobPlacement::defaultLists());
        $this->assertSame('pi', JobPlacement::activeFor($storage, 'wind', $this->settings->jobWorkers()));

        file_put_contents($storage . '/workers/pi/conflict.json', json_encode(['tick' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600), 'host' => 'thief']));
        $this->assertNull(JobPlacement::workers($storage)['pi']['conflict']);

        file_put_contents($storage . '/workers/pi/conflict.json', json_encode(['tick' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'host' => 'thief']));
        JobPlacement::resetWorker($storage, 'pi');
        $this->assertNull(JobPlacement::workers($storage)['pi']['status']);
        $this->assertNull(JobPlacement::workers($storage)['pi']['conflict']);
        JobPlacement::resetWorker($storage, '../etc'); // never leaves storage/workers/
    }

    public function testTheServersCronCountsThroughItsHeartbeatFile(): void
    {
        $storage = $this->root . '/storage';
        $this->settings->setJobWorkers(['wind' => [['id' => 'pi', 'enabled' => true], ['id' => 'server', 'enabled' => true]]] + JobPlacement::defaultLists());
        $this->assertNull(JobPlacement::serverTick($storage));
        $this->assertNull(JobPlacement::activeFor($storage, 'wind', $this->settings->jobWorkers()));

        file_put_contents($storage . '/jobs-heartbeat.json', json_encode(['tick' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)]));
        $this->assertSame('server', JobPlacement::activeFor($storage, 'wind', $this->settings->jobWorkers()));
        $this->report('pi');
        $this->assertSame('pi', JobPlacement::activeFor($storage, 'wind', $this->settings->jobWorkers()));
    }
}
