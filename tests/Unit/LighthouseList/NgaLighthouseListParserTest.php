<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit\LighthouseList;

use PHPUnit\Framework\TestCase;
use Ytan\Service\LighthouseList\NgaLighthouseListParser;

/**
 * Works on hand-built fragments [x, y, text] laid out like the real NGA
 * List of Lights pages (labels centred over their columns, data left-aligned
 * at x 86.7 / 115.4 / 210.7 / 248.6 / 328.6 / 357 / 383.2 / 476.8, y growing
 * upwards).
 */
final class NgaLighthouseListParserTest extends TestCase
{
    /** @return list<array{0:float,1:float,2:string}> */
    private function header(float $shift = 0.0): array
    {
        $numbers = [82.0, 151.0, 217.6, 271.6, 318.1, 347.7, 412.0, 505.6];
        $fragments = [];
        foreach ($numbers as $i => $x) {
            $fragments[] = [$x + $shift, 748.0, '(' . ($i + 1) . ')'];
        }

        return array_merge($fragments, [
            [80.8 + $shift, 741.0, 'No.'], [123.1 + $shift, 741.0, 'Name and Location'], [208.9 + $shift, 741.0, 'Position'],
            [253.3 + $shift, 741.0, 'Characteristic'], [311.9 + $shift, 741.0, 'Height'], [341.2 + $shift, 741.0, 'Range'],
            [401.4 + $shift, 741.0, 'Structure'], [495.2 + $shift, 741.0, 'Remarks'],
            [309.4 + $shift, 22.1, '12'], // the page number
        ]);
    }

    /**
     * One light: rows from $y down (6.6 apart, like the real list).
     *
     * @param list<string> $nameLines
     * @param list<string> $characteristicLines
     * @param list<string> $ranges
     * @return list<array{0:float,1:float,2:string}>
     */
    private function entry(
        float $y,
        string $number,
        string $intNo,
        array $nameLines,
        string $lat,
        string $lon,
        array $characteristicLines,
        array $heights = [],
        array $ranges = [],
        string $structure = 'White tower.',
        array $remarks = []
    ): array {
        $f = [
            [86.7, $y - 0.7, $number],
            [210.7, $y, $lat],
            [214.7, $y - 6.6, $lon],
            [383.2, $y, $structure],
        ];
        if ($intNo !== '') {
            $f[] = [82.4, $y - 7.5, $intNo];
        }
        foreach ($nameLines as $i => $line) {
            $f[] = [$i === 0 ? 115.4 : 126.2, $y - $i * 6.6, $line];
        }
        foreach ($characteristicLines as $i => $line) {
            $f[] = [248.6, $y - $i * 6.6, $line];
        }
        foreach ($heights as $i => $line) {
            $f[] = [328.6, $y - $i * 6.6, $line];
        }
        foreach ($ranges as $i => $line) {
            $f[] = [357.1, $y - $i * 6.6, $line];
        }
        foreach ($remarks as $i => $line) {
            $f[] = [476.8, $y - $i * 6.6, $line];
        }

        return $f;
    }

    public function testParsesALightWithItsColumnsAndSectors(): void
    {
        $page = array_merge(
            $this->header(),
            [[272.0, 707.8, 'SWEDEN-WEST COAST']],
            $this->entry(
                680.0, '2628', 'C 0969', ['Aaro Island.'], '55° 15.4´ N', '9° 43.6´ E',
                ['Iso.W.R.G.', 'period 2s', 'fl. 1s, ec. 4s'], ['39', '12'], ['W. 11', 'R. 8', 'G. 8'],
                'White round tower.',
                ['R. 306°-322°, G.-353°, W.-', '001°, R.-151°.']
            )
        );

        $entries = (new NgaLighthouseListParser())->parse([$page]);

        $this->assertCount(1, $entries);
        $e = $entries[0];
        $this->assertSame('2628', $e['dk_no']);
        $this->assertSame('C0969', $e['international_id'], 'stored without the space');
        $this->assertSame('SWEDEN-WEST COAST', $e['region']);
        $this->assertSame('Aaro Island', $e['name']);
        $this->assertEqualsWithDelta(55 + 15.4 / 60, $e['latitude'], 0.000001);
        $this->assertEqualsWithDelta(9 + 43.6 / 60, $e['longitude'], 0.000001);
        $this->assertSame('Iso WRG 2s', $e['characteristic']);
        $this->assertSame('fl. 1s, ec. 4s', $e['other_signals']);
        $this->assertSame('12', $e['flame_height'], 'metres, the second of feet/metres');
        $this->assertSame('W. 11; R. 8; G. 8', $e['light_range']);
        $this->assertSame('White round tower.', $e['appearance']);
        $this->assertSame("R 8M 306-322\nG 8M 322-353\nW 11M 353-1\nR 8M 1-151", $e['sector_characteristic']);
    }

    public function testCharacteristicsAreWrittenTheWayYtanDoes(): void
    {
        $cases = [
            [['Fl.(2)W.R.G.', 'period 6s'], 'Fl(2) WRG 6s'],
            [['F. W. R .'], 'F WR'],
            [['Q.W.'], 'Q W'],
            [['V.Q.(8)W.R.'], 'VQ(8) WR'],
            [['LFl.W.', 'period 6s'], 'LFl W 6s'],
            [['2F.R.(vert)'], '2F R (vert)'],
            [['Iso.W.period 10s'], 'Iso W 10s'],
        ];
        foreach ($cases as [$lines, $expected]) {
            $page = array_merge($this->header(), $this->entry(680.0, '1', '', ['A.'], '55° 00.0´ N', '9° 00.0´ E', $lines));
            $this->assertSame($expected, (new NgaLighthouseListParser())->parse([$page])[0]['characteristic'], implode(' ', $lines));
        }
    }

