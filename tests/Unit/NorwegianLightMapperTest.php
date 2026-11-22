<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\LighthouseList\NorwegianLightMapper;

/** Kystverket's layers Lys and Sektorflate (rows as the live service answers them, 2026-11-22) -> staging entries of source "no". */
final class NorwegianLightMapperTest extends TestCase
{
    private const GUID = '{011A7450-2BF9-4BE3-802B-BAC1C7EF787E}';

    private function light(array $more = []): array
    {
        return $more + ['objectid' => '1', 'sjomerke_nr' => '560000', 'navn' => 'Sklinna', 'sted' => 'Sklinna', 'beliggenhet' => 'På Heimøy',
            'klasse' => 'FS - Fyrstasjon', 'nfs_status' => 'Operativ', 'karakter' => 'Oc WRG 6s', 'beskrivelse' => 'Fyrbygning med tårn',
            'lys_hoyde' => '45.00000000', 'rekkevidde_hvit' => '18.60000000', 'rekkevidde_rod' => '12.00000000', 'rekkevidde_gronn' => '12.80000000',
            'globalid' => self::GUID, 'lat' => 65.20203906, 'lon' => 10.99514589];
    }

    private function sector(string $colour, string $from, string $to, string $nr, string $guid = self::GUID): array
    {
        return ['lys_farge' => $colour, 'retning_sektorlinje_1' => $from, 'retning_sektorlinje_2' => $to, 'sektor_nr' => $nr, 'fknavinst' => $guid];
    }

    public function testALighthouseStationBecomesAnEntryWithSectorsInTheOrderOfTheirNumbers(): void
    {
        $entries = (new NorwegianLightMapper())->map([$this->light()], [
            $this->sector('Rød', '53.66880000', '55.78340000', '2'),
            $this->sector('Hvit', '47.59780000', '53.66880000', '1'),
            $this->sector('Grønn', '55.78340000', '58.25460000', '3'),
        ]);

        $this->assertCount(1, $entries);
        $e = $entries[0];
        $this->assertSame('no', NorwegianLightMapper::SOURCE);
        $this->assertSame('lys/011a7450-2bf9-4be3-802b-bac1c7ef787e', $e['source_key'], 'the GUID without braces, lower case');
        $this->assertSame('560000', $e['dk_no'], 'with its leading zeros as a string');
        $this->assertSame('', $e['international_id']);
        $this->assertSame('Sklinna', $e['name']);
        $this->assertSame('Sklinna, På Heimøy', $e['location']);
        $this->assertSame('Fyrstasjon', $e['subregion']);
        $this->assertSame(65.2020391, $e['latitude']);
        $this->assertSame('Oc WRG 6s', $e['characteristic']);
        $this->assertSame('45', $e['flame_height']);
        $this->assertSame('W 18.6; R 12; G 12.8', $e['light_range']);
        $this->assertSame("W 18.6M 47.598-53.669\nR 12M 53.669-55.783\nG 12.8M 55.783-58.255", $e['sector_characteristic'], 'the range of each sector\'s own colour; bearings as the service gives them (from seaward)');
        $this->assertFalse($e['sector_missing_range']);
        $this->assertSame('Fyrbygning med tårn', $e['remarks']);
    }

    public function testOnlyLighthouseStationsAndLanternsAreImported(): void
    {
        $entries = (new NorwegianLightMapper())->map([
            $this->light(['navn' => 'Fyr', 'globalid' => '{00000000-0000-0000-0000-000000000001}']),
            $this->light(['navn' => 'Lykt', 'klasse' => 'FL - Fyrlykt', 'globalid' => '{00000000-0000-0000-0000-000000000002}']),
            $this->light(['navn' => 'Lanterne', 'klasse' => 'LA - Lanterne', 'globalid' => '{00000000-0000-0000-0000-000000000003}']),
            $this->light(['navn' => 'Bøye', 'klasse' => 'LB - Lysbøye', 'globalid' => '{00000000-0000-0000-0000-000000000004}']),
        ], []);

        $this->assertSame(['Fyr', 'Lykt'], array_column($entries, 'name'));
        $this->assertSame('Fyrlykt', $entries[1]['subregion']);
    }

    public function testASectorLinksToItsLightByGuidInAnyCaseAndBraces(): void
    {
        $entries = (new NorwegianLightMapper())->map([$this->light()], [
            $this->sector('Hvit', '10', '20', '1', strtolower(trim(self::GUID, '{}'))),
            $this->sector('Hvit', '30', '40', '2', '{00000000-0000-0000-0000-00000000FFFF}'),
        ]);

        $this->assertSame('W 18.6M 10-20', $entries[0]['sector_characteristic'], 'a sector of another light is not attached');
    }

    public function testASectorColourWithoutARangeGetsThePlaceholderAndIsFlagged(): void
    {
        $entries = (new NorwegianLightMapper())->map([$this->light(['rekkevidde_rod' => null])], [$this->sector('Rød', '10', '20', '1')]);

        $this->assertSame('R ?M 10-20', $entries[0]['sector_characteristic']);
        $this->assertTrue($entries[0]['sector_missing_range']);
    }

    public function testYellowAndUndefinedSectorsAreLeftOut(): void
    {
        $entries = (new NorwegianLightMapper())->map([$this->light()], [$this->sector('Gul', '10', '20', '1'), $this->sector('Udefinert', '20', '30', '2')]);

        $this->assertNull($entries[0]['sector_characteristic']);
    }

    public function testKystverketsSpellingOfTheCharacteristicIsAlignedWithTheOtherSources(): void
    {
        $map = fn (string $c) => (new NorwegianLightMapper())->map([$this->light(['karakter' => $c])], [])[0]['characteristic'];

        $this->assertSame('Oc(2) WRG 8s', $map('Oc (2) WRG 8s'));
        $this->assertSame('F R', $map('Fast R lys'));
        $this->assertSame('FFl(2) W 20s', $map('FFl (2) W 20s'));
    }

    public function testLightsWithoutPositionOrGuidAreSkippedAndAMissingNameUsesThePlace(): void
    {
        $noPosition = $this->light(['globalid' => '{00000000-0000-0000-0000-000000000009}']);
        unset($noPosition['lat'], $noPosition['lon']);
        $entries = (new NorwegianLightMapper())->map([
            $noPosition,
            $this->light(['globalid' => 'kaputt']),
            $this->light(['navn' => '', 'sted' => 'Fitjar', 'beliggenhet' => '', 'globalid' => '{00000000-0000-0000-0000-000000000008}']),
        ], []);

        $this->assertCount(1, $entries);
        $this->assertSame('Fitjar', $entries[0]['name']);
        $this->assertSame('Fitjar', $entries[0]['location']);
    }
}
