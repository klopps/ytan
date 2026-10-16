<?php

declare(strict_types=1);

namespace Ytan\Http;

/**
 * Content-Security-Policy of every HTML page (security audit 2026-10-16) -
 * the second line of defence against XSS: even if markup gets injected
 * somewhere, the browser runs no script that the server didn't hand out.
 *
 * - script-src: a fresh nonce per request plus 'strict-dynamic'. Every
 *   <script> tag in the templates carries the nonce (nonceAttr()); scripts
 *   those load themselves (Google Maps' loader) are trusted through
 *   'strict-dynamic'. No inline event handlers (onclick=...) - markup uses
 *   data-on-click (public/js/csp-events.js). 'https:' and 'unsafe-inline'
 *   are ignored by browsers that understand nonces/'strict-dynamic' and are
 *   only the fallback for very old ones (Google's "strict CSP" recipe).
 * - style-src keeps 'unsafe-inline': style="..." attributes are everywhere
 *   and Google Maps injects inline styles; CSS can't run script.
 * - img/connect/frame/worker: own server plus Google Maps (tiles, Places
 *   autocomplete, blob workers); photos are blob: URLs (fetchBlob()).
 *
 * .env's CSP_MODE: "enforce" (default), "report" (Report-Only header -
 * nothing is blocked, violations go to POST /api/v1/csp-report and the
 * PHP error log) or "off". Lets a regression be switched off on a server
 * without a deploy.
 */
final class ContentSecurityPolicy
{
    private static ?string $nonce = null;

    /** The nonce of this request (one per PHP request). */
    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(18));
    }

    /** ' nonce="..."' for a <script> tag in a template. */
    public static function nonceAttr(): string
    {
        return ' nonce="' . self::nonce() . '"';
    }

    public static function mode(): string
    {
        $mode = strtolower(trim((string) ($_ENV['CSP_MODE'] ?? 'enforce')));

        return in_array($mode, ['enforce', 'report', 'off'], true) ? $mode : 'enforce';
    }

    /** Header name for the current mode, null when off. */
    public static function headerName(): ?string
    {
        return match (self::mode()) {
            'enforce' => 'Content-Security-Policy',
            'report' => 'Content-Security-Policy-Report-Only',
            default => null,
        };
    }

    public static function policy(string $reportUri): string
    {
        $google = 'https://*.googleapis.com https://*.gstatic.com https://*.google.com';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'nonce-" . self::nonce() . "' 'strict-dynamic' https: 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https://fonts.gstatic.com",
            "connect-src 'self' $google",
            "frame-src 'self' https://*.google.com",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            'report-uri ' . $reportUri,
        ]);
    }
}
