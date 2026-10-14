<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit\LighthouseList;

use PHPUnit\Framework\TestCase;
use Ytan\Service\LighthouseList\DanishLighthouseListParser;

/**
 * Works on hand-built fragments [x, y, text] laid out like the real Dansk
 * Fyrliste pages (x of the 8 column headers 31.7/92.4/259.6/336.8/399.1/445.5/
 * 495.0/586.5, data a little right of them, y growing upwards).
 */
final class DanishLighthouseListParserTest extends TestCase
{
    /** @return list<array{0:float,1:float,2:string}> */
    private function header(string $region = 'A. Nordsøen'): array
    {
        return [
            [29.6, 548.0, $region],
            [313.1, 548.1, 'Fortegnelse over fyr og tågesignalstationer '],
            [31.7, 514.4, 'Dansk nr./'], [92.4, 514.4, 'Navn/'], [259.6, 514.4, 'Bredde/'],
            [336.8, 514.4, 'Fyrkarakter/'], [399.1, 514.4, 'Flamme-'], [445.5, 514.4, 'Lysevne'],
            [495.0, 514.4, 'Fyrudseende/'], [586.5, 514.4, 'Yderligere oplysninger'],
            [31.7, 500.1, 'Int. nr.'], [92.4, 500.1, 'Lokalitet'], [259.6, 500.1, 'Længde'],
        ];
    }

    /**
     * One entry; its rows start at $y (the anchor row with the latitude).
     *
     * @param list<string> $ranges
     * @return list<array{0:float,1:float,2:string}>
     */
    private function entry(
        float $y,
        string $dkNo,
        string $intNo,
        string $name,
        string $location,
        string $lonMinutes,
        string $characteristic,
        array $ranges = [],
        string $remarks = '',
        float $numberOffset = 0.0
    ): array {
        $fragments = [
            [33.3, $y + $numberOffset, $dkNo],
            [95.7, $y, $name],
            [258.6, $y, '55'], [270.4, $y, '°'], [283.0, $y, '27,49'], [305.9, $y, "'"], [314.2, $y, 'N'],
            [264.1, $y - 12, '8'], [271.0, $y - 12, '°'], [283.4, $y - 12, $lonMinutes], [306.3, $y - 12, "'"], [313.2, $y - 12, 'E'],
            [335.7, $y, $characteristic],
        ];
        if ($intNo !== '') {
            $fragments[] = [33.3, $y - 11, $intNo];
        }
        if ($location !== '') {
            $fragments[] = [95.6, $y - 12, $location];
        }
        foreach ($ranges as $i => $range) {
            $fragments[] = [448.9, $y - $i * 11, $range];
        }
        if ($remarks !== '') {
            $fragments[] = [584.8, $y, $remarks];
        }

        return $fragments;
    }

    public function testParsesTwoEntriesWithTheirColumns(): void
    {
        $page = array_merge(
            $this->header(),
            [[33.3, 483.7, 'Vadehavet til Grådyb']],
            $this->entry(467.7, '44', 'B1780.2', 'Fovrfelt N.', 'Ca. 0,5 sm S for Forfyr', '33,65', 'Oc(2)WRG.6s', ['W 6,5', 'R 4,5', 'G 4,5'], 'G327°-331° W331°-333,5° R333,5°-342°.'),
            $this->entry(423.2, '46', '', 'Esbjerg Lystbådehavn', 'S-lige molehoved', '24,46', 'Fl.G.3s', ['G 2,5'])
        );

        $entries = (new DanishLighthouseListParser())->parse([$page]);

        $this->assertCount(2, $entries);

        $first = $entries[0];
        $this->assertSame('44', $first['dk_no']);
        $this->assertSame('B1780.2', $first['international_id']);
        $this->assertSame('A. Nordsøen', $first['region']);
        $this->assertSame('Vadehavet til Grådyb', $first['subregion']);
        $this->assertSame('Fovrfelt N', $first['name']);
        $this->assertSame('Ca. 0,5 sm S for Forfyr', $first['location']);
        $this->assertEqualsWithDelta(55 + 27.49 / 60, $first['latitude'], 0.000001);
        $this->assertEqualsWithDelta(8 + 33.65 / 60, $first['longitude'], 0.000001);
        $this->assertSame('Oc(2) WRG 6s', $first['characteristic']);
        $this->assertSame("G 4.5M 327-331\nW 6.5M 331-333.5\nR 4.5M 333.5-342", $first['sector_characteristic']);

        $second = $entries[1];
        $this->assertSame('46', $second['dk_no']);
        $this->assertSame('', $second['international_id']);
        $this->assertSame('Esbjerg Lystbådehavn', $second['name']);
        $this->assertSame('S-lige molehoved', $second['location']);
        $this->assertSame('Fl G 3s', $second['characteristic']);
        $this->assertNull($second['sector_characteristic']);
    }

