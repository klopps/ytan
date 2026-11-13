<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\ValidationException;
use Ytan\Service\ImageMetadataStripper;

final class ImageMetadataStripperTest extends TestCase
{
    private static function gdImage(): \GdImage
    {
        $img = imagecreatetruecolor(40, 20);
        imagefilledrectangle($img, 0, 0, 19, 19, imagecolorallocate($img, 200, 30, 30));
        imagefilledrectangle($img, 20, 0, 39, 19, imagecolorallocate($img, 30, 30, 200));

        return $img;
    }

    private static function jpeg(bool $progressive = false): string
    {
        $img = self::gdImage();
        imageinterlace($img, $progressive);
        ob_start();
        imagejpeg($img, null, 90);

        return (string) ob_get_clean();
    }

    /** APP1 Exif with IFD0 = orientation + a fake GPS IFD pointer, little endian. */
    private static function exifSegment(int $orientation): string
    {
        $tiff = "II\x2A\x00" . pack('V', 8)
            . pack('v', 2)
            . pack('vvV', 0x0112, 3, 1) . pack('v', $orientation) . "\x00\x00"
            . pack('vvV', 0x8825, 4, 1) . pack('V', 38)
            . pack('V', 0)
            . 'GPS 54.3233N 10.1228E secret';
        $payload = "Exif\0\0" . $tiff;

        return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    }

    private static function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    /** Puts $segments right behind SOI and $trailer behind EOI. */
    private static function withMetadata(string $jpeg, string $segments, string $trailer = ''): string
    {
        return substr($jpeg, 0, 2) . $segments . substr($jpeg, 2) . $trailer;
    }

    public function testJpegLosesExifXmpIptcCommentAndTrailingImages(): void
    {
        $clean = self::jpeg();
        $dirty = self::withMetadata(
            $clean,
            self::exifSegment(1)
                . self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>Kiel</x:xmpmeta>")
                . self::segment(0xED, "Photoshop 3.0\0IPTC Kiel")
                . self::segment(0xFE, 'my comment'),
            "\xFF\xD8\xFF\xE1secondimage Exif GPS secret\xFF\xD9",
        );

        $stripped = ImageMetadataStripper::strip($dirty, 'image/jpeg');

        foreach (['Exif', 'secret', 'xmpmeta', 'IPTC', 'my comment', 'secondimage'] as $needle) {
            $this->assertStringNotContainsString($needle, $stripped, $needle);
        }
        $this->assertStringEndsWith("\xFF\xD9", $stripped);
        $this->assertSame([40, 20], array_slice(getimagesizefromstring($stripped), 0, 2));
        $this->assertNotFalse(imagecreatefromstring($stripped));
    }

    public function testJpegKeepsTheOrientationAsMinimalExif(): void
    {
        $stripped = ImageMetadataStripper::strip(self::withMetadata(self::jpeg(), self::exifSegment(6)), 'image/jpeg');

        $this->assertStringContainsString("Exif\0\0", $stripped);
        $this->assertStringNotContainsString('secret', $stripped);
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('exif extension not loaded');
        }
        $this->assertSame(6, exif_read_data('data://image/jpeg;base64,' . base64_encode($stripped))['Orientation'] ?? null);
        $this->assertNotFalse(imagecreatefromstring($stripped));
    }

    public function testJpegKeepsTheEntropyDataOfProgressiveImages(): void
    {
        $clean = self::jpeg(true);
        $stripped = ImageMetadataStripper::strip(self::withMetadata($clean, self::exifSegment(1)), 'image/jpeg');

        $decoded = imagecreatefromstring($stripped);
        $this->assertNotFalse($decoded);
        $this->assertSame(imagecolorat(imagecreatefromstring($clean), 30, 10), imagecolorat($decoded, 30, 10));
    }

    public function testCleanJpegStaysUnchangedApartFromComments(): void
    {
        $clean = ImageMetadataStripper::strip(self::jpeg(), 'image/jpeg');

        $this->assertSame($clean, ImageMetadataStripper::strip($clean, 'image/jpeg'));
    }

    public function testBrokenJpegIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        ImageMetadataStripper::strip("\xFF\xD8\xFF\xE1\x40\x00Exif", 'image/jpeg');
    }

    public function testPngLosesTextAndExifChunks(): void
    {
        ob_start();
        imagepng(self::gdImage());
        $png = (string) ob_get_clean();
        $chunk = static fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $dirty = substr($png, 0, 33) . $chunk('tEXt', "Author\0Secret Person") . $chunk('eXIf', 'MM GPS secret') . substr($png, 33);

        $stripped = ImageMetadataStripper::strip($dirty, 'image/png');

        $this->assertStringNotContainsString('Secret', $stripped);
        $this->assertStringNotContainsString('secret', $stripped);
        $this->assertSame($png, $stripped);
    }

    public function testWebpLosesExifAndXmpChunksAndFlags(): void
    {
        ob_start();
        imagewebp(self::gdImage(), null, 80);
        $webp = (string) ob_get_clean();
        $bitstream = substr($webp, 12);
        $vp8x = 'VP8X' . pack('V', 10) . chr(0x08 | 0x04) . "\0\0\0" . "\x27\0\0" . "\x13\0\0";
        $exif = 'EXIF' . pack('V', 15) . 'MM GPS secret!!' . "\0";
        $xmp = 'XMP ' . pack('V', 6) . 'Kiel!!';
        $body = $vp8x . $bitstream . $exif . $xmp;
        $dirty = 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;

        $stripped = ImageMetadataStripper::strip($dirty, 'image/webp');

        $this->assertStringNotContainsString('secret', $stripped);
        $this->assertStringNotContainsString('Kiel', $stripped);
        $this->assertSame(0, ord($stripped[20]) & 0x0C);
        $this->assertSame(strlen($stripped) - 8, unpack('V', substr($stripped, 4, 4))[1]);
        $this->assertNotFalse(imagecreatefromstring($stripped));
    }
}
