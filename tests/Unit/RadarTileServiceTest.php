<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\RadarTileService;

final class RadarTileServiceTest extends TestCase
{
    private const FRAME = 'o202610170430';

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

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }
}
