<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ValidationException;

/**
 * Removes metadata (EXIF incl. GPS position, XMP, IPTC, comments, text
 * chunks) from an uploaded photo WITHOUT re-encoding it - the pixels and
 * the quality stay exactly as uploaded. Photos are served to everybody who
 * may see the POI/route/area/tour, and a phone photo's EXIF usually carries
 * the exact position, time and device (todo.md "Löschung von EXIF-Daten").
 *
 * - JPEG: walks the marker segments and keeps only what decoding needs
 *   (quantisation/Huffman tables, frame/scan headers, JFIF APP0, the ICC
 *   colour profile in APP2 and Adobe APP14 for CMYK); every other APPn and
 *   COM is dropped, and so is anything after the end-of-image marker
 *   (multi-picture files append further images there, with their own EXIF).
 *   The EXIF orientation is the one value worth keeping - without it a
 *   portrait phone photo would show lying on its side - so it comes back as
 *   a minimal Exif segment holding nothing but that tag.
 * - PNG: drops tEXt/zTXt/iTXt/eXIf/tIME chunks and anything after IEND.
 * - WebP: drops the EXIF and "XMP " chunks and clears their VP8X flags.
 *
 * A file whose structure can't be followed is refused (ValidationException)
 * rather than stored with its metadata.
 */
final class ImageMetadataStripper
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const PNG_DROP = ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'];

    public static function strip(string $bytes, string $mime): string
    {
        $result = match ($mime) {
            'image/jpeg' => self::jpeg($bytes),
            'image/png' => self::png($bytes),
            'image/webp' => self::webp($bytes),
            default => $bytes,
        };
        if ($result === null) {
            throw new ValidationException('The image could not be read.');
        }

        return $result;
    }

    private static function jpeg(string $b): ?string
    {
        $n = strlen($b);
        if ($n < 4 || substr($b, 0, 2) !== "\xFF\xD8") {
            return null;
        }

        $head = '';      // SOI + APP0 (JFIF), the Exif orientation goes behind it
        $body = '';
        $orientation = 1;
        $pos = 2;

        while ($pos < $n) {
            if ($b[$pos] !== "\xFF") {
                return null;
            }
            while ($pos < $n && $b[$pos] === "\xFF") {
                $pos++; // fill bytes
            }
            if ($pos >= $n) {
                return null;
            }
            $marker = ord($b[$pos]);
            $pos++;

            if ($marker === 0xD9) { // EOI - anything behind it is dropped
                $body .= "\xFF\xD9";
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $body .= "\xFF" . chr($marker);
                continue;
            }
            if ($pos + 2 > $n) {
                return null;
            }
            $length = unpack('n', substr($b, $pos, 2))[1];
            if ($length < 2 || $pos + $length > $n) {
                return null;
            }
            $segment = "\xFF" . chr($marker) . substr($b, $pos, $length);
            $payload = substr($b, $pos + 2, $length - 2);
            $pos += $length;

            if ($marker === 0xDA) { // SOS: header, then entropy-coded data up to the next real marker
                $end = self::jpegScanEnd($b, $pos);
                $body .= $segment . substr($b, $pos, $end - $pos);
                $pos = $end;
                if ($pos >= $n) {
                    break; // truncated file without EOI - keep what there is
                }
                continue;
            }

            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
                $orientation = self::exifOrientation(substr($payload, 6)) ?? $orientation;
                continue;
            }
            $keep = match (true) {
                $marker === 0xE0 => str_starts_with($payload, "JFIF\0"),
                $marker === 0xE2 => str_starts_with($payload, "ICC_PROFILE\0"),
                $marker === 0xEE => str_starts_with($payload, 'Adobe'),
                $marker >= 0xE1 && $marker <= 0xEF => false,
                $marker === 0xFE => false,
                default => true,
            };
            if (!$keep) {
                continue;
            }
            if ($marker === 0xE0 && $head === '' && $body === '') {
                $head = $segment;
            } else {
                $body .= $segment;
            }
        }

        $exif = $orientation >= 2 && $orientation <= 8 ? self::orientationSegment($orientation) : '';

        return "\xFF\xD8" . $head . $exif . $body;
    }

    /** First position at/after $pos where a marker (not stuffing, not RSTn) starts, or strlen. */
    private static function jpegScanEnd(string $b, int $pos): int
    {
        $n = strlen($b);
        while (($pos = strpos($b, "\xFF", $pos)) !== false) {
            if ($pos + 1 >= $n) {
                return $n;
            }
            $next = ord($b[$pos + 1]);
            if ($next === 0x00 || $next === 0xFF || ($next >= 0xD0 && $next <= 0xD7)) {
                $pos += $next === 0xFF ? 1 : 2;
                continue;
            }

            return $pos;
        }

        return $n;
    }

    /** Orientation tag (0x0112) of IFD0 in a TIFF block, null when absent/unreadable. */
    private static function exifOrientation(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }
        $u16 = static fn (int $at) => unpack($order === 'II' ? 'v' : 'n', substr($tiff, $at, 2))[1];
        $u32 = static fn (int $at) => unpack($order === 'II' ? 'V' : 'N', substr($tiff, $at, 4))[1];

        $ifd = $u32(4);
        if ($ifd + 2 > strlen($tiff)) {
            return null;
        }
        $count = $u16($ifd);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                return null;
            }
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }

    /** APP1 Exif segment containing only IFD0 with the orientation tag. */
    private static function orientationSegment(int $orientation): string
    {
        $tiff = "MM\x00\x2A" . pack('N', 8)
            . pack('n', 1)
            . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\x00\x00"
            . pack('N', 0);
        $payload = "Exif\0\0" . $tiff;

        return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    }

    private static function png(string $b): ?string
    {
        if (!str_starts_with($b, self::PNG_SIGNATURE)) {
            return null;
        }
        $n = strlen($b);
        $out = self::PNG_SIGNATURE;
        $pos = 8;
        while ($pos + 12 <= $n) {
            $length = unpack('N', substr($b, $pos, 4))[1];
            $type = substr($b, $pos + 4, 4);
            if ($pos + 12 + $length > $n) {
                return null;
            }
            if (!in_array($type, self::PNG_DROP, true)) {
                $out .= substr($b, $pos, 12 + $length);
            }
            $pos += 12 + $length;
            if ($type === 'IEND') {
                return $out;
            }
        }

        return null;
    }

    private static function webp(string $b): ?string
    {
        if (strlen($b) < 12 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') {
            return null;
        }
        $end = min(strlen($b), 8 + unpack('V', substr($b, 4, 4))[1]);
        $chunks = '';
        $pos = 12;
        while ($pos + 8 <= $end) {
            $type = substr($b, $pos, 4);
            $size = unpack('V', substr($b, $pos + 4, 4))[1];
            $padded = $size + ($size & 1);
            if ($pos + 8 + $size > $end) {
                return null;
            }
            $chunk = substr($b, $pos, 8 + $padded);
            $pos += 8 + $padded;
            if ($type === 'EXIF' || $type === 'XMP ') {
                continue;
            }
            if ($type === 'VP8X' && $size >= 1) {
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C); // clear the EXIF (0x08) and XMP (0x04) flags
            }
            $chunks .= $chunk;
        }

        return 'RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks;
    }
}
