<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Content-Security-Policy blocks inline event handlers and <script>
 * tags without the request's nonce - the browser then silently does
 * nothing. Catches both before they reach a browser: handlers belong in
 * data-on-<event>="fn(...)" (public/js/csp-events.js), script tags need
 * <?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?>.
 */
final class NoInlineScriptTest extends TestCase
{
    /** @return list<string> */
    private static function files(): array
    {
        $root = dirname(__DIR__, 2);

        return array_merge(
            glob($root . '/public/js/*.js'),
            glob($root . '/templates/*.php'),
            glob($root . '/templates/partials/*.php'),
        );
    }

    public function testNoInlineEventHandlerAttributes(): void
    {
        $found = [];
        foreach (self::files() as $file) {
            foreach (file($file) as $no => $line) {
                // onclick="..." / onClick=\'...\' as markup - not el.onclick = fn.
                if (preg_match('/(?<![\w-])on[a-z]+=\\\\?["\']/i', $line)) {
                    $found[] = basename($file) . ':' . ($no + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame([], $found, 'Use data-on-<event>="..." instead (csp-events.js).');
    }

    public function testEveryScriptTagInTemplatesHasTheNonce(): void
    {
        $found = [];
        foreach (self::files() as $file) {
            if (!str_ends_with($file, '.php') || basename($file) === 'sw.php') {
                continue;
            }
            foreach (file($file) as $no => $line) {
                if (preg_match('/^\s*<script(?=[\s>])/', $line) && !str_contains($line, 'ContentSecurityPolicy::nonceAttr()')) {
                    $found[] = basename($file) . ':' . ($no + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function testNoJavascriptUrls(): void
    {
        $found = [];
        foreach (self::files() as $file) {
            foreach (file($file) as $no => $line) {
                if (preg_match('/(href|src|action)\s*=\s*\\\\?["\']\s*javascript:/i', $line)) {
                    $found[] = basename($file) . ':' . ($no + 1);
                }
            }
        }

        $this->assertSame([], $found);
    }
}
