<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\WindDataService;

final class WindDataServiceTest extends TestCase
{
    private const RUN = '202610182132';

    private string $dir;
    private WindDataService $service;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-wind-test-' . uniqid();
        mkdir($this->dir . '/runs/' . self::RUN . '/L1/000', 0775, true);
        mkdir($this->dir . '/runs/' . self::RUN . '/L0', 0775, true);
        file_put_contents($this->dir . '/manifest.json', json_encode(['run' => self::RUN, 'hours' => ['2026-10-18T21:00Z']]));
        file_put_contents($this->dir . '/runs/' . self::RUN . '/L1/000/23_18.bin.gz', 'block-bytes');
        file_put_contents($this->dir . '/runs/' . self::RUN . '/L0/000.bin.gz', 'overview-bytes');
        file_put_contents($this->dir . '/secret.bin.gz', 'must never be served');
        $this->service = new WindDataService($this->dir);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    public function testManifestIsReturned(): void
    {
        $this->assertSame(self::RUN, $this->service->manifest()['run']);
    }

    public function testNoManifestYetIsNull(): void
    {
        $this->assertNull((new WindDataService($this->dir . '/nowhere'))->manifest());
    }

    public function testBlockAndOverviewBytes(): void
    {
        $this->assertSame('block-bytes', $this->service->block(self::RUN, 'L1', 0, '23_18'));
        $this->assertSame('overview-bytes', $this->service->block(self::RUN, 'L0', 0, null));
    }

    public function testUnknownBlockRunOrHourIs404(): void
    {
        foreach ([[self::RUN, 'L1', 0, '99_99'], [self::RUN, 'L1', 1, '23_18'], ['202610182133', 'L1', 0, '23_18'], [self::RUN, 'L2', 0, '23_18']] as $args) {
            try {
                $this->service->block(...$args);
                $this->fail('expected 404 for ' . json_encode($args));
            } catch (NotFoundException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }

    /**
     * @dataProvider invalidBlocks
     */
    public function testInvalidPartsAreRejected(string $run, string $level, int $hour, ?string $block): void
    {
        $this->expectException(ValidationException::class);
        $this->service->block($run, $level, $hour, $block);
    }

    public static function invalidBlocks(): array
    {
        return [
            'traversal in block' => [self::RUN, 'L1', 0, '../../../secret'],
            'traversal in run' => ['../../../../etc', 'L1', 0, '23_18'],
            'short run' => ['2026101821', 'L1', 0, '23_18'],
            'unknown level' => [self::RUN, 'L3', 0, '23_18'],
            'level with path' => [self::RUN, 'L1/../..', 0, '23_18'],
            'L0 with a block' => [self::RUN, 'L0', 0, '23_18'],
            'L1 without a block' => [self::RUN, 'L1', 0, null],
            'block not numbers' => [self::RUN, 'L1', 0, 'ab_cd'],
            'negative hour' => [self::RUN, 'L1', -1, '23_18'],
            'huge hour' => [self::RUN, 'L1', 1000, '23_18'],
        ];
    }

    private function rmrf(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) as $f) {
                if ($f !== '.' && $f !== '..') {
                    $this->rmrf($path . '/' . $f);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
