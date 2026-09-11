<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Translator;

final class TranslatorTest extends TestCase
{
    private string $resourcesDir;

    protected function setUp(): void
    {
        $this->resourcesDir = sys_get_temp_dir() . '/ytan-translator-test-' . uniqid();
        mkdir($this->resourcesDir);
        file_put_contents($this->resourcesDir . '/en.json', json_encode([
            'greeting' => 'Hello {name}',
            'only_in_english' => 'English only',
        ]));
        file_put_contents($this->resourcesDir . '/de.json', json_encode([
            'greeting' => 'Hallo {name}',
        ]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->resourcesDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->resourcesDir);
    }

    public function testTranslatesAKeyInTheActiveLocale(): void
    {
        $translator = new Translator($this->resourcesDir, 'de');

        $this->assertSame('Hallo Welt', $translator->t('greeting', ['name' => 'Welt']));
    }

    public function testFallsBackToTheFallbackLocaleForAMissingKey(): void
    {
        $translator = new Translator($this->resourcesDir, 'de');

        $this->assertSame('English only', $translator->t('only_in_english'));
    }

    public function testFallsBackToTheKeyItselfWhenMissingEverywhere(): void
    {
        $translator = new Translator($this->resourcesDir, 'de');

        $this->assertSame('no.such.key', $translator->t('no.such.key'));
    }

    public function testAnUnknownLocaleFallsBackEntirelyToEnglish(): void
    {
        $translator = new Translator($this->resourcesDir, 'fr');

        $this->assertSame('Hello Welt', $translator->t('greeting', ['name' => 'Welt']));
    }

    public function testLocaleReturnsTheConstructedLocale(): void
    {
        $translator = new Translator($this->resourcesDir, 'de');

        $this->assertSame('de', $translator->locale());
    }

    public function testAllReturnsOnlyTheActiveLocalesOwnStrings(): void
    {
        $translator = new Translator($this->resourcesDir, 'de');

        $this->assertSame(['greeting' => 'Hallo {name}'], $translator->all());
    }

    public function testResolveLocalePrefersTheStoredSettingsCookieOverAcceptLanguage(): void
    {
        $resolved = Translator::resolveLocale(
            ['en', 'de'],
            json_encode(['language' => 'de']),
            'en-US,en;q=0.9'
        );

        $this->assertSame('de', $resolved);
    }

    public function testResolveLocaleIgnoresAnUnsupportedStoredLanguage(): void
    {
        $resolved = Translator::resolveLocale(
            ['en', 'de'],
            json_encode(['language' => 'fr']),
            'de-DE,de;q=0.9'
        );

        $this->assertSame('de', $resolved);
    }

    public function testResolveLocaleFallsBackToAcceptLanguageWhenNoCookie(): void
    {
        $resolved = Translator::resolveLocale(['en', 'de'], null, 'de-DE,de;q=0.9,en;q=0.8');

        $this->assertSame('de', $resolved);
    }

    public function testResolveLocaleFallsBackToTheDefaultWhenNothingMatches(): void
    {
        $resolved = Translator::resolveLocale(['en', 'de'], null, 'fr-FR,fr;q=0.9');

        $this->assertSame('en', $resolved);
    }

    public function testResolveLocaleIgnoresAMalformedSettingsCookie(): void
    {
        $resolved = Translator::resolveLocale(['en', 'de'], 'not-json{', 'de-DE');

        $this->assertSame('de', $resolved);
    }
}
