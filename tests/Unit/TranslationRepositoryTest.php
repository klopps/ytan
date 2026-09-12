<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Exception\TranslationKeyMismatchException;
use Ytan\Exception\ValidationException;
use Ytan\Service\TranslationRepository;

final class TranslationRepositoryTest extends TestCase
{
    private string $resourcesDir;
    private TranslationRepository $repository;

    protected function setUp(): void
    {
        $this->resourcesDir = sys_get_temp_dir() . '/ytan-translation-repo-test-' . uniqid();
        mkdir($this->resourcesDir);
        file_put_contents($this->resourcesDir . '/en.json', json_encode([
            'a' => 'Alpha',
            'b' => 'Bravo',
        ]));
        file_put_contents($this->resourcesDir . '/de.json', json_encode([
            'a' => 'Alpha (DE)',
            'b' => 'Bravo (DE)',
        ]));
        $this->repository = new TranslationRepository($this->resourcesDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->resourcesDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->resourcesDir);
    }

    public function testLoadReturnsTheDecodedMap(): void
    {
        $this->assertSame(['a' => 'Alpha', 'b' => 'Bravo'], $this->repository->load('en'));
    }

    public function testLoadOfAMissingLocaleReturnsAnEmptyMap(): void
    {
        $this->assertSame([], $this->repository->load('fr'));
    }

    public function testSaveRoundTripsBothLocales(): void
    {
        $this->repository->save(
            ['a' => 'Alpha!', 'b' => 'Bravo'],
            ['a' => 'Alpha! (DE)', 'b' => 'Bravo (DE)']
        );

        $this->assertSame(['a' => 'Alpha!', 'b' => 'Bravo'], $this->repository->load('en'));
        $this->assertSame(['a' => 'Alpha! (DE)', 'b' => 'Bravo (DE)'], $this->repository->load('de'));
    }

    public function testSavePreservesExistingKeyOrderAndAppendsNewKeys(): void
    {
        $this->repository->save(
            ['b' => 'Bravo', 'a' => 'Alpha', 'c' => 'Charlie'],
            ['b' => 'Bravo (DE)', 'a' => 'Alpha (DE)', 'c' => 'Charlie (DE)'],
            true
        );

        // load() re-reads from disk via json_decode, which preserves the
        // JSON object's own key order - assert the written file's order
        // directly rather than just the (order-blind) array contents.
        $decoded = json_decode((string) file_get_contents($this->resourcesDir . '/en.json'), true);
        $this->assertSame(['b', 'a', 'c'], array_keys($decoded));
    }

    public function testSaveWithMismatchedKeySetsThrowsAndWritesNeitherFile(): void
    {
        $originalEn = file_get_contents($this->resourcesDir . '/en.json');
        $originalDe = file_get_contents($this->resourcesDir . '/de.json');

        try {
            $this->repository->save(
                ['a' => 'Alpha', 'b' => 'Bravo', 'extra' => 'Extra'],
                ['a' => 'Alpha (DE)', 'b' => 'Bravo (DE)']
            );
            $this->fail('Expected TranslationKeyMismatchException.');
        } catch (TranslationKeyMismatchException $e) {
            $this->assertSame(['extra'], $e->getPayload()['only_in_en']);
            $this->assertSame([], $e->getPayload()['only_in_de']);
        }

        $this->assertSame($originalEn, file_get_contents($this->resourcesDir . '/en.json'));
        $this->assertSame($originalDe, file_get_contents($this->resourcesDir . '/de.json'));
    }

    public function testSaveWithMismatchedKeySetsAndForceSucceeds(): void
    {
        $this->repository->save(
            ['a' => 'Alpha', 'b' => 'Bravo', 'extra' => 'Extra'],
            ['a' => 'Alpha (DE)', 'b' => 'Bravo (DE)'],
            true
        );

        $this->assertSame(['a' => 'Alpha', 'b' => 'Bravo', 'extra' => 'Extra'], $this->repository->load('en'));
    }

    public function testSaveRejectsAValueContainingAScriptTagBreakout(): void
    {
        $originalEn = file_get_contents($this->resourcesDir . '/en.json');

        $this->expectException(ValidationException::class);
        try {
            $this->repository->save(
                ['a' => 'Alpha</script><script>alert(1)</script>', 'b' => 'Bravo'],
                ['a' => 'Alpha (DE)', 'b' => 'Bravo (DE)']
            );
        } finally {
            $this->assertSame($originalEn, file_get_contents($this->resourcesDir . '/en.json'));
        }
    }

    public function testDiffKeysReportsBothDirections(): void
    {
        $diff = $this->repository->diffKeys(
            ['a' => '1', 'only_en' => '1'],
            ['a' => '1', 'only_de' => '1']
        );

        $this->assertSame(['only_en'], $diff['only_in_en']);
        $this->assertSame(['only_de'], $diff['only_in_de']);
    }
}
