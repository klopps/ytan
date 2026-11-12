<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use RuntimeException;
use Ytan\Exception\ApiException;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\OsmImportJobController;
use Ytan\Service\Log\ImportLog;
use Ytan\Service\Overpass\OsmImportCliRun;
use Ytan\Service\Overpass\OsmImportJob;

/**
 * Loading a country from OSM in the background: the start command, the lock
 * (one import at a time), the status the CLI writes and the pages poll, and
 * what the status says when the process died.
 */
final class OsmImportJobTest extends ControllerTestCase
{
    private string $dir;
    /** @var list<string> */
    private array $spawned = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ytan-osmjob-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/storage/logs/*') ?: [], glob($this->dir . '/storage/*.*') ?: []) as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/storage/logs');
        @rmdir($this->dir . '/storage');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function job(): OsmImportJob
    {
        return new OsmImportJob($this->dir, function (string $command) {
            $this->spawned[] = $command;
        });
    }

    public function testStartRunsTheToolsScriptWithCountryAndUser(): void
    {
        $status = $this->job()->start('shelter', 'no', 'anna');

        $this->assertCount(1, $this->spawned);
        $this->assertStringContainsString('import-shelters.php', $this->spawned[0]);
        $this->assertStringContainsString('osm:NO', $this->spawned[0]);
        $this->assertStringContainsString('--user=anna', $this->spawned[0]);
        $this->assertSame(['shelter', 'NO', 'starting'], [$status['tool'], $status['country'], $status['state']]);
        $this->assertSame('starting', $this->job()->status()['state']);
    }

    public function testLighthouseAreaIsCheckedAndPassedWithTheLabelEncoded(): void
    {
        $controller = new OsmImportJobController($this->job(), fn (string $log) => ImportLog::file($this->dir, $log));
        $admin = $this->authPayload($this->createUser(['username' => 'anna', 'is_admin' => 1]), ['username' => 'anna', 'is_admin' => true]);
        $body = ['tool' => 'lighthouse', 'south' => 54.95, 'west' => 14.6, 'north' => 55.35, 'east' => 15.2, 'label' => 'Øresund'];

        foreach ([['south' => 'x'], ['north' => 70, 'east' => 30]] as $bad) {
            try {
                $controller->start($this->request('POST', '/x', $admin, $bad + $body), $this->response());
                $this->fail('Expected a validation error.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame([], $this->spawned, 'nothing started for a bad or too large area');

        $controller->start($this->request('POST', '/x', $admin, $body), $this->response());
        $this->assertStringContainsString('import-lighthouses-osm.php', $this->spawned[0]);
        $this->assertStringContainsString('--south=54.95', $this->spawned[0]);
        $this->assertStringContainsString('--label64=' . base64_encode('Øresund'), $this->spawned[0]);
        $this->assertSame(['lighthouse', 'Øresund'], [$this->job()->status()['tool'], $this->job()->status()['country']]);
    }

    public function testRejectsUnknownToolsAndCountries(): void
    {
        foreach ([['x', 'FR'], ['campsite', 'France']] as [$tool, $country]) {
            try {
                $this->job()->start($tool, $country, 'anna');
                $this->fail('Expected a validation error.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame([], $this->spawned);
    }

    public function testOneImportAtATimeAndTheStatusOfARun(): void
    {
        $run = new OsmImportCliRun($this->job(), 'campsite', 'anna');
        $this->assertTrue($run->begin());
        $this->assertFalse((new OsmImportCliRun($this->job(), 'club', null))->begin(), 'second run refused');
        try {
            $this->job()->start('campsite', 'FR', 'anna');
            $this->fail('Expected 409.');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $run->country('FR', 20);
        $run->tileDone(0, ['fetched' => 3, 'inserted' => 2, 'updated' => 1, 'skipped' => 0, 'tile' => 0, 'tiles' => 20]);
        $run->retry(1, 1, 3, 30, new RuntimeException('busy'));
        $status = $this->job()->status();
        $this->assertTrue($status['running']);
        $this->assertSame(['retrying', 2, 20, 3], [$status['state'], $status['tile'], $status['tiles'], $status['sums']['fetched']]);
        $this->assertStringContainsString('retry 1/3 in 30 s after: busy', $status['message']);

        $run->tileFailed(1, new RuntimeException('GAVE UP'));
        $run->finish(false, 'failed: FR (tile 2/20)');
        $status = $this->job()->status();
        $this->assertFalse($status['running']);
        $this->assertSame(['failed', [2]], [$status['state'], $status['failed_tiles']]);

        $this->assertTrue((new OsmImportCliRun($this->job(), 'club', null))->begin(), 'lock free again');
    }

    public function testAProcessThatDiedIsReportedAsFailed(): void
    {
        $this->job()->writeStatus(['tool' => 'campsite', 'country' => 'FR', 'state' => 'running', 'tile' => 4, 'tiles' => 20]);
        $status = $this->job()->status();
        $this->assertSame('failed', $status['state']);
        $this->assertStringContainsString('ended unexpectedly', $status['message']);
    }

    public function testParseArgsTakesTheUserAndResumeOut(): void
    {
        $this->assertSame([['FR', '--all'], 'anna', false], OsmImportCliRun::parseArgs(['FR', '--user=anna', '--all']));
        $this->assertSame([['FR'], null, true], OsmImportCliRun::parseArgs(['FR', '--resume']));
    }

    public function testAKilledResumableRunIsInterruptedUntilTheCronJobResumesIt(): void
    {
        $lines = [];
        $run = new OsmImportCliRun($this->job(), 'campsite', 'anna', false, ['FR'], '/x/bin/import-campsites.php', function (string $l) use (&$lines) {
            $lines[] = $l;
        });
        $this->assertTrue($run->begin());
        $run->country('FR', 20);
        $this->assertSame(0, $run->firstTile('FR'));
        for ($tile = 0; $tile < 11; $tile++) {
            $run->tileDone($tile, ['fetched' => 10, 'inserted' => 10, 'updated' => 0, 'skipped' => 0]);
        }
        $raw = $this->job()->readStatus();
        $this->assertSame(['php' => PHP_BINARY, 'script' => '/x/bin/import-campsites.php', 'args' => ['FR'], 'log' => 'campsite-import'], $raw['resume']);
        $this->assertSame(12, $raw['tile']);

        // the process is killed: lock gone, status still "running" (simulated by dropping the run without finish())
        $kill = new \ReflectionMethod($run, 'unlock');
        $kill->invoke($run);
        $status = $this->job()->status();
        $this->assertSame(['interrupted', false], [$status['state'], $status['running']]);

        // tools/ytan_jobs.py starts it again with --resume: it continues at tile 12 and keeps the sums
        $this->job()->writeStatus(['state' => 'starting', 'resumes' => 1, 'started_resume' => date('c')] + $raw);
        $this->assertSame('starting', $this->job()->status()['state'], 'a fresh resume is not reported as failed');
        $resumed = new OsmImportCliRun($this->job(), 'campsite', 'anna', true, ['FR'], '/x/bin/import-campsites.php', function (string $l) use (&$lines) {
            $lines[] = $l;
        });
        $this->assertTrue($resumed->begin());
        $this->assertSame(11, $resumed->firstTile('FR'));
        $this->assertSame(0, $resumed->firstTile('BE'));
        $resumed->country('FR', 20);
        $resumed->tileDone(11, ['fetched' => 5, 'inserted' => 5, 'updated' => 0, 'skipped' => 0]);
        $raw = $this->job()->readStatus();
        $this->assertSame([13, 115, 1, 'running'], [$raw['tile'], $raw['sums']['fetched'], $raw['resumes'], $raw['state']]);
        $this->assertStringContainsString('continues FR at tile 12/20', $lines[0]);
        $resumed->finish(true);
        $this->assertSame('done', $this->job()->status()['state']);
    }

    public function testAfterTooManyResumesADeadRunIsFailed(): void
    {
        $this->job()->writeStatus(['tool' => 'club', 'country' => 'FR', 'state' => 'running', 'tile' => 2, 'tiles' => 3,
            'resumes' => OsmImportJob::MAX_RESUMES, 'resume' => ['php' => 'php', 'script' => 's', 'args' => [], 'log' => 'club-import']]);
        $this->assertSame('failed', $this->job()->status()['state']);
    }

    public function testAFailedTileIsNotLoadedAgainOnResume(): void
    {
        $run = new OsmImportCliRun($this->job(), 'club', null, false, ['FR'], 's');
        $run->begin();
        $run->country('FR', 3);
        $run->tileFailed(0, new RuntimeException('GAVE UP'));
        $this->assertSame([2, [1]], [$this->job()->readStatus()['tile'], $this->job()->readStatus()['failed_tiles']]);
        $run->finish(false);
    }

    public function testControllerStartsLogsAndIsAdminOnly(): void
    {
        $controller = new OsmImportJobController($this->job(), fn (string $log) => ImportLog::file($this->dir, $log));
        $admin = $this->authPayload($this->createUser(['username' => 'anna', 'is_admin' => 1]), ['username' => 'anna', 'is_admin' => true]);

        $response = $controller->start($this->request('POST', '/x', $admin, ['tool' => 'campsite', 'country' => 'FR']), $this->response());
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('starting', $this->decode($controller->status($this->request('GET', '/x', $admin), $this->response()))['data']['state']);
        $lines = file($this->dir . '/storage/logs/campsite-import.log', FILE_IGNORE_NEW_LINES);
        $this->assertStringContainsString('anna: start OpenStreetMap import in the background country=FR - result state=starting', $lines[0]);

        $this->expectException(ForbiddenException::class);
        $controller->start($this->request('POST', '/x', $this->authPayload($this->createUser()), ['tool' => 'campsite', 'country' => 'FR']), $this->response());
    }
}
