<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Ytan\Service\MailService;

/**
 * German umlauts/ß rendered incorrectly in sent emails - PHPMailer defaults
 * CharSet to iso-8859-1, but this app's subjects/bodies are UTF-8 (see
 * MailService::buildMailer()'s own comment). buildMailer() stops just
 * short of an actual network send, so these tests call it via Reflection
 * (it's private, and MailService is final so it can't be subclassed) and
 * inspect the composed PHPMailer instance - preSend()/getSentMIMEMessage()
 * build the MIME message in memory without ever touching the network,
 * unlike send()/postSend().
 */
final class MailServiceEncodingTest extends TestCase
{
    private MailService $mailService;

    protected function setUp(): void
    {
        $this->mailService = new MailService('', 587, '', '', 'test@example.test', '', 'YTAN Test');
    }

    private function buildMailer(string $toEmail, string $subject, string $bodyText): PHPMailer
    {
        // No setAccessible() call needed - PHP 8.1+ lets Reflection invoke
        // private/protected methods directly.
        $method = new ReflectionMethod(MailService::class, 'buildMailer');

        return $method->invoke($this->mailService, $toEmail, $subject, $bodyText);
    }

    public function testCharSetIsUtf8(): void
    {
        $mail = $this->buildMailer('to@example.test', 'Subject', 'Body');

        $this->assertSame(PHPMailer::CHARSET_UTF8, $mail->CharSet);
    }

    public function testUmlautsInBodyAreEncodedAsUtf8NotMisreadAsIso88591(): void
    {
        $mail = $this->buildMailer(
            'to@example.test',
            'Ändert sich Ihre Route?',
            "Die Route \"Über den Bodensee - Straße am Ufer\" wurde geändert."
        );

        $mail->preSend();
        $raw = $mail->getSentMIMEMessage();

        $this->assertStringContainsString('charset=utf-8', $raw);
        // quoted-printable representation of the UTF-8 bytes for "ä"
        // (0xC3 0xA4) - if CharSet/Encoding were still left at PHPMailer's
        // iso-8859-1/8bit defaults, this exact byte sequence would not
        // appear (the raw UTF-8 bytes would either pass through unencoded
        // under the wrong charset label, or be mangled by an iso-8859-1
        // quoted-printable pass instead).
        $this->assertStringContainsString('=C3=84ndert', $raw); // "Ändert" (subject, RFC 2047 encoded-word)
        $this->assertStringContainsString('=C3=9Cber', $raw); // "Über" (body)
        $this->assertStringContainsString('Stra=C3=9Fe', $raw); // "Straße" (body)
    }
}
