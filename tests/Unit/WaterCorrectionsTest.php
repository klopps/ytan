<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Water\WaterCorrections;
use Ytan\Service\Water\WaterDistanceService;

/**
 * The admin's corrections on top of tests/testdata/water-index-sample.bin
 * (see WaterDistanceServiceTest: coast 100 m north of 56.0 N / 9.0 E from
 * 8.99 to 9.01 E, a river 300 m east drawn south to north).
 */
final class WaterCorrectionsTest extends TestCase
{
    private const INDEX = __DIR__ . '/../testdata/water-index-sample.bin';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /** @param list<array<string, mixed>> $corrections */
    private function service(array $corrections): WaterDistanceService
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'wc');
        $this->files[] = $file;
        file_put_contents($file, json_encode(['version' => 1, 'corrections' => $corrections]));

        return new WaterDistanceService(self::INDEX, null, $file);
    }

    /** A square around the coast segment (but not the river 300 m east). */
    private const AROUND_COAST = [[55.999, 8.98], [56.003, 8.98], [56.003, 9.003], [55.999, 9.003]];

    public function testWithoutAFileNothingChanges(): void
    {
        $plain = new WaterDistanceService(self::INDEX);
        $this->assertEqualsWithDelta(100, $plain->distances(56.0, 9.0)['sea'], 1);
    }

    public function testDeleteRemovesTheSegmentsOfItsKindInsideThePolygon(): void
    {
        // the coast is one long segment 8.99-9.01 - its midpoint 9.0 lies inside
        $service = $this->service([['type' => 'delete', 'kinds' => ['sea'], 'points' => self::AROUND_COAST]]);
        $distances = $service->distances(56.0, 9.0);

        $this->assertNull($distances['sea']);
        $this->assertEqualsWithDelta(300, $distances['river'], 2, 'other kinds stay');
    }

    public function testDeleteOfAnotherKindLeavesTheCoast(): void
    {
        $service = $this->service([['type' => 'delete', 'kinds' => ['river'], 'points' => self::AROUND_COAST]]);
        $this->assertEqualsWithDelta(100, $service->distances(56.0, 9.0)['sea'], 1);
    }

    public function testFlipReversesTheSegment(): void
    {
        $plain = array_values(array_filter((new WaterDistanceService(self::INDEX))->segmentsInBox(55.98, 8.98, 56.02, 9.05), static fn (array $s): bool => $s[0] === 0));
        $flipped = array_values(array_filter(
            $this->service([['type' => 'flip', 'kinds' => ['sea'], 'points' => self::AROUND_COAST]])->segmentsInBox(55.98, 8.98, 56.02, 9.05),
            static fn (array $s): bool => $s[0] === 0
        ));

        $this->assertCount(1, $flipped);
        $this->assertSame([0, $plain[0][3], $plain[0][4], $plain[0][1], $plain[0][2]], $flipped[0]);
    }

    public function testAddedLineIsFoundAcrossCells(): void
    {
        // 50 m north, from 8.9 to 9.1 E: crosses several 0.05 degree cells
        $lat = 56.0 + 50 / 111195;
        $service = $this->service([['type' => 'add', 'kinds' => ['sea'], 'points' => [[$lat, 8.9], [$lat, 9.0], [$lat, 9.1]]]]);

        $this->assertEqualsWithDelta(50, $service->distances(56.0, 9.0)['sea'], 1);
        $this->assertEqualsWithDelta(50, $service->distances(56.0, 9.07)['sea'], 1);
        $sea = array_filter($service->segmentsInBox(55.98, 8.85, 56.02, 9.15), static fn (array $s): bool => $s[0] === 0);
        $this->assertCount(3, $sea, 'coast + 2 added segments, each once');
    }

    public function testCorrectionsApplyInOrder(): void
    {
        $lat = 56.0 + 50 / 111195;
        $add = ['type' => 'add', 'kinds' => ['sea'], 'points' => [[$lat, 8.995], [$lat, 9.0]]];
        $delete = ['type' => 'delete', 'kinds' => ['sea'], 'points' => self::AROUND_COAST];

        $this->assertNull($this->service([$add, $delete])->distances(56.0, 9.0)['sea'], 'a later delete removes the added line');
        $this->assertEqualsWithDelta(50, $this->service([$delete, $add])->distances(56.0, 9.0)['sea'], 1, 'a later add survives');
    }

    /** The coast segment of the fixture as the page would send it as a replaced line's original. */
    private function coastOriginal(float $offsetMeters = 0.0): array
    {
        $plain = array_values(array_filter((new WaterDistanceService(self::INDEX))->segmentsInBox(55.98, 8.98, 56.02, 9.05), static fn (array $s): bool => $s[0] === 0));
        $shift = $offsetMeters / 111320;

        return [[$plain[0][1] + $shift, $plain[0][2]], [$plain[0][3] + $shift, $plain[0][4]]];
    }

    public function testReplaceTakesTheOldLineOutAndPutsTheNewOneIn(): void
    {
        $lat = 56.0 + 50 / 111195;
        $service = $this->service([[
            'type' => 'replace', 'kinds' => ['sea'],
            'original' => $this->coastOriginal(),
            'points' => [[$lat, 8.99], [$lat, 9.01]],
        ]]);

        $this->assertEqualsWithDelta(50, $service->distances(56.0, 9.0)['sea'], 1);
        $sea = array_filter($service->segmentsInBox(55.98, 8.98, 56.02, 9.05), static fn (array $s): bool => $s[0] === 0);
        $this->assertCount(1, $sea, 'only the new line, the old one is gone');
        $this->assertEqualsWithDelta(300, $service->distances(56.0, 9.0)['river'], 2, 'other kinds stay');
    }

    public function testReplaceWithoutNewLineDeletesTheLine(): void
    {
        $service = $this->service([['type' => 'replace', 'kinds' => ['sea'], 'original' => $this->coastOriginal(), 'points' => []]]);
        $this->assertNull($service->distances(56.0, 9.0)['sea']);

        $overlay = $service->overlaySegments(55.98, 8.98, 56.02, 9.05, false, 1000);
        $this->assertCount(1, $overlay['removed']);
    }

    public function testReplaceToleratesASlightlyMovedLineButNotAnotherOne(): void
    {
        // a rebuild may move the segment a few metres - still taken out
        $near = $this->service([['type' => 'replace', 'kinds' => ['sea'], 'original' => $this->coastOriginal(6.0), 'points' => []]]);
        $this->assertNull($near->distances(56.0, 9.0)['sea']);

        // 30 m away is another line - stays
        $far = $this->service([['type' => 'replace', 'kinds' => ['sea'], 'original' => $this->coastOriginal(30.0), 'points' => []]]);
        $this->assertEqualsWithDelta(100, $far->distances(56.0, 9.0)['sea'], 1);
    }

    public function testReplaceNeedsAnOriginalAndNoSinglePoint(): void
    {
        $this->assertTrue((new WaterCorrections([
            ['type' => 'replace', 'kinds' => ['sea'], 'points' => [[56.0, 9.0], [56.1, 9.0]]],
            ['type' => 'replace', 'kinds' => ['sea'], 'original' => $this->coastOriginal(), 'points' => [[56.0, 9.0]]],
            ['type' => 'replace', 'kinds' => ['sea', 'lake'], 'original' => $this->coastOriginal(), 'points' => []],
        ]))->isEmpty());
    }

    public function testOverlayMarksSegmentsFromCorrectionsAsOwn(): void
    {
        $lat = 56.0 + 50 / 111195;
        $service = $this->service([['type' => 'add', 'kinds' => ['sea'], 'points' => [[$lat, 8.99], [$lat, 9.01]]]]);
        $sea = array_values(array_filter(
            $service->overlaySegments(55.98, 8.98, 56.02, 9.05, false, 1000)['segments'],
            static fn (array $s): bool => $s[0] === 'sea'
        ));

        $this->assertCount(2, $sea);
        $own = array_values(array_filter($sea, static fn (array $s): bool => $s[5] !== 0));
        $this->assertCount(1, $own, 'only the added line is own, the coast of the index is not');
        $this->assertEqualsWithDelta($lat, $own[0][1], 1e-9);

        $raw = $service->overlaySegments(55.98, 8.98, 56.02, 9.05, true, 1000)['segments'];
        $this->assertSame([0], array_values(array_unique(array_column($raw, 5))), 'raw = only the index');
    }

    public function testOwnSegmentsCarryTheIdOfTheirCorrection(): void
    {
        $lat = 56.0 + 50 / 111195;
        $service = $this->service([
            ['id' => 7, 'type' => 'add', 'kinds' => ['sea'], 'points' => [[$lat, 8.99], [$lat, 9.01]]],
            ['id' => 9, 'type' => 'flip', 'kinds' => ['sea'], 'points' => self::AROUND_COAST],
        ]);
        $own = [];
        foreach ($service->overlaySegments(55.98, 8.98, 56.02, 9.05, false, 1000)['segments'] as $s) {
            if ($s[0] === 'sea') {
                $own[] = $s[5];
            }
        }
        sort($own);

        // the coast flipped by #9 is changed but drawn by no correction (-1);
        // the added line keeps #7 although #9 flipped it too (its midpoint is inside)
        $this->assertSame([-1, 7], $own);
    }

    public function testInvalidCorrectionsAreIgnored(): void
    {
        $corrections = new WaterCorrections([
            ['type' => 'delete', 'kinds' => ['sea'], 'points' => [[56.0, 9.0], [56.1, 9.0]]],
            ['type' => 'add', 'kinds' => ['sea', 'lake'], 'points' => [[56.0, 9.0], [56.1, 9.0]]],
            ['type' => 'move', 'kinds' => ['sea'], 'points' => self::AROUND_COAST],
            ['type' => 'flip', 'kinds' => ['ocean'], 'points' => self::AROUND_COAST],
        ]);
        $this->assertTrue($corrections->isEmpty());
    }

    public function testOverlayShowsRemovedSegmentsAndTheRawIndex(): void
    {
        $service = $this->service([['type' => 'delete', 'kinds' => ['sea'], 'points' => self::AROUND_COAST]]);

        $corrected = $service->overlaySegments(55.98, 8.98, 56.02, 9.05, false, 1000);
        $this->assertSame([], array_values(array_filter($corrected['segments'], static fn (array $s): bool => $s[0] === 'sea')));
        $this->assertCount(1, $corrected['removed']);
        $this->assertSame('sea', $corrected['removed'][0][0]);
        $this->assertFalse($corrected['truncated']);

        $raw = $service->overlaySegments(55.98, 8.98, 56.02, 9.05, true, 1000);
        $this->assertCount(1, array_filter($raw['segments'], static fn (array $s): bool => $s[0] === 'sea'));
        $this->assertSame([], $raw['removed']);

        $this->assertTrue($service->overlaySegments(55.98, 8.98, 56.4, 9.05, true, 2)['truncated']);
    }
}
