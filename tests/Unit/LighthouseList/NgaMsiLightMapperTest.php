<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit\LighthouseList;

use PHPUnit\Framework\TestCase;
use Ytan\Service\LighthouseList\NgaMsiLightMapper;

/**
 * Maps rows of the NGA Maritime Safety Information web service to staging
 * entries. The fixture (tests/testdata/nga-msi-pub116-sample.json) holds real
 * rows of volume 116: a sector light, a range light with a racon row, a light
 * whose second row has the sectors, a padded international number, a buoy
 * without number, a row without position.
 */
final class NgaMsiLightMapperTest extends TestCase
{
    /** @return array<string, array<string,mixed>> source_key => entry */
    private function entries(): array
    {
        $answer = json_decode((string) file_get_contents(__DIR__ . '/../../testdata/nga-msi-pub116-sample.json'), true);
        $byKey = [];
        foreach ((new NgaMsiLightMapper())->mapVolume($answer['ngalol']) as $entry) {
            $byKey[$entry['source_key']] = $entry;
        }

        return $byKey;
    }

    public function testASectorLightWithPositionToTheSecond(): void
    {
        $e = $this->entries()['116|4|C0277']; // Kattholmen

        $this->assertSame('pub116/4', $e['source_ref']);
        $this->assertSame('4', $e['dk_no'], 'the list number');
        $this->assertSame('C0277', $e['international_id']);
        $this->assertSame('Kattholmen', $e['name']);
        $this->assertSame('SWEDEN-WEST COAST', $e['region']);
        $this->assertSame('', $e['subregion']);
        $this->assertEqualsWithDelta(59 + 3 / 60 + 38.11 / 3600, $e['latitude'], 0.0000001);
        $this->assertEqualsWithDelta(11 + 9 / 60 + 21.8 / 3600, $e['longitude'], 0.0000001);
        $this->assertSame('Fl WRG 3s', $e['characteristic']);
        $this->assertSame('9', $e['flame_height'], 'metres, the second line of the feet/metres cell');
        $this->assertSame('W. 6; R. 5; G. 4', $e['light_range']);
        $this->assertSame('Tower.', $e['appearance']);
        $this->assertStringStartsWith("G 4M 177.9-204.7\nW 6M 204.7-224.7\nR 5M 224.7-297.3\nG 4M 297.3-22.8", $e['sector_characteristic'], 'the backtick is the minute sign');
        $this->assertFalse($e['sector_missing_range']);
    }

    public function testARaconRowIsNoLightAndLeavesTheEntryAlone(): void
    {
        $entries = $this->entries();
        $e = $entries['116|12|C0280']; // range light front + a RACON row (charNo 2)

        $this->assertSame('Klovningarna Range, front', $e['name']);
        $this->assertSame('Iso WRG 4s', $e['characteristic']);
        $this->assertStringNotContainsString('cm', $e['remarks'], 'the racon\'s "(3 & 10cm)" is not a remark of the light');
        $this->assertCount(8, $entries, '11 rows of 8 lights (the racon row, the empty-position row and a second row go into/out of their light)');
    }

    public function testASecondCharacteristicRowSuppliesTheSectors(): void
    {
        $e = $this->entries()['116|144|C0317']; // row 1 "Q.R." (range line only), row 2 "Q.W.R.G." with the sectors

        $this->assertSame('Q WRG', $e['characteristic']);
        $this->assertSame("G 2M 234.9-239.2\nW 3M 239.2-243.1\nR 2M 243.1-42.8", $e['sector_characteristic']);
        $this->assertSame('W. 3; R. 2; G. 2', $e['light_range']);
        $this->assertStringContainsString('Visible on range line only.', $e['remarks']);
        $this->assertStringContainsString('G. 234°54`', $e['remarks'], 'the remarks of every row of the light');
        $this->assertSame('GREBBESTAD', $e['subregion'], 'the region heading, colon dropped');
    }

