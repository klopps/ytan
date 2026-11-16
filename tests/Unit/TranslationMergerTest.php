<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\TranslationMerger;

final class TranslationMergerTest extends TestCase
{
    public function testWithoutBaselineTheDeployedTextsWinNewKeysArriveRemovedOnesGo(): void
    {
        $result = TranslationMerger::merge(
            ['a' => 'A neu', 'b' => 'B neu', 'c' => 'C'],
            ['a' => 'A prod', 'gone' => 'x', 'c' => 'C']
        );

        $this->assertSame(['a' => 'A neu', 'b' => 'B neu', 'c' => 'C'], $result['merged']);
        $this->assertSame(['a'], $result['updated']);
        $this->assertSame([], $result['conflicts']);
    }

    public function testATextChangedInTheRepositoryReachesTheServer(): void
    {
        $result = TranslationMerger::merge(['a' => 'neu'], ['a' => 'alt'], ['a' => 'alt']);

        $this->assertSame(['a' => 'neu'], $result['merged']);
        $this->assertSame(['a'], $result['updated']);
    }

    public function testAServerEditStaysWhileTheRepositoryLeavesTheTextAlone(): void
    {
        $result = TranslationMerger::merge(['a' => 'Original'], ['a' => 'auf Prod verbessert'], ['a' => 'Original']);

        $this->assertSame(['a' => 'auf Prod verbessert'], $result['merged']);
        $this->assertSame(['a'], $result['kept_server']);
        $this->assertSame([], $result['updated']);
    }

    public function testChangedOnBothSidesTheRepositoryWinsAndItIsReported(): void
    {
        $result = TranslationMerger::merge(['a' => 'Repo neu'], ['a' => 'Prod neu'], ['a' => 'Original']);

        $this->assertSame(['a' => 'Repo neu'], $result['merged']);
        $this->assertSame(['a'], $result['conflicts']);
    }

    public function testEmptyExistingTakesIncoming(): void
    {
        $this->assertSame(['a' => '1'], TranslationMerger::merge(['a' => '1'], [])['merged']);
    }

    public function testMergeDirectoryWritesTheBaselineForTheNextDeploy(): void
    {
        $base = sys_get_temp_dir() . '/ytan-i18n-' . bin2hex(random_bytes(4));
        mkdir($base . '/in', 0775, true);
        mkdir($base . '/out', 0775, true);
        file_put_contents($base . '/in/de.json', json_encode(['a' => 'neu', 'b' => 'Äpfel']));
        file_put_contents($base . '/in/en.json', json_encode(['a' => 'new']));
        file_put_contents($base . '/out/de.json', json_encode(['a' => 'prod', 'x' => 'weg']));

        $report = TranslationMerger::mergeDirectory($base . '/in', $base . '/out', $base . '/baseline');

        $this->assertSame(['added' => 1, 'removed' => 1, 'updated' => 1, 'kept_server' => 0, 'conflicts' => []], $report['de']);
        $this->assertSame(['a' => 'neu', 'b' => 'Äpfel'], json_decode((string) file_get_contents($base . '/out/de.json'), true));
        $this->assertSame(['a' => 'new'], json_decode((string) file_get_contents($base . '/out/en.json'), true));
        $this->assertSame(['a' => 'neu', 'b' => 'Äpfel'], json_decode((string) file_get_contents($base . '/baseline/de.json'), true));
        $this->assertDirectoryDoesNotExist($base . '/in');

        // next deploy: the server edited "b", the repository changed "a"
        file_put_contents($base . '/out/de.json', json_encode(['a' => 'neu', 'b' => 'Äpfel (Prod)']));
        mkdir($base . '/in');
        file_put_contents($base . '/in/de.json', json_encode(['a' => 'neuer', 'b' => 'Äpfel']));

        $report = TranslationMerger::mergeDirectory($base . '/in', $base . '/out', $base . '/baseline');

        $this->assertSame(['added' => 0, 'removed' => 0, 'updated' => 1, 'kept_server' => 1, 'conflicts' => []], $report['de']);
        $this->assertSame(['a' => 'neuer', 'b' => 'Äpfel (Prod)'], json_decode((string) file_get_contents($base . '/out/de.json'), true));

        foreach (['out', 'baseline'] as $dir) {
            foreach (glob($base . '/' . $dir . '/*') as $f) {
                unlink($f);
            }
            rmdir($base . '/' . $dir);
        }
        rmdir($base);
    }
}
