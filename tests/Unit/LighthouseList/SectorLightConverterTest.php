<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit\LighthouseList;

use PHPUnit\Framework\TestCase;
use Ytan\Service\LighthouseList\SectorLightConverter;

/**
 * The two spellings of the official lists (todo.md's "Einlesen der Dänischen
 * Leuchtfeuer") must both end up in YTAN's "<colour> <range>M <start>-<end>".
 */
final class SectorLightConverterTest extends TestCase
{
    private const RANGES = ['G' => 6.5, 'W' => 8.0, 'R' => 5.0];

    public function testFirstFormatWithDecimalCommas(): void
    {
        $result = SectorLightConverter::convert('G350,7°-352,2° W352,2°-354,2° R359°-20°', self::RANGES);

        $this->assertSame("G 6.5M 350.7-352.2\nW 8M 352.2-354.2\nR 5M 359-20", $result['text']);
        $this->assertFalse($result['missingRange']);
    }

    public function testSecondFormatWithMinutesAndMissingStart(): void
    {
        // "350°42" = 350°42' = 350.7°; the "W. -354,2°" starts where the previous sector ended (352°).
        $result = SectorLightConverter::convert('G. 350°42-352° W. -354,2°, R.359°-20°', self::RANGES);

        $this->assertSame("G 6.5M 350.7-352\nW 8M 352-354.2\nR 5M 359-20", $result['text']);
    }

    public function testSectorSplitOverTwoLinesOfTheRemarksColumn(): void
    {
        $result = SectorLightConverter::convert('G327°-331° W331°-333,5° R333,5°-342° G066,5°- 073° W073°-077°.', self::RANGES);

        $this->assertSame(
            "G 6.5M 327-331\nW 8M 331-333.5\nR 5M 333.5-342\nG 6.5M 66.5-73\nW 8M 73-77",
            $result['text']
        );
    }

    public function testOrdinaryRemarksAreNoSectors(): void
    {
        $result = SectorLightConverter::convert('Brændetid: H24. Nr. 32a og 32b er overet i pejling 053,8°. N-siden af molen.', self::RANGES);

        $this->assertNull($result['text']);
    }

    public function testColoursTheOverlayCannotDrawAreLeftOut(): void
    {
        $result = SectorLightConverter::convert('Y10°-20° W20°-30°', self::RANGES + ['Y' => 3.0]);

        $this->assertSame('W 8M 20-30', $result['text']);
    }

    public function testASectorWithoutAKnownRangeGetsThePlaceholderAndIsReported(): void
    {
        $result = SectorLightConverter::convert('G10°-20° W20°-30°', ['G' => 6.0, 'R' => 4.0]);

        $this->assertSame("G 6M 10-20\nW ?M 20-30", $result['text']);
        $this->assertTrue($result['missingRange']);
    }

    public function testASingleListedRangeStandsForEveryColour(): void
    {
        $result = SectorLightConverter::convert('R203°-119°', ['W' => 7.0]);

        $this->assertSame('R 7M 203-119', $result['text']);
        $this->assertFalse($result['missingRange']);
    }

    public function testNoRangeAtAllGivesThePlaceholder(): void
    {
        $result = SectorLightConverter::convert('R203°-119°', []);

        $this->assertSame('R ?M 203-119', $result['text']);
        $this->assertTrue($result['missingRange']);
    }

    public function testDefaultRangesReplaceOnlyThePlaceholders(): void
    {
        $result = SectorLightConverter::applyDefaultRanges(
            "G ?M 10-20\nW 7M 20-30\nW ?M 30-40\nR ?M 40-50",
            ['W' => 3.0, 'R' => 2.0, 'G' => 1.5]
        );

        $this->assertSame("G 1.5M 10-20\nW 7M 20-30\nW 3M 30-40\nR 2M 40-50", $result['text']);
        $this->assertTrue($result['applied']);
    }

    public function testNothingIsAppliedWhenEverySectorHasARange(): void
    {
        $result = SectorLightConverter::applyDefaultRanges("G 6M 10-20\nW 7M 20-30", ['W' => 3.0, 'R' => 2.0, 'G' => 2.0]);

        $this->assertSame("G 6M 10-20\nW 7M 20-30", $result['text']);
        $this->assertFalse($result['applied']);
    }
}
