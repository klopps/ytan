<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * The Connect IQ settings file (.SET) that gives the YTAN data field on a
 * sideloaded watch its watch key without a new build (watch/ reads the
 * "watchKey" property, YtanKey.token()). Garmin doesn't document the
 * format; this is what a fenix 7X writes for the one string property
 * "watchKey", compared byte for byte with files from the watch (default
 * value and "ABCDE"):
 *
 *   ABCDABCD, u32 length, then per property u16 name length + name + NUL and
 *   u16 value length + value + NUL (lengths include the NUL), all
 *   big-endian; DA7ADA7A, u32 15, 0b 00000001 03 00000000 03 0000000b (a
 *   fixed description of one string property).
 */
final class WatchSettingsFile
{
    public const PROPERTY = 'watchKey';
    /** What the user has to rename it to: the name the watch gave its own file. */
    public const DOWNLOAD_NAME = 'ytan-ReplaceByWatchName.SET';

    public static function build(string $value, string $property = self::PROPERTY): string
    {
        $entries = pack('n', strlen($property) + 1) . $property . "\0"
            . pack('n', strlen($value) + 1) . $value . "\0";

        return "\xAB\xCD\xAB\xCD" . pack('N', strlen($entries)) . $entries
            . "\xDA\x7A\xDA\x7A" . pack('N', 15)
            . "\x0b\x00\x00\x00\x01\x03\x00\x00\x00\x00\x03\x00\x00\x00\x0b";
    }
}
