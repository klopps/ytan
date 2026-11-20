<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\WorkerSettingsController;
use Ytan\Service\JobPlacement;

/** The admin page /admin/workers: registry with unique names, the priority list per job, who works now. */
final class WorkerSettingsControllerTest extends ControllerTestCase
{
    use \Ytan\Tests\RemovesDirectories;

    private string $storage;
    private SettingsRepository $settings;
    private WorkerSettingsController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('INSERT INTO app_settings (id) VALUES (1)');
        $this->storage = sys_get_temp_dir() . '/ytan-workers-' . uniqid() . '/storage';
        mkdir($this->storage . '/radar', 0777, true);
        $this->settings = new SettingsRepository($this->pdo);
        $this->controller = new WorkerSettingsController($this->settings, $this->storage);
    }

    protected function tearDown(): void
    {
        $this->rmrf(dirname($this->storage));
        parent::tearDown();
    }


    private function admin(): array
    {
        return $this->authPayload($this->createUser(), ['is_admin' => true]);
    }

    private function put(array $body): array
    {
        return $this->decode($this->controller->update($this->request('PUT', '/api/v1/settings/workers', $this->admin(), $body), $this->response()))['data'];
    }

    private function show(): array
    {
        return $this->decode($this->controller->show($this->request('GET', '/api/v1/settings/workers', $this->admin()), $this->response()))['data'];
    }

    private function report(string $id, int $ago = 30, array $extra = []): void
    {
        $dir = $this->storage . '/workers/' . $id;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/worker-status.json', json_encode($extra + ['tick' => gmdate('Y-m-d\TH:i:s\Z', time() - $ago),
            'id' => $id, 'host' => $id . '-host', 'capabilities' => ['radar', 'wind']]));
    }

    private function jobConfig(): array
    {
        return json_decode((string) file_get_contents($this->storage . '/radar/config.json'), true);
    }

    public function testOnlyAnAdminMaySeeOrChangeTheWorkers(): void
    {
        $plain = $this->authPayload($this->createUser());
        foreach ([fn () => $this->controller->show($this->request('GET', '/x', $plain), $this->response()),
                  fn () => $this->controller->update($this->request('PUT', '/x', $plain, ['workers' => []]), $this->response()),
                  fn () => $this->controller->reset($this->request('POST', '/x', $plain), $this->response(), ['id' => 'pi'])] as $call) {
            try {
                $call();
                $this->fail('expected a ForbiddenException');
            } catch (ForbiddenException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(UnauthorizedException::class);
        $this->controller->show($this->request('GET', '/x'), $this->response());
    }

    public function testShowBeforeAnythingWasSavedHasEveryJobOnTheServer(): void
    {
        $data = $this->show();
        $this->assertSame([], $data['workers']);
        $this->assertSame(JobPlacement::defaultLists(), $data['job_workers']);
        $this->assertSame(['radar', 'wind'], $data['jobs']);
        $this->assertSame(['radar' => null, 'wind' => null], $data['active']); // the server's cron has not ticked
        $this->assertFalse($data['server']['alive']);
        $this->assertDirectoryExists($this->storage . '/workers');
    }

    public function testSavingRegistersWorkersStoresTheListsAndWritesTheJobConfig(): void
    {
        $data = $this->put([
            'workers' => [['id' => 'pi', 'label' => 'Home Assistant', 'note' => '192.168.178.11'], ['id' => 'nuc', 'label' => 'ytanworker']],
            'job_workers' => ['wind' => [['id' => 'nuc', 'enabled' => true], ['id' => 'pi', 'enabled' => true], ['id' => 'server', 'enabled' => false]]],
        ]);

        $this->assertSame(['pi', 'nuc'], array_column($data['workers'], 'id'));
        $this->assertSame(['nuc', 'pi', 'server'], array_column($data['job_workers']['wind'], 'id'));
        $this->assertSame([['id' => 'server', 'enabled' => true]], $data['job_workers']['radar']);
        $this->assertSame($data['job_workers'], $this->jobConfig()['job_workers']);
        $this->assertSame(['wind'], $this->jobConfig()['external_jobs']);
        $this->assertSame($data['job_workers'], $this->settings->jobWorkers());
    }

    public function testWhoWorksNowFollowsTheHeartbeats(): void
    {
        $this->put([
            'workers' => [['id' => 'pi'], ['id' => 'nuc']],
            'job_workers' => ['wind' => [['id' => 'nuc', 'enabled' => true], ['id' => 'pi', 'enabled' => true]]],
        ]);
        $this->report('pi');
        $this->assertSame('pi', $this->show()['active']['wind']); // nuc has not reported
        $this->report('nuc');
        $data = $this->show();
        $this->assertSame('nuc', $data['active']['wind']);
        $this->assertTrue($data['reports']['nuc']['registered']);
        $this->assertSame('nuc-host', $data['reports']['nuc']['status']['host']);

        $this->report('stranger');
        $this->assertFalse($this->show()['reports']['stranger']['registered']);
    }

    public function testInvalidInputIsRefusedAndNothingIsStored(): void
    {
        $bad = [
            'name taken' => [['workers' => [['id' => 'pi'], ['id' => 'pi']]], 'worker.name_taken'],
            'invalid name' => [['workers' => [['id' => 'Home Assistant']]], 'worker.invalid_name'],
            'server is reserved' => [['workers' => [['id' => 'server']]], 'worker.invalid_name'],
            'no workers list' => [[], 'worker.registry_invalid'],
            'unregistered worker in a list' => [['workers' => [['id' => 'pi']], 'job_workers' => ['wind' => [['id' => 'ghost', 'enabled' => true]]]], 'worker.unknown'],
        ];
        foreach ($bad as $name => [$body, $code]) {
            try {
                $this->put($body);
                $this->fail($name . ': expected a ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame($code, $e->getErrorCode(), $name);
            }
        }
        $this->assertSame([], $this->settings->workerRegistry());
        $this->assertFileDoesNotExist($this->storage . '/radar/config.json');
    }

    public function testRemovingAWorkerAndItsListEntryInOneSave(): void
    {
        $this->put(['workers' => [['id' => 'pi']], 'job_workers' => ['wind' => [['id' => 'pi', 'enabled' => true]]]]);
        $data = $this->put(['workers' => [], 'job_workers' => ['wind' => [['id' => 'server', 'enabled' => true]]]]);
        $this->assertSame([], $data['workers']);
        $this->assertSame([['id' => 'server', 'enabled' => true]], $data['job_workers']['wind']);
    }

    public function testAPendingRequestIsDroppedWhenTheServerGetsTheJobBack(): void
    {
        $this->put(['workers' => [['id' => 'pi']], 'job_workers' => ['wind' => [['id' => 'pi', 'enabled' => true]]]]);
        JobPlacement::requestRun($this->storage, 'wind');
        $this->put(['workers' => [['id' => 'pi']], 'job_workers' => ['wind' => [['id' => 'pi', 'enabled' => true]]]]);
        $this->assertFileExists($this->storage . '/wind/run-request'); // still a worker's job

        $this->put(['workers' => [['id' => 'pi']], 'job_workers' => ['wind' => [['id' => 'server', 'enabled' => true], ['id' => 'pi', 'enabled' => true]]]]);
        $this->assertFileDoesNotExist($this->storage . '/wind/run-request');
    }

    public function testResetFreesAName(): void
    {
        $this->report('pi');
        file_put_contents($this->storage . '/workers/pi/conflict.json', json_encode(['tick' => gmdate('Y-m-d\TH:i:s\Z'), 'host' => 'thief']));
        $this->assertNotNull($this->show()['reports']['pi']['conflict']);

        $data = $this->decode($this->controller->reset($this->request('POST', '/x', $this->admin()), $this->response(), ['id' => 'pi']))['data'];
        $this->assertNull($data['reports']['pi']['status']);
        $this->assertNull($data['reports']['pi']['conflict']);

        foreach (['ghost', '../etc', 'server'] as $id) {
            try {
                $this->controller->reset($this->request('POST', '/x', $this->admin()), $this->response(), ['id' => $id]);
                $this->fail($id . ': expected a NotFoundException');
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
