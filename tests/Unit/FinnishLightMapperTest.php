<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\LighthouseList\FinnishLightMapper;

/** Väylävirasto's layers (rows as the live service answers them, 2026-11-22) -> staging entries of source "fi". */
final class FinnishLightMapperTest extends TestCase
{
    private function aid(string $number, string $name, array $more = []): array
    {
        return $more + ['turvalaitenumero' => $number, 'nimifi' => $name, 'nimisv' => null, 'sijaintifi' => 'Kallioluodolla Boistön E-puolella.',
            'turvalaitetyyppifi' => 'Sektoriloisto', 'toimintatilakoodi' => 1];
    }

    private function light(int $id, string $aid, array $more = []): array
    {
        return $more + ['id' => $id, 'laatu' => 'Pääloisto', 'korkeusvedesta' => 6.5, 'maantietkanto' => 9.0, 'optinenkanto' => 7.2,
            'viralvalotunnuse' => 'Fl (2) 6 s', 'virallvalotunnus' => 'V-B (2) 6 s', 'laji' => 'Yövalo', 'valoominaisuusfi' => null,
            'tarkkavalotunnus' => '2*(0,5+1,0) + 3,0=6,0 s', 'turvalaitenumero' => $aid, 'lat' => 60.3208834, 'lon' => 26.5047395];
    }

    private function sector(int $id, int $light, string $colour, float $from, float $to): array
    {
        return ['id' => $id, 'loisto' => $light, 'alkukulma' => $from, 'loppukulma' => $to, 'vari' => $colour];
    }

    public function testASectorLightBecomesAnEntryWithItsSectorsAndTheLightsRange(): void
    {
        $entries = (new FinnishLightMapper())->map(
            [$this->aid('110', 'Ljusaklack', ['nimisv' => 'Ljusaklack'])],
            [$this->light(101164, '110')],
            [$this->sector(2, 101164, 'p', 80, 104), $this->sector(1, 101164, 'v', 74, 80), $this->sector(3, 101164, 'vi', 104, 175)]
        );

        $this->assertCount(1, $entries);
        $e = $entries[0];
        $this->assertSame('fi', FinnishLightMapper::SOURCE);
        $this->assertSame('light/101164', $e['source_key']);
        $this->assertSame('110', $e['dk_no'], 'the authority\'s own number');
        $this->assertSame('', $e['international_id']);
        $this->assertSame('Ljusaklack', $e['name'], 'the same name in both languages is written once');
        $this->assertSame('Kallioluodolla Boistön E-puolella', $e['location'], 'the closing full stop is dropped');
        $this->assertSame(60.3208834, $e['latitude']);
        $this->assertSame(26.5047395, $e['longitude']);
        $this->assertSame('Fl(2) 6s', $e['characteristic']);
        $this->assertSame('6.5', $e['flame_height']);
        $this->assertSame('7.2 M', $e['light_range']);
        $this->assertSame("W 7.2M 74-80\nR 7.2M 80-104\nG 7.2M 104-175", $e['sector_characteristic'], 'in the order of the sectors\' ids, one range for every colour');
        $this->assertFalse($e['sector_missing_range']);
        $this->assertSame('Sektoriloisto', $e['subregion']);
    }

    public function testFinnishAndSwedishNamesAreBothKept(): void
    {
        $entries = (new FinnishLightMapper())->map([$this->aid('1', 'Söderskär', ['nimisv' => 'Sandskär'])], [$this->light(1, '1')], []);

        $this->assertSame('Söderskär / Sandskär', $entries[0]['name']);
    }

    public function testWithoutAnyRangeTheSectorsGetTheUnknownPlaceholderAndAreFlagged(): void
    {
        $entries = (new FinnishLightMapper())->map(
            [$this->aid('1', 'A')],
            [$this->light(1, '1', ['optinenkanto' => null, 'maantietkanto' => null])],
            [$this->sector(1, 1, 'v', 10, 20)]
        );

        $this->assertSame('W ?M 10-20', $entries[0]['sector_characteristic']);
        $this->assertTrue($entries[0]['sector_missing_range']);
        $this->assertSame('', $entries[0]['light_range']);
    }

    public function testTheGeographicRangeIsUsedWhenThereIsNoOpticalOne(): void
    {
        $entries = (new FinnishLightMapper())->map([$this->aid('1', 'A')], [$this->light(1, '1', ['optinenkanto' => null, 'maantietkanto' => 12.0])], [$this->sector(1, 1, 'v', 10, 20)]);

        $this->assertSame('W 12M 10-20', $entries[0]['sector_characteristic']);
    }

    public function testYellowSectorsAreLeftOutLikeEverywhereElse(): void
    {
        $entries = (new FinnishLightMapper())->map([$this->aid('1', 'A')], [$this->light(1, '1')], [$this->sector(1, 1, 'k', 10, 20)]);

        $this->assertNull($entries[0]['sector_characteristic']);
    }

    public function testOnlyAidsInOperationAreImported(): void
    {
        $entries = (new FinnishLightMapper())->map(
            [$this->aid('1', 'Käytössä'), $this->aid('2', 'Pois', ['toimintatilakoodi' => 2]), $this->aid('3', 'Tuntematon', ['toimintatilakoodi' => 0])],
            [$this->light(1, '1'), $this->light(2, '2'), $this->light(3, '3')],
            []
        );

        $this->assertSame(['Käytössä'], array_column($entries, 'name'));
    }

    public function testLightsOfOtherAidsAndLightsWithoutPositionAreIgnored(): void
    {
        $noPosition = $this->light(2, '1');
        unset($noPosition['lat'], $noPosition['lon']);
        $entries = (new FinnishLightMapper())->map([$this->aid('1', 'A')], [$this->light(5, '999'), $noPosition], []);

        $this->assertSame([], $entries);
    }

    public function testOneEntryPerAidPreferringTheNightLightWithSectors(): void
    {
        $entries = (new FinnishLightMapper())->map(
            [$this->aid('1', 'A')],
            [$this->light(10, '1', ['laji' => 'Päivävalo']), $this->light(11, '1'), $this->light(12, '1', ['laatu' => 'Lisäloisto'])],
            [$this->sector(1, 12, 'v', 10, 20)]
        );

        $this->assertCount(1, $entries);
        $this->assertSame('light/12', $entries[0]['source_key'], 'the one with sectors wins over the plain main light');
    }

    public function testAMissingNameFallsBackToTheNumber(): void
    {
        $entries = (new FinnishLightMapper())->map([$this->aid('77', '')], [$this->light(1, '77')], []);

        $this->assertSame('Finland 77', $entries[0]['name']);
    }
}
