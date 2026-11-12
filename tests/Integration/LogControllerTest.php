<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Exception\ForbiddenException;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\LogController;
use Ytan\Service\Log\LogFileService;
use ZipArchive;

/**
 * LogController + LogFileService (/admin/logs): listing, tail/follow reads
 * (offsets, rotation, line-start cut), downloads (one file raw, several as
 * ZIP), admin gate and that only listed files are reachable.
 */
final class LogControllerTest extends ControllerTestCase
{
    private string $dir;
    private string $extra;
    private LogController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ytan-logtest-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/logs', 0777, true);
        file_put_contents($this->dir . '/logs/radar.log', "line 1\nline 2\nline 3\n");
        file_put_contents($this->dir . '/logs/wind.log', "wind\n");
        file_put_contents($this->dir . '/logs/.lock', 'x');
        file_put_contents($this->dir . '/secret.txt', 'not a log');
        $this->extra = $this->dir . '/apache-error.log';
        file_put_contents($this->extra, "apache\n");
        $this->controller = new LogController(new LogFileService(
            $this->dir . '/logs',
            null,
            [$this->extra, $this->dir . '/missing.log'],
        ));
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->dir . '/logs/{,.}*', GLOB_BRACE), ...glob($this->dir . '/*')] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir . '/logs');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function admin(): array
    {
        return $this->authPayload(1, ['is_admin' => true]);
    }

    public function testListsStorageLogsAndExistingExtraFilesOnly(): void
    {
        $result = $this->decode($this->controller->index($this->request('GET', '/api/v1/admin/logs', $this->admin()), $this->response()));

        $this->assertSame(200, $result['status']);
        $this->assertSame(['s:radar.log', 's:wind.log', 'x:0'], array_column($result['data'], 'id'));
        $this->assertSame(21, $result['data'][0]['size']);
        $this->assertSame('storage', $result['data'][0]['source']);
        $this->assertSame('extra', $result['data'][2]['source']);
        $this->assertNotNull($result['data'][0]['modified']);
    }

    public function testTheWorkersUploadedLogsAreListedSeparately(): void
    {
        mkdir($this->dir . '/logs/worker');
        file_put_contents($this->dir . '/logs/worker/wind.log', "from the pi\n");
        try {
            $result = $this->decode($this->controller->index($this->request('GET', '/api/v1/admin/logs', $this->admin()), $this->response()));
            $this->assertSame(['s:radar.log', 's:wind.log', 'w:wind.log', 'x:0'], array_column($result['data'], 'id'));
            $this->assertSame('worker', $result['data'][2]['source']);

            $read = $this->decode($this->controller->read($this->request('GET', '/api/v1/admin/logs/read', $this->admin(), null, ['id' => 'w:wind.log']), $this->response()));
            $this->assertSame("from the pi\n", $read['data']['content']);
        } finally {
            unlink($this->dir . '/logs/worker/wind.log');
            rmdir($this->dir . '/logs/worker');
        }
    }

    public function testNonAdminIsRejected(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->controller->index($this->request('GET', '/api/v1/admin/logs', $this->authPayload(1, ['is_admin' => false])), $this->response());
    }

    public function testUnknownIdIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller->read($this->request('GET', '/api/v1/admin/logs/read', $this->admin(), null, ['id' => 's:../secret.txt']), $this->response());
    }

    public function testReadReturnsWholeSmallFile(): void
    {
        $data = $this->decode($this->controller->read($this->request('GET', '/x', $this->admin(), null, ['id' => 's:radar.log']), $this->response()))['data'];

        $this->assertSame("line 1\nline 2\nline 3\n", $data['content']);
        $this->assertSame(0, $data['from']);
        $this->assertSame(21, $data['to']);
        $this->assertFalse($data['truncated']);
        $this->assertFalse($data['more']);
    }

    public function testTailStartsAtALineBeginning(): void
    {
        $data = $this->decode($this->controller->read($this->request('GET', '/x', $this->admin(), null, ['id' => 's:radar.log', 'bytes' => '10']), $this->response()))['data'];

        // Last 10 bytes are "2\nline 3\n" plus one more - cut to the next line start.
        $this->assertSame("line 3\n", $data['content']);
        $this->assertTrue($data['truncated']);
        $this->assertSame(21, $data['to']);
    }

    public function testFollowReturnsOnlyAppendedTextAndDetectsRotation(): void
    {
        file_put_contents($this->dir . '/logs/radar.log', "line 4\n", FILE_APPEND);
        $data = $this->decode($this->controller->read($this->request('GET', '/x', $this->admin(), null, ['id' => 's:radar.log', 'from' => '21']), $this->response()))['data'];
        $this->assertSame("line 4\n", $data['content']);
        $this->assertSame(28, $data['to']);
        $this->assertFalse($data['reset']);

        file_put_contents($this->dir . '/logs/radar.log', "new\n");
        $data = $this->decode($this->controller->read($this->request('GET', '/x', $this->admin(), null, ['id' => 's:radar.log', 'from' => '28']), $this->response()))['data'];
        $this->assertTrue($data['reset']);
        $this->assertSame("new\n", $data['content']);
        $this->assertSame(4, $data['to']);
    }

    public function testFollowInChunksReportsMore(): void
    {
        $data = $this->decode($this->controller->read($this->request('GET', '/x', $this->admin(), null, ['id' => 's:radar.log', 'from' => '0', 'bytes' => '7']), $this->response()))['data'];

        $this->assertSame("line 1\n", $data['content']);
        $this->assertTrue($data['more']);
    }

    public function testInvalidUtf8IsScrubbed(): void
    {
        file_put_contents($this->dir . '/logs/wind.log', "a\xFFb\n");
        $result = $this->controller->read($this->request('GET', '/x', $this->admin(), null, ['id' => 's:wind.log']), $this->response());

        $this->assertNotFalse(json_decode((string) $result->getBody()));
        $this->assertSame("a?b\n", $this->decode($result)['data']['content']);
    }

    public function testDownloadOneFileAsIs(): void
    {
        $response = $this->controller->download($this->request('GET', '/x', $this->admin(), null, ['ids' => 's:wind.log']), $this->response());

        $this->assertSame("wind\n", (string) $response->getBody());
        $this->assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('filename="wind.log"', $response->getHeaderLine('Content-Disposition'));
    }

    public function testDownloadSeveralAsZipIgnoringUnknownIds(): void
    {
        $response = $this->controller->download($this->request('GET', '/x', $this->admin(), null, ['ids' => 's:radar.log,x:0,s:../secret.txt']), $this->response());

        $this->assertStringContainsString('.zip"', $response->getHeaderLine('Content-Disposition'));
        $zipFile = $this->dir . '/check.zip';
        file_put_contents($zipFile, (string) $response->getBody());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipFile));
        $this->assertSame(2, $zip->numFiles);
        $this->assertSame("line 1\nline 2\nline 3\n", $zip->getFromName('radar.log'));
        $this->assertSame("apache\n", $zip->getFromName('apache-error.log'));
        $zip->close();
    }

    public function testDownloadWithoutValidIdIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller->download($this->request('GET', '/x', $this->admin(), null, ['ids' => 'nope']), $this->response());
    }
}
