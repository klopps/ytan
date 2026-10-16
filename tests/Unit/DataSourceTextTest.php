<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\DataSourceText;
use Ytan\Service\Translator;

final class DataSourceTextTest extends TestCase
{
    private const DK = [
        'code' => 'dk',
        'name' => 'Dansk Fyrliste',
        'url' => 'https://www.soefartsstyrelsen.dk/',
        'license' => 'DB license',
        'license_url' => null,
        'attribution' => 'DB attribution',
    ];

    public function testUsesTheTranslationOfTheCurrentLocale(): void
    {
        $en = new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'en');
        $de = new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'de');

        $this->assertSame('Published by the Danish Maritime Authority, no explicit licence', DataSourceText::localize(self::DK, $en->t(...))['license']);
        $this->assertSame('Veröffentlicht von der Søfartsstyrelsen, keine ausdrückliche Lizenz', DataSourceText::localize(self::DK, $de->t(...))['license']);
        $this->assertSame('© OpenStreetMap contributors', DataSourceText::localize(['code' => 'osm', 'attribution' => 'x'], $en->t(...))['attribution']);
    }

    public function testKeepsTheTableValueWithoutATranslation(): void
    {
        $noTranslations = fn (string $key) => $key;

        $this->assertSame(self::DK, DataSourceText::localize(self::DK, $noTranslations));
        // name and url are never translated; an unknown source stays as it is.
        $en = new Translator(dirname(__DIR__, 2) . '/resources/i18n', 'en');
        $this->assertSame('Dansk Fyrliste', DataSourceText::localize(self::DK, $en->t(...))['name']);
        $other = ['code' => 'xx', 'license' => 'L', 'attribution' => 'A'];
        $this->assertSame($other, DataSourceText::localize($other, $en->t(...)));
    }
}
