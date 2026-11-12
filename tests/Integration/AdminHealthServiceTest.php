<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Http\Controllers\AdminHealthController;
use Ytan\Service\AdminHealthService;

/**
 * The /admin dashboard's "Probleme" card (AdminHealthService): every check
 * against a temporary storage tree with a fixed "now".
 */
final class AdminHealthServiceTest extends ControllerTestCase
{
    private const NOW = 1_800_000_000;

    private string $root;
    private SettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('INSERT INTO app_settings (id) VALUES (1)');
        $this->root = sys_get_temp_dir() . '/ytan-health-' . uniqid();
        foreach (['logs', 'radar', 'wind'] as $dir) {
            mkdir($this->root . '/storage/' . $dir, 0777, true);
        }
        $this->settings = new SettingsRepository($this->pdo);
        $this->healthy();
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

    private static function iso(int $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    private function write(string $relative, array|string $content, ?int $mtime = null): void
    {
        $file = $this->root . '/storage/' . $relative;
        file_put_contents($file, is_array($content) ? json_encode($content) : $content);
        touch($file, $mtime ?? self::NOW);
    }

    /** Everything fresh: cron just ran, radar and wind current, cleanup ran today. */
    private function healthy(): void
    {
        $this->write('jobs-heartbeat.json', ['tick' => self::iso(self::NOW - 60)]);
        $this->write('jobs-status.json', [
            'cleanup-weather' => ['last_run' => ['start' => self::iso(self::NOW - 3600), 'outcome' => 'processed', 'exit_code' => 0]],
            'cleanup-caches' => ['last_run' => ['start' => self::iso(self::NOW - 3600), 'outcome' => 'processed', 'exit_code' => 0]],
        ]);
        $this->write('logs/web-error.log', '', self::NOW - 3600);
        $this->write('radar/status.json', ['last_run' => ['start' => self::iso(self::NOW - 120), 'outcome' => 'processed']]);
        $this->write('radar/manifest.json', ['latest_observation' => self::iso(self::NOW - 600)]);
        $this->write('wind/status.json', ['last_run' => ['start' => self::iso(self::NOW - 300), 'outcome' => 'unchanged']]);
        $this->write('wind/manifest.json', ['generated' => self::iso(self::NOW - 3600), 'hours' => [self::iso(self::NOW + 40 * 3600)]]);
    }

    /** With an existing, empty web server error log (none at all would be an issue of its own). */
    private function service(): AdminHealthService
    {
        return new AdminHealthService($this->root, $this->settings, $this->pdo, [$this->root . '/storage/logs/web-error.log']);
    }

    /** @return array<string, string> code => severity */
    private function issues(): array
    {
        $issues = ($this->service())->issues(self::NOW);

        return array_column($issues, 'severity', 'code');
    }

    public function testNothingToReportWhenEverythingIsFresh(): void
    {
        $this->assertSame([], $this->issues());
    }

    public function testStaleRadarDataAndAFailedRun(): void
    {
        $this->write('radar/manifest.json', ['latest_observation' => self::iso(self::NOW - 26 * 3600)]);
        $this->write('radar/status.json', ['last_run' => ['start' => self::iso(self::NOW - 60), 'end' => self::iso(self::NOW - 30),
            'outcome' => 'failed', 'error' => 'boom']]);

        $this->assertSame(['radar.failed' => 'error', 'radar.stale' => 'error'], $this->issues());
    }

    public function testRadarDataJustAWhileOldIsAWarning(): void
    {
        $this->write('radar/manifest.json', ['latest_observation' => self::iso(self::NOW - 45 * 60)]);
        $this->assertSame(['radar.stale' => 'warning'], $this->issues());
    }

    public function testAPausedRadarIsOnlyMentioned(): void
    {
        $this->settings->setRadarPause(gmdate('Y-m-d H:i:s', self::NOW + 900), false);
        $this->write('radar/manifest.json', ['latest_observation' => self::iso(self::NOW - 26 * 3600)]);
        $this->assertSame(['radar.paused' => 'info'], $this->issues());
    }

    public function testCronAndWindJobsThatStoppedRunning(): void
    {
        $this->write('jobs-heartbeat.json', ['tick' => self::iso(self::NOW - 3600)]);
        $this->write('wind/status.json', ['last_run' => ['start' => self::iso(self::NOW - 2 * 3600), 'outcome' => 'unchanged']]);
        $this->write('wind/manifest.json', ['generated' => self::iso(self::NOW - 30 * 3600), 'hours' => [self::iso(self::NOW - 3600)]]);

        $this->assertSame(['cron.silent' => 'error', 'wind.silent' => 'error', 'wind.stale' => 'error'], $this->issues());
    }

    public function testASwitchedOffWindLayerIsNoProblem(): void
    {
        $this->settings->setWindSettings(['enabled' => false] + $this->settings->windSettings());
        unlink($this->root . '/storage/wind/manifest.json');
        $this->assertSame([], $this->issues());
    }

    public function testASilentWorkerAndItsFailedJob(): void
    {
        $this->settings->setExternalJobs(['wind']);
        $this->assertSame(['worker.never' => 'error'], $this->issues());

        $this->write('worker-status.json', ['tick' => self::iso(self::NOW - 3600), 'host' => 'pi',
            'jobs' => ['wind' => ['ran' => true, 'code' => 1, 'published' => false]]]);
        $this->assertSame(['worker.silent' => 'error', 'worker.job_failed' => 'error'], $this->issues());
    }

    public function testAFailedOrOverdueDailyCleanup(): void
    {
        $this->write('jobs-status.json', [
            'cleanup-weather' => ['last_run' => ['start' => self::iso(self::NOW - 3600), 'outcome' => 'failed', 'exit_code' => 255]],
            'cleanup-caches' => ['last_run' => ['start' => self::iso(self::NOW - 3 * 86400), 'outcome' => 'processed', 'exit_code' => 0]],
        ]);
        $issues = $this->service()->issues(self::NOW);
        $this->assertSame(['cleanup.failed' => 'error', 'cleanup.silent' => 'warning'], array_column($issues, 'severity', 'code'));
        $this->assertSame(['bin/cleanup-weather-data.php', 255], [$issues[0]['vars']['script'], $issues[0]['vars']['code']]);
    }

    public function testAFailedOsmImportAndOldWeatherRowsAndAMissingCleanup(): void
    {
        file_put_contents($this->root . '/storage/osm-import-status.json', json_encode(['tool' => 'campsite', 'country' => 'FR',
            'state' => 'done', 'failed_tiles' => [3], 'updated' => self::iso(time() - 60)]));
        $this->pdo->prepare('INSERT INTO weather_forecast (lat, lng, region_code, source, model, generated_at, fetched_at, payload) VALUES (1, 2, ?, ?, ?, ?, ?, ?)')
            ->execute(['de', 'x', 'x', gmdate('Y-m-d H:i:s', time() - 20 * 86400), gmdate('Y-m-d H:i:s', time() - 20 * 86400), '{}']);
        unlink($this->root . '/storage/jobs-status.json');

        $issues = ($this->service())->issues(time());
        $codes = array_column($issues, 'page', 'code');
        $this->assertSame('campsite-import', $codes['osm.failed_tiles']);
        $this->assertArrayHasKey('cleanup.never', $codes);
        $this->assertCount(2, array_filter($issues, fn ($i) => $i['code'] === 'cleanup.never')); // weather and caches
        // (time() instead of NOW: the OSM status and the weather row are dated from the real clock)
        $this->assertArrayHasKey('weather.old_rows', $codes);
    }

    public function testErrorLogLinesOfPhpApacheAndNginx(): void
    {
        $php = AdminHealthService::parseErrorLine('[12-Nov-2026 19:51:00 UTC] PHP Fatal error:  Uncaught Error: boom in /x.php:3');
        $this->assertSame([strtotime('2026-11-12 19:51:00 UTC'), 'error'], [$php[0], $php[1]]);
        $this->assertSame('PHP Fatal error:  Uncaught Error: boom in /x.php:3', $php[2]);

        $apache = AdminHealthService::parseErrorLine('[Wed Nov 11 10:42:50.939098 2026] [php:warn] [pid 1:tid 2] [client 127.0.0.1:6] PHP Warning:  Undefined variable $x in /y.php on line 9');
        $this->assertSame(['warning', 'PHP Warning:  Undefined variable $x in /y.php on line 9'], [$apache[1], $apache[2]]);
        $this->assertSame('error', AdminHealthService::parseErrorLine('[Wed Nov 11 10:42:50 2026] [core:error] [pid 1] AH00037: Symbolic link not allowed')[1]);
        $this->assertSame('error', AdminHealthService::parseErrorLine('2026/11/12 19:51:00 [crit] 12#12: *1 connect() failed')[1]);

        // deprecations, notices and plain error_log() lines (CSP reports) are no problem; trace lines have no time
        $this->assertNull(AdminHealthService::parseErrorLine('[Wed Nov 11 10:42:50.9 2026] [php:notice] [pid 1] PHP Deprecated:  curl_close()')[1]);
        $this->assertNull(AdminHealthService::parseErrorLine('[12-Nov-2026 19:51:00 UTC] CSP violation: {...}')[1]);
        $this->assertNull(AdminHealthService::parseErrorLine('#0 /x.php(3): f()'));
    }

    public function testErrorsOfTheLastDayInTheErrorLogAreReported(): void
    {
        $log = $this->root . '/storage/web-error.log';
        $old = gmdate('d-M-Y H:i:s', self::NOW - 2 * 86400);
        $recent = gmdate('d-M-Y H:i:s', self::NOW - 3600);
        file_put_contents($log, "[$old UTC] PHP Fatal error:  too old\n[$recent UTC] PHP Warning:  w1\n[$recent UTC] PHP Fatal error:  Uncaught Error: recent\n#0 trace\n[$recent UTC] PHP Deprecated:  d\n");
        touch($log, self::NOW - 3600);
        $missing = $this->root . '/storage/missing-error.log';

        $issues = (new AdminHealthService($this->root, $this->settings, $this->pdo, [$missing, $log]))->issues(self::NOW);
        $this->assertCount(1, $issues);
        $this->assertSame(['error', 'errorlog.errors'], [$issues[0]['severity'], $issues[0]['code']]);
        $this->assertSame(['web-error.log', 1, 1, 'PHP Fatal error:  Uncaught Error: recent'], [$issues[0]['vars']['file'],
            $issues[0]['vars']['errors'], $issues[0]['vars']['warnings'], $issues[0]['vars']['message']]);

        // no readable error log at all: only a hint
        $this->assertSame(['errorlog.none' => 'info'], array_column((new AdminHealthService($this->root, $this->settings, $this->pdo, [$missing]))->issues(self::NOW), 'severity', 'code'));
    }

    public function testWhichFilesCountAsErrorLogs(): void
    {
        $this->assertSame(['/php.log', 'C:/Apache24/logs/ytan-error.log'], AdminHealthService::errorLogFiles('/php.log', ['C:/Apache24/logs/ytan-error.log', 'C:/Apache24/logs/ytan-access.log']));
        $this->assertSame([], AdminHealthService::errorLogFiles('', []));
    }

    public function testErrorsComeFirstAndOnlyAdminsMayAsk(): void
    {
        $this->settings->setRadarPause(null, true);
        $this->write('jobs-heartbeat.json', ['tick' => self::iso(self::NOW - 3600)]);
        $issues = ($this->service())->issues(self::NOW);
        $this->assertSame(['error', 'info'], array_column($issues, 'severity'));

        $this->expectException(ForbiddenException::class);
        (new AdminHealthController($this->service()))
            ->index($this->request('GET', '/api/v1/admin/health', $this->authPayload($this->createUser(), ['is_admin' => false])), $this->response());
    }
}
