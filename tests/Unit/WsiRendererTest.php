<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\WsiController;
use Ytan\Service\WsiRenderer;
use Ytan\Tests\RemovesDirectories;

/**
 * Wind shelter indicator SVGs: the code is validated before it becomes part
 * of a file name (the PESR original allowed path traversal), one arc per run
 * of equal sectors, cached on disk.
 */
final class WsiRendererTest extends TestCase
{
    use RemovesDirectories;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-wsi-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    /** @return array<string, array{string}> */
    public static function invalidCodes(): array
    {
        return [
            'too short' => ['000011122221110'],
            'too long' => ['00001112222111000'],
            'other digit' => ['0000111222211130'],
            'path traversal' => ['../../../etc/pass'],
            'empty' => [''],
        ];
    }

    /** @dataProvider invalidCodes */
    public function testInvalidCodesAreRefusedWithoutTouchingTheDisk(string $code): void
    {
        try {
            (new WsiRenderer($this->dir))->render($code);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException) {
            $this->assertDirectoryDoesNotExist($this->dir);
        }
    }

    public function testDrawsOneArcPerRunAndShadesBySheltering(): void
    {
        // north half partly sheltered (1), a fully sheltered run (2), the rest open
        $svg = (new WsiRenderer($this->dir))->render('1111222200000000');

        $this->assertStringStartsWith('<', ltrim($svg));
        $this->assertNotFalse(simplexml_load_string($svg), 'valid XML');
        $this->assertSame(2, substr_count($svg, '<path'), 'one arc for the 1-run, one for the 2-run');
        $this->assertStringContainsString('#ccc', $svg);
        $this->assertStringContainsString('#777', $svg);
        $this->assertSame(2, substr_count($svg, '<line'), 'the cross');
        $this->assertSame(1, substr_count($svg, '<polyline'), 'the N');
    }

    public function testAnOpenShelterHasNoArcs(): void
    {
        $svg = (new WsiRenderer($this->dir))->render('0000000000000000');

        $this->assertSame(0, substr_count($svg, '<path'));
    }

    public function testResultIsCachedOnDisk(): void
    {
        $renderer = new WsiRenderer($this->dir);
        $first = $renderer->render('2222222222222222');
        $file = $this->dir . '/wsi_2222222222222222.svg';

        $this->assertFileExists($file);
        file_put_contents($file, '<svg>cached</svg>');
        $this->assertSame('<svg>cached</svg>', $renderer->render('2222222222222222'));
        $this->assertNotSame($first, '<svg>cached</svg>');
    }

    public function testControllerStripsTheSvgSuffixAndCachesForAYear(): void
    {
        $controller = new WsiController(new WsiRenderer($this->dir));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/wsi/0000111222211100.svg');

        $response = $controller->show($request, (new ResponseFactory())->createResponse(), ['code' => '0000111222211100.svg']);

        $this->assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('immutable', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('<svg', (string) $response->getBody());
    }
}
