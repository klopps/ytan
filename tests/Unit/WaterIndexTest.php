<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Water\WaterIndex;

/**
 * The binary water index read directly (WaterDistanceServiceTest goes through
 * the service): header checks, kinds, segments inside/outside the grid.
 * Fixture: tests/testdata/water-index-sample.bin, made by
 * tools/water/make_test_fixture.py - regenerate it when the format changes.
 */
final class WaterIndexTest extends TestCase
{
    private const SAMPLE = __DIR__ . '/../testdata/water-index-sample.bin';
    private const SAMPLE_3_KINDS = __DIR__ . '/../testdata/water-index-sample-v3kinds.bin';

    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
    }

    private function tempFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ytan-water');
        file_put_contents($path, $bytes);
        $this->tmp[] = $path;

        return $path;
    }

    public function testMissingOrForeignFilesAreNoIndex(): void
    {
        $this->assertNull(WaterIndex::open(sys_get_temp_dir() . '/no-such-water-index.bin'));
        $this->assertNull(WaterIndex::open($this->tempFile('short')));
        $this->assertNull(WaterIndex::open($this->tempFile(str_repeat("\0", 64))), 'wrong magic');
    }

    public function testOtherFormatVersionIsRefused(): void
    {
        $header = file_get_contents(self::SAMPLE, false, null, 0, 64);
        $patched = substr($header, 0, 8) . pack('V', 99) . substr($header, 12);

        $this->assertNull(WaterIndex::open($this->tempFile($patched)));
    }

    public function testSampleHasAllKinds(): void
    {
        $index = WaterIndex::open(self::SAMPLE);

        $this->assertNotNull($index);
        foreach (WaterIndex::KINDS as $kind) {
            $this->assertTrue($index->hasKind($kind), $kind);
        }
        $this->assertFalse($index->hasKind('ocean'));
        $this->assertGreaterThan(0, $index->cellDegrees());
    }

    public function testAnOlderFileWithoutCanalsIsStillReadable(): void
    {
        $index = WaterIndex::open(self::SAMPLE_3_KINDS);

        $this->assertNotNull($index);
        $this->assertTrue($index->hasKind('lake'));
        $this->assertFalse($index->hasKind('canal'));
        $this->assertSame([], $index->segments(3, 0, 0, 0), 'a kind the file does not have is empty');
    }

    public function testSegmentsAreDegreesInsideTheirCell(): void
    {
        $index = WaterIndex::open(self::SAMPLE);
        $cell = $index->cellDegrees();

        // find any non-empty cell of the sea kind by scanning a generous range
        $found = null;
        for ($row = -2000; $row <= 2000 && $found === null; $row += 1) {
            $segments = $index->segments(0, $row, -4000, 4000);
            if ($segments !== []) {
                $found = [$row, $segments];
            }
        }
        $this->assertNotNull($found, 'the sample has sea segments');
        [$row, $segments] = $found;
        foreach ($segments as [$lat1, $lng1, $lat2, $lng2]) {
            foreach ([$lat1, $lat2] as $lat) {
                $this->assertGreaterThanOrEqual(-90, $lat);
                $this->assertLessThanOrEqual(90, $lat);
            }
            // a segment is filed under the cells it touches: it reaches into the row's latitude band
            $this->assertLessThanOrEqual(($row + 1) * $cell + 1e-6, min($lat1, $lat2), 'starts below the row\'s top');
            $this->assertGreaterThanOrEqual($row * $cell - 1e-6, max($lat1, $lat2), 'ends above the row\'s bottom');
            $this->assertIsFloat($lng1);
            $this->assertIsFloat($lng2);
        }
        $this->assertSame([], $index->segments(0, 999999, 0, 10), 'rows outside the grid are empty');
    }
}
