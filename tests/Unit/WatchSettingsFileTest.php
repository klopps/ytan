<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\WatchKeyVault;
use Ytan\Service\WatchSettingsFile;

final class WatchSettingsFileTest extends TestCase
{
    /**
     * The file a fenix 7X itself wrote for the data field's "watchKey"
     * property after a test build had set it to "ABCDE" (read back from the
     * watch's GARMIN/Apps/SETTINGS) - the format isn't documented by Garmin,
     * so this is the reference.
     */
    public function testMatchesTheFileTheWatchWroteForAKnownValue(): void
    {
        $fromWatch = hex2bin(
            'abcdabcd' . '00000013' . '0009' . '7761746368' . '4b657900' . '0006' . '4142434445' . '00'
            . 'da7ada7a' . '0000000f' . '0b00000001' . '0300000000' . '030000000b'
        );

        $this->assertSame($fromWatch, WatchSettingsFile::build('ABCDE'));
    }

    public function testLengthsFollowTheKey(): void
    {
        $key = bin2hex(random_bytes(16));

        $file = WatchSettingsFile::build($key);

        // 77 bytes for a 32 character key (the file the watch wrote for "" was 45).
        $this->assertSame(77, strlen($file));
        $this->assertSame(pack('N', 0x2e), substr($file, 4, 4));
        $this->assertStringContainsString("\0\x21" . $key . "\0", $file);
    }

    public function testVaultRoundTripAndWrongSecret(): void
    {
        $vault = new WatchKeyVault('secret-a');
        $key = bin2hex(random_bytes(16));

        $encrypted = $vault->encrypt($key);

        $this->assertStringNotContainsString($key, $encrypted);
        $this->assertNotSame($encrypted, $vault->encrypt($key), 'a fresh IV per encryption');
        $this->assertSame($key, $vault->decrypt($encrypted));
        $this->assertNull((new WatchKeyVault('secret-b'))->decrypt($encrypted));
        $this->assertNull($vault->decrypt('not base64 !!'));
        $this->assertNull($vault->decrypt(base64_encode('short')));
    }
}
