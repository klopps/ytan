<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\WatchDatafieldIndex;

final class WatchDatafieldIndexTest extends TestCase
{
    public function testDevicesComeFromTheManifestInItsOrder(): void
    {
        $xml = '<iq:products><iq:product id="fr245m"/><iq:product id="venu3s"/><iq:product id="fr245m"/></iq:products>';
        $this->assertSame(['fr245m', 'venu3s'], WatchDatafieldIndex::devices($xml));
    }

    public function testTheRealManifestListsTheVenu3S(): void
    {
        $devices = WatchDatafieldIndex::devices((string) file_get_contents(dirname(__DIR__, 2) . '/watch/manifest.xml'));
        $this->assertContains('venu3s', $devices);
        $this->assertContains('fenix7x', $devices);
    }

    public function testDisplayNameWithoutTrademarkSigns(): void
    {
        $this->assertSame('Venu 3S', WatchDatafieldIndex::displayName('venu3s', '{"displayName":"Venu® 3S"}'));
        $this->assertSame('fēnix 7X', WatchDatafieldIndex::displayName('fenix7x', '{"displayName":"fēnix® 7X"}'));
        $this->assertSame('venu3s', WatchDatafieldIndex::displayName('venu3s', null), 'no device file: the id');
        $this->assertSame('venu3s', WatchDatafieldIndex::displayName('venu3s', '{}'));
    }

    public function testOnlyBuiltModelsSortedByName(): void
    {
        $index = WatchDatafieldIndex::build(
            ['venu3s' => 'Venu 3S', 'fenix7x' => 'fēnix 7X', 'fr245m' => 'Forerunner 245 Music'],
            ['venu3s' => 29324, 'fenix7x' => 30000],
            '202610241200'
        );

        $this->assertSame('202610241200', $index['version']);
        $this->assertSame(['fenix7x', 'venu3s'], array_column($index['fields'], 'device'));

        $sorted = WatchDatafieldIndex::build(['fr245m' => 'Forerunner 245 Music', 'fenix7x' => 'fēnix 7X'], ['fr245m' => 1, 'fenix7x' => 1], 'v');
        $this->assertSame(['fenix7x', 'fr245m'], array_column($sorted['fields'], 'device'), 'fēnix sorts as fenix');
        $this->assertSame(['device' => 'venu3s', 'name' => 'Venu 3S', 'file' => 'ytan-venu3s.prg', 'size' => 29324], $index['fields'][1]);
    }
}
