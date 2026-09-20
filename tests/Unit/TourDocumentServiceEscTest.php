<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Ytan\Service\TourDocumentService;

/**
 * Some POI/area names imported from the old PESR system already contain a
 * literal HTML entity baked into the stored name itself (confirmed live
 * against the poi table, e.g. "Noor &amp; Lara Livs") rather than the real
 * character - see todo.md "Touren-Dokument: HTMLSpecialchars". esc() is
 * private static, tested via Reflection the same way MailServiceEncodingTest
 * reaches MailService::buildMailer().
 */
final class TourDocumentServiceEscTest extends TestCase
{
    private function esc(string $value): string
    {
        $method = new ReflectionMethod(TourDocumentService::class, 'esc');

        return $method->invoke(null, $value);
    }

    public function testEscapesAnOrdinaryNameOnce(): void
    {
        $this->assertSame('Noor &amp; Lara Livs', $this->esc('Noor & Lara Livs'));
        $this->assertSame('Say &quot;hi&quot;', $this->esc('Say "hi"'));
    }

    public function testNormalizesALegacyAlreadyEscapedNameInsteadOfDoubleEscapingIt(): void
    {
        // Without the html_entity_decode() step, this would come out as
        // 'Noor &amp;amp; Lara Livs' - which dompdf's HTML parser only
        // unwraps by one level, leaving the literal text "&amp;" visible in
        // the PDF instead of a real ampersand.
        $this->assertSame('Noor &amp; Lara Livs', $this->esc('Noor &amp; Lara Livs'));
        $this->assertSame('Say &quot;hi&quot;', $this->esc('Say &quot;hi&quot;'));
        $this->assertSame('Årsta Handlar&#039;n', $this->esc('Årsta Handlar&#039;n'));
    }
}