    public function testASectorRangeComesFromTheColoursRangeAndUnintensifiedPartsCount(): void
    {
        $page = array_merge(
            $this->header(),
            $this->entry(680.0, '1', '', ['A.'], '55° 00.0´ N', '9° 00.0´ E', ['Fl.W.R.'], ['10', '3'], ['W. 9', 'R. 5'], 'Tower.',
                ['W. 053°6´-248°, R.-323°30´, W.Unintensified-', '350°.'])
        );

        $sector = (new NgaLighthouseListParser())->parse([$page])[0]['sector_characteristic'];

        $this->assertSame("W 9M 53.1-248\nR 5M 248-323.5\nW 9M 323.5-350", $sector);
    }

    public function testHeadingsSetRegionAndSubregionForTheEntriesBelow(): void
    {
        $page = array_merge(
            $this->header(),
            [[272.0, 707.8, 'SWEDEN-WEST COAST']],
            $this->entry(680.0, '1', '', ['Erste.'], '55° 00.0´ N', '9° 00.0´ E', ['Q.W.']),
            [[115.4, 640.0, 'FJALLBACKA']],
            $this->entry(620.0, '2', '', ['Zweite.'], '55° 01.0´ N', '9° 01.0´ E', ['Q.W.']),
            [[252.0, 580.0, 'DENMARK-KATTEGAT AND BELTS']],
            [[115.4, 560.0, 'HJARTOFJORD (HIERTO']],
            [[126.2, 553.4, 'FJORD):']],
            $this->entry(530.0, '3', '', ['Dritte.'], '55° 02.0´ N', '9° 02.0´ E', ['Q.W.'])
        );

        $entries = (new NgaLighthouseListParser())->parse([$page]);

        $this->assertSame(['SWEDEN-WEST COAST', 'SWEDEN-WEST COAST', 'DENMARK-KATTEGAT AND BELTS'], array_column($entries, 'region'));
        $this->assertSame(['', 'FJALLBACKA', 'HJARTOFJORD (HIERTO FJORD)'], array_column($entries, 'subregion'));
        $this->assertSame(['Erste', 'Zweite', 'Dritte'], array_column($entries, 'name'), 'a heading must not leak into the entry above');
    }

    public function testTheSecondLightOfAPairGetsTheNameOfTheFirst(): void
    {
        $page = array_merge(
            $this->header(),
            $this->entry(680.0, '1', 'C 0434', ['Koljebergskil Range, front.'], '58° 08.0´ N', '11° 25.1´ E', ['Iso.R.']),
            $this->entry(640.0, '2', 'C 0434.1', ['-Rear, 110 meters 093°30\' from front.'], '58° 08.0´ N', '11° 25.2´ E', ['Q.R.']),
            $this->entry(600.0, '3', '', ['Bojden Ferry Harbor, N. ', 'mole, head.'], '55° 06.4´ N', '10° 04.8´ E', ['Fl.R.']),
            $this->entry(560.0, '4', '', ['-S. mole, head.'], '55° 06.4´ N', '10° 04.8´ E', ['Fl.G.'])
        );

        $names = array_column((new NgaLighthouseListParser())->parse([$page]), 'name');

        $this->assertSame([
            'Koljebergskil Range, front',
            "Koljebergskil Range, Rear, 110 meters 093°30' from front",
            'Bojden Ferry Harbor, N. mole, head',
            'Bojden Ferry Harbor, S. mole, head',
        ], $names);
    }

    public function testRaconsAreNotLights(): void
    {
        $page = array_merge(
            $this->header(),
            $this->entry(680.0, '1', '', ['Mavholmsbadan,'], '57° 40.3´ N', '11° 42.5´ E', ['M(', '– –', ')'], [], [], '', ['(3 & 10 cm).']),
            [[126.2, 673.4, 'RACONRACON']],
            $this->entry(620.0, '2', '', ['Hono Huvud.'], '57° 41.4´ N', '11° 36.4´ E', ['Q.W.'])
        );

        $entries = (new NgaLighthouseListParser())->parse([$page]);

        $this->assertSame(['Hono Huvud'], array_column($entries, 'name'));
    }

    public function testPagesThatAreNotTheLightsTableAreSkipped(): void
    {
        // The radiobeacon table has the same kind of number row at other positions.
        $radiobeacons = [
            [267.2, 738.0, 'Radiobeacons'],
            [85.6, 685.4, '(1)'], [150.1, 685.4, '(2)'], [218.1, 685.4, '(3)'], [286.5, 685.4, '(4)'],
            [345.9, 685.4, '(5)'], [376.8, 685.4, '(6)'], [416.4, 685.4, '(7)'], [497.0, 685.4, '(8)'],
            [269.4, 678.4, 'Characteristic'], [339.8, 678.4, 'Range'],
        ];

        $this->assertSame([], (new NgaLighthouseListParser())->parse([$radiobeacons, []]));
    }

    public function testAPageShiftedAsAWholeIsStillRead(): void
    {
        $page = array_merge($this->header(8.0), array_map(
            fn ($f) => [$f[0] + 8.0, $f[1], $f[2]],
            $this->entry(680.0, '1', 'C 1', ['Verschoben.'], '55° 00.0´ N', '9° 00.0´ E', ['Q.W.'], ['5'], ['3'])
        ));

        $entries = (new NgaLighthouseListParser())->parse([$page]);

        $this->assertCount(1, $entries);
        $this->assertSame('Verschoben', $entries[0]['name']);
        $this->assertSame('Q W', $entries[0]['characteristic']);
        $this->assertSame('C1', $entries[0]['international_id']);
    }
}
