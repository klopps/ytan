<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\TranslationMerger;

final class TranslationMergerTest extends TestCase
{
    public function testKeepsExistingValuesAddsNewDropsRemoved(): void
    {
        $merged = TranslationMerger::merge(
            ['a' => 'A neu', 'b' => 'B neu', 'c' => 'C neu'],
            ['a' => 'A prod', 'gone' => 'x', 'c' => 'C prod']
        );

        $this->assertSame(['a' => 'A prod', 'b' => 'B neu', 'c' => 'C prod'], $merged);
    }

    public function testEmptyExistingTakesIncoming(): void
    {
        $this->assertSame(['a' => '1'], TranslationMerger::merge(['a' => '1'], []));
    }

    public function testMergeDirectory(): void
    {
        $base = sys_get_temp_dir() . '/ytan-i18n-' . bin2hex(random_bytes(4));
        mkdir($base . '/in', 0775, true);
        mkdir($base . '/out', 0775, true);
        file_put_contents($base . '/in/de.json', json_encode(['a' => 'neu', 'b' => 'Äpfel']));
        file_put_contents($base . '/in/en.json', json_encode(['a' => 'new']));
        file_put_contents($base . '/out/de.json', json_encode(['a' => 'prod', 'x' => 'weg']));

        $report = TranslationMerger::mergeDirectory($base . '/in', $base . '/out');

        $this->assertSame(['added' => 1, 'removed' => 1, 'kept' => 1], $report['de']);
        $this->assertSame(['a' => 'prod', 'b' => 'Äpfel'], json_decode((string) file_get_contents($base . '/out/de.json'), true));
        $this->assertSame(['a' => 'new'], json_decode((string) file_get_contents($base . '/out/en.json'), true));
        $this->assertDirectoryDoesNotExist($base . '/in');

        foreach (glob($base . '/out/*') as $f) {
            unlink($f);
        }
        rmdir($base . '/out');
        rmdir($base);
    }
}