    public function testANumberFloatingAboveItsEntryStillBelongsToIt(): void
    {
        // dk no. printed 2 points above the anchor row, as in the real list.
        $page = array_merge(
            $this->header(),
            $this->entry(467.7, '48', 'B1782', 'Strandby.', 'N-siden af ny dækmole', '24,63', 'Oc.WRG.5s'),
            $this->entry(423.2, '52', 'B1780.4', 'Fovrfelt.', 'Ca. 1 sm NW', '23,75', 'Fl(2)R.10s', [], '', 2.0)
        );

        $entries = (new DanishLighthouseListParser())->parse([$page]);

        $this->assertSame(['48', '52'], array_column($entries, 'dk_no'));
        $this->assertSame('Fovrfelt', $entries[1]['name']);
        $this->assertSame('B1780.4', $entries[1]['international_id']);
    }

    public function testOffshoreNumbersWithALetterAreNotMistakenForHeadings(): void
    {
        $page = array_merge(
            $this->header(),
            $this->entry(467.7, '151 M', 'C0202.2', 'Horns Rev 3.', 'Mølle E01', '24,46', 'Fl(3)Y.10s')
        );

        $entries = (new DanishLighthouseListParser())->parse([$page]);

        $this->assertCount(1, $entries);
        $this->assertSame('151 M', $entries[0]['dk_no']);
        $this->assertSame('', $entries[0]['subregion']);
    }

    public function testInternationalNumberIsStoredWithoutSpaces(): void
    {
        $page = array_merge(
            $this->header(),
            $this->entry(467.7, '12', 'B 1804.42', 'Rømø Havn.', '', '24,46', 'F.R')
        );

        $this->assertSame('B1804.42', (new DanishLighthouseListParser())->parse([$page])[0]['international_id']);
    }

    public function testPagesWithoutTheLighthouseTableAreSkipped(): void
    {
        $racon = [
            [29.6, 548.0, 'A. Nordsøen'],
            [313.1, 548.1, 'Fortegnelse over racon'],
            [31.7, 514.4, 'Dansk nr./'],
        ];

        $this->assertSame([], (new DanishLighthouseListParser())->parse([$racon, []]));
    }

    public function testFragmentsAboveTheFirstEntryContinueTheLastEntryOfThePreviousPage(): void
    {
        $page1 = array_merge($this->header(), $this->entry(100.0, '90', '', 'Skagen.', '', '44,00', 'Fl.W.5s'));
        // Page 2 starts with the rest of entry 90's remarks, then a new entry.
        $page2 = array_merge(
            $this->header(),
            [[584.8, 470.0, 'Tændt i mørke.']],
            $this->entry(440.0, '91', '', 'Grenen.', '', '45,00', 'Fl.R.5s')
        );

        $entries = (new DanishLighthouseListParser())->parse([$page1, $page2]);

        $this->assertCount(2, $entries);
        $this->assertSame('Tændt i mørke.', $entries[0]['remarks']);
        $this->assertSame('', $entries[1]['remarks']);
    }
}
