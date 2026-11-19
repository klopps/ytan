<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\RadarTileService;

final class RadarTileServiceTest extends TestCase
{
    use \Ytan\Tests\RemovesDirectories;

    private const FRAME = 'o202610170430';
    /** the job's class colours (tools/radar/ytanradar/palette.py), lightest first */
    private const LEGEND = ['9fd4ff', '5aaaf2', '2a6fdb', '1c9e57', '8fcb2e', 'f2d21b', 'f58b0f', 'e0261d', 'b1168a'];

    private string $dir;
    private RadarTileService $service;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-radar-test-' . uniqid();
        mkdir($this->dir . '/frames/' . self::FRAME . '/8/137', 0775, true);
        file_put_contents($this->dir . '/manifest.json', json_encode([
            'version' => 1,
            'zoom' => ['min' => 5, 'max' => 8],
            'frames' => [['id' => self::FRAME, 'type' => 'observation']],
            'legend' => array_map(fn (string $c) => ['mm_h' => 1, 'color' => '#' . $c], self::LEGEND),
        ]));
        // z8 tile 137/80: transparent except the top-left 2x2 pixels (palette index 3)
        $img = imagecreate(256, 256);
        imagecolortransparent($img, imagecolorallocate($img, 0, 0, 0));
        imagecolorallocate($img, 1, 1, 1);
        imagecolorallocate($img, 2, 2, 2);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefilledrectangle($img, 0, 0, 1, 1, $red);
        imagepng($img, $this->dir . '/frames/' . self::FRAME . '/8/137/80.png');
        $this->service = new RadarTileService($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    public function testManifestIsReturnedAsArray(): void
    {
        $this->assertSame(8, $this->service->manifest()['zoom']['max']);
    }

    public function testControllerServesTheManifestBrieflyCached(): void
    {
        $response = (new \Ytan\Http\Controllers\RadarController($this->service))->manifest(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/v1/radar/manifest'),
            (new \Slim\Psr7\Factory\ResponseFactory())->createResponse()
        );

        $this->assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(self::FRAME, json_decode((string) $response->getBody(), true)['data']['frames'][0]['id']);
    }

    public function testControllerServesATileImmutable(): void
    {
        $response = (new \Ytan\Http\Controllers\RadarController($this->service))->tile(
            (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/v1/radar/tiles/x'),
            (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(),
            ['frame' => self::FRAME, 'z' => '8', 'x' => '137', 'y' => '80']
        );

        $this->assertSame('image/png', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('immutable', $response->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith("\x89PNG", (string) $response->getBody());
    }

    public function testMissingManifestIs404(): void
    {
        unlink($this->dir . '/manifest.json');
        $this->expectException(NotFoundException::class);
        $this->service->manifest();
    }

    /**
     * @dataProvider invalidFrames
     */
    public function testFrameIdIsValidatedBeforeAnyPathIsBuilt(string $frame): void
    {
        $this->expectException(ValidationException::class);
        $this->service->tile($frame, 8, 137, 80);
    }

    public static function invalidFrames(): array
    {
        return [
            'traversal' => ['../../../.env'],
            'traversal after id' => ['o202610170430/../../x'],
            'unknown prefix' => ['x202610170430'],
            'short' => ['o2026101704'],
            'forecast without lead' => ['f202610170430'],
        ];
    }

    public function testTileCoordinatesOutsideTheWorldAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->tile(self::FRAME, 8, 256, 80);
    }

    public function testUnknownFrameIs404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->tile('f202610170430-030', 8, 137, 80);
    }

    public function testNativeTileIsServedAsIs(): void
    {
        $this->assertSame(
            file_get_contents($this->dir . '/frames/' . self::FRAME . '/8/137/80.png'),
            $this->service->tile(self::FRAME, 8, 137, 80)
        );
    }

    public function testMissingNativeAndTooLowZoomGiveATransparentTile(): void
    {
        foreach ([[8, 136, 80], [4, 8, 5]] as [$z, $x, $y]) {
            $img = imagecreatefromstring($this->service->tile(self::FRAME, $z, $x, $y));
            $this->assertSame(256, imagesx($img));
            $this->assertSame(imagecolortransparent($img), imagecolorat($img, 128, 128));
        }
    }

    public function testOverzoomEnlargesTheMatchingPartOfTheParentAndCachesIt(): void
    {
        // z9 tile 274/160 is the top-left quarter of z8 137/80: its 2x2 red
        // pixels become 4x4 there
        $png = $this->service->tile(self::FRAME, 9, 274, 160);
        $img = imagecreatefromstring($png);
        $this->assertSame([255, 0, 0], $this->rgb($img, 3, 3));
        $this->assertSame(imagecolortransparent($img), imagecolorat($img, 4, 4));
        $this->assertFileExists($this->dir . '/frames/' . self::FRAME . '/9/274/160.png');

        // z16 (shift 8): one parent pixel fills the whole tile
        $img = imagecreatefromstring($this->service->tile(self::FRAME, 16, 137 << 8, 80 << 8));
        $this->assertSame([255, 0, 0], $this->rgb($img, 200, 200));
    }

    /**
     * A z8 tile like the job writes it (PIL): index 0 transparent, index i =
     * legend class i. Written as raw PNG chunks - GD's imagepng() renumbers
     * the palette (found 2026-11-19: it wrote the classes in reverse order).
     *
     * @param array<int, array<int, int>> $pixels [y][x] => palette index
     * @param list<string>|null $colours palette entries 1.. (default the legend)
     */
    private function jobTile(int $x, array $pixels, ?array $colours = null): void
    {
        $chunk = static fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $palette = "\0\0\0";
        foreach ($colours ?? self::LEGEND as $hex) {
            $palette .= hex2bin($hex);
        }
        $raw = '';
        for ($row = 0; $row < 256; $row++) {
            $raw .= "\0"; // filter: none
            for ($col = 0; $col < 256; $col++) {
                $raw .= chr($pixels[$row][$col] ?? 0);
            }
        }
        $png = "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', 256, 256, 8, 3, 0, 0, 0))
            . $chunk('PLTE', $palette)
            . $chunk('tRNS', "\0")
            . $chunk('IDAT', (string) gzcompress($raw))
            . $chunk('IEND', '');
        @mkdir($this->dir . '/frames/' . self::FRAME . '/8/' . $x, 0775, true);
        file_put_contents($this->dir . '/frames/' . self::FRAME . '/8/' . $x . '/80.png', $png);
    }

    /**
     * Rain class per pixel, from its COLOUR (0 = transparent, -1 = a colour not
     * in the legend) - the served PNG is written by GD, which renumbers the palette.
     *
     * @return list<list<int>>
     */
    private function classes(string $png): array
    {
        $img = imagecreatefromstring($png);
        $transparent = imagecolortransparent($img);
        $byColour = array_flip(self::LEGEND);
        $rows = [];
        for ($y = 0; $y < 256; $y++) {
            for ($x = 0; $x < 256; $x++) {
                $index = imagecolorat($img, $x, $y);
                if ($index === $transparent) {
                    $rows[$y][$x] = 0;
                    continue;
                }
                $c = imagecolorsforindex($img, $index);
                $rows[$y][$x] = isset($byColour[sprintf('%02x%02x%02x', $c['red'], $c['green'], $c['blue'])])
                    ? $byColour[sprintf('%02x%02x%02x', $c['red'], $c['green'], $c['blue'])] + 1 : -1;
            }
        }

        return $rows;
    }

    public function testOverzoomOfJobTilesIsSmoothInLegendColoursAndWithoutSeams(): void
    {
        // a 3x3 cloudburst (class 9) in z8 tile 138/80 around pixel (103, 103)
        $pixels = [];
        for ($row = 102; $row <= 104; $row++) {
            $pixels[$row] = [102 => 9, 103 => 9, 104 => 9];
        }
        $this->jobTile(138, $pixels);

        // z13 = shift 5: 8 source pixels per tile; pixel 103 lies in sub-square 12
        $left = $this->classes($this->service->tile(self::FRAME, 13, (138 << 5) + 12, (80 << 5) + 12));
        $right = $this->classes($this->service->tile(self::FRAME, 13, (138 << 5) + 13, (80 << 5) + 12));

        $used = array_unique(array_merge(...$left));
        sort($used);
        $this->assertSame(range(0, 9), $used, 'every class between none and 9 appears (a gradient, no 32 px blocks) and no colour outside the legend (-1)');
        // source pixel 103 = output pixels 224..255 of sub-square 12 (96..103), its centre at 240
        $this->assertSame(9, $left[240][240], 'the centre of source pixel 103 keeps its class');
        // the borders of neighbouring tiles join: the class changes by at most one step
        for ($y = 0; $y < 256; $y += 8) {
            $this->assertLessThanOrEqual(1, abs($left[$y][255] - $right[$y][0]), "row $y");
        }
    }

    public function testATilePaletteThatIsNotTheLegendOrderIsEnlargedPixelExact(): void
    {
        // the legend's colours in reverse order: index 1 is the heaviest class - mixing
        // indices would give wrong colours, so only "none" and that one colour appear
        $pixels = [];
        for ($row = 102; $row <= 104; $row++) {
            $pixels[$row] = [102 => 1, 103 => 1, 104 => 1];
        }
        $this->jobTile(139, $pixels, array_reverse(self::LEGEND));

        $used = array_unique(array_merge(...$this->classes($this->service->tile(self::FRAME, 13, (139 << 5) + 12, (80 << 5) + 12))));
        sort($used);

        $this->assertSame([0, 9], $used);
    }

    public function testOverzoomOfAnEmptyPartIsTransparentAndNotCached(): void
    {
        $img = imagecreatefromstring($this->service->tile(self::FRAME, 9, 275, 161));
        $this->assertSame(imagecolortransparent($img), imagecolorat($img, 0, 0));
        $this->assertFileDoesNotExist($this->dir . '/frames/' . self::FRAME . '/9/275/161.png');
    }

    /**
     * @return array{int, int, int}
     */
    private function rgb(\GdImage $img, int $x, int $y): array
    {
        $c = imagecolorsforindex($img, imagecolorat($img, $x, $y));

        return [$c['red'], $c['green'], $c['blue']];
    }

}
