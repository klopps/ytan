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

    public function testParseArgsTakesTheUserOut(): void
    {
        $this->assertSame([['FR', '--all'], 'anna'], OsmImportCliRun::parseArgs(['FR', '--user=anna', '--all']));
        $this->assertSame([['FR'], null], OsmImportCliRun::parseArgs(['FR']));
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
