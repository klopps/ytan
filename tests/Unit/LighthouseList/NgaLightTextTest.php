<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit\LighthouseList;

use PHPUnit\Framework\TestCase;
use Ytan\Service\LighthouseList\NgaLightText;

/**
 * The NGA List of Lights' text conventions shared by the PDF parser and the
 * MSI web service mapper.
 */
final class NgaLightTextTest extends TestCase
{
    public function testCharacteristicIsCompactedWithColoursAndPeriod(): void
    {
        $this->assertSame(['Iso WRG 2s', ''], NgaLightText::characteristic(['Iso.W.R.G.', 'period 2s']));
    }

    public function testPeriodGluedToTheCodeAndFurtherLines(): void
    {
        $this->assertSame(
            ['Iso W 10s', 'fl. 1s, ec. 9s'],
            NgaLightText::characteristic(['Iso.W.period 10s', 'fl. 1s, ec. 9s'])
        );
    }

    public function testGroupCharacteristicKeepsItsBrackets(): void
    {
        $this->assertSame(['Fl(2) W 6s', ''], NgaLightText::characteristic(['Fl.(2)W.', 'period 6s']));
    }

    public function testVerticalSuffixStaysBehindTheColours(): void
    {
        [$characteristic] = NgaLightText::characteristic(['F.R.(vert)']);

        $this->assertSame('F R (vert)', $characteristic);
    }

    public function testEmptyLinesAreIgnored(): void
    {
        $this->assertSame(['Q G', ''], NgaLightText::characteristic(['', 'Q.G.', '  ']));
    }

    public function testRacons(): void
    {
        $this->assertTrue(NgaLightText::isRacon('Svinnas RACON', ''));
        $this->assertTrue(NgaLightText::isRacon('Buoy', 'M( - - )'));
        $this->assertTrue(NgaLightText::isRacon('Buoy', 'K(–•–)'));
        $this->assertFalse(NgaLightText::isRacon('Svinnas', 'Fl(2) W 6s'));
    }

    public function testDashIndentedNamesGetTheirParentsStem(): void
    {
        $entries = NgaLightText::completeSiblingNames([
            ['name' => 'Bojden Ferry Harbor, N. mole, head.'],
            ['name' => '-S. mole, head.'],
            ['name' => 'Svinnas Range.'],
            ['name' => '-Front.'],
            ['name' => '--Rear, 120 meters 230° from front.'],
            ['name' => 'Kattholmen.'],
        ]);

        $this->assertSame([
            'Bojden Ferry Harbor, N. mole, head.',
            'Bojden Ferry Harbor, S. mole, head',
            'Svinnas Range.',
            'Svinnas Range, Front',
            'Front, Rear, 120 meters 230° from front',
            'Kattholmen.',
        ], array_column($entries, 'name'));
    }

    public function testDashedNameWithoutParentKeepsItsText(): void
    {
        $this->assertSame('Rear', NgaLightText::completeSiblingNames([['name' => '-Rear.']])[0]['name']);
    }
}