    public function testPaddedInternationalNumbersLoseTheirTrailingZero(): void
    {
        $entries = $this->entries();

        $this->assertArrayHasKey('116|344|C0378.6', $entries, '"C0378.60" is the "C 0378.6" the PDF prints');
        $this->assertSame('C0378.6', $entries['116|344|C0378.6']['international_id']);
        $this->assertSame('C0280.01', $entries['116|16|C0280.01']['international_id'], '.01 is a different number than .1');
    }

    public function testUnintensifiedPartsOfASectorLightAreSectorsToo(): void
    {
        $e = $this->entries()['116|16|C0280.01'];

        $this->assertSame("W 10M 49-57\nG 5M 90-115\nW 10M 115-122\nR 6M 122-137.5\nW 10M 137.5-203", $e['sector_characteristic']);
    }

    public function testABuoyWithoutNumberAndARowWithoutPosition(): void
    {
        $entries = $this->entries();

        $this->assertSame('', $entries['116|384|']['international_id']);
        $this->assertSame('', $entries['116|384|']['flame_height']);
        $this->assertArrayNotHasKey('116|13077.5|C4076.45', $entries, 'no position, no entry');
    }

    public function testNamesAreCleanedAndTheSecondLightFollowsItsParent(): void
    {
        $row = fn (string $no, string $name, string $position) => [
            'volumeNumber' => 'PUB 116', 'featureNumber' => $no, 'name' => $name, 'position' => $position, 'charNo' => 1,
            'characteristic' => "Fl.W.\nperiod 5s \n", 'heightFeetMeters' => null, 'range' => '5', 'structure' => null, 'remarks' => null,
            'geopoliticalHeading' => 'DENMARK-KATTEGAT', 'regionHeading' => 'SKAGEN:', 'subregionHeading' => 'HAVN',
        ];
        $rows = [
            $row("100\nC1000", "Alpha, front.\n", "57°00'00\"N \n10°00'00\"E"),
            $row("104\nC1001", '-Beta Range, front.', "57°00'10\"N \n10°00'10\"E"),
            $row("108\nC1001.01", "--Rear, 100 meters\n010^ from front.", "57°00'20\"N \n10°00'20\"E"),
            $row("112\nC1002", '-S. mole, head.', "57°00'30\"N \n10°00'30\"E"),
            $row("116\nC1003", 'Gamma.', "57°00'40\"N \n10°00'40\"E"),
        ];

        $entries = (new NgaMsiLightMapper())->mapVolume($rows);

        $this->assertSame([
            'Alpha, front',
            'Alpha, Beta Range, front',
            'Beta Range, Rear, 100 meters 010° from front',
            'Alpha, S. mole, head',
            'Gamma',
        ], array_column($entries, 'name'));
        $this->assertSame('SKAGEN / HAVN', $entries[0]['subregion'], 'region heading and sub-heading');
    }

    public function testTheSameKeyTwiceStaysTwoEntries(): void
    {
        $row = fn (string $name) => [
            'volumeNumber' => 'PUB 116', 'featureNumber' => "100\nC1000", 'name' => $name, 'position' => "57°00'00\"N \n10°00'00\"E", 'charNo' => 1,
            'characteristic' => "F.R.\n", 'heightFeetMeters' => null, 'range' => null, 'structure' => null, 'remarks' => null,
            'geopoliticalHeading' => 'X', 'regionHeading' => null, 'subregionHeading' => null,
        ];
        $other = ['volumeNumber' => 'PUB 116', 'featureNumber' => "104\nC1001", 'name' => 'Mittendrin', 'position' => "57°01'00\"N \n10°01'00\"E",
            'charNo' => 1, 'characteristic' => "F.G.\n", 'heightFeetMeters' => null, 'range' => null, 'structure' => null, 'remarks' => null,
            'geopoliticalHeading' => 'X', 'regionHeading' => null, 'subregionHeading' => null];

        $entries = (new NgaMsiLightMapper())->mapVolume([$row('Erstes.'), $other, $row('Zweites.')]);

        $this->assertSame(['116|100|C1000', '116|104|C1001', '116|100|C1000#2'], array_column($entries, 'source_key'));
    }
}
