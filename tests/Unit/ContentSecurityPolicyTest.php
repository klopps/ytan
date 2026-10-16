<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Ytan\Http\ContentSecurityPolicy;
use Ytan\Http\Middleware\ContentSecurityPolicyMiddleware;

/**
 * The CSP header goes on HTML responses only, carries this request's nonce
 * (the one the templates put on their <script> tags), allows no inline
 * handlers, and follows .env's CSP_MODE.
 */
final class ContentSecurityPolicyTest extends TestCase
{
    private ?string $previousMode;

    protected function setUp(): void
    {
        $this->previousMode = $_ENV['CSP_MODE'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousMode === null) {
            unset($_ENV['CSP_MODE']);
        } else {
            $_ENV['CSP_MODE'] = $this->previousMode;
        }
    }

    private function respond(string $contentType): ResponseInterface
    {
        $handler = new class ($contentType) implements RequestHandlerInterface {
            public function __construct(private readonly string $type)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse()->withHeader('Content-Type', $this->type);
            }
        };

        return (new ContentSecurityPolicyMiddleware('/ytan/public'))
            ->process((new ServerRequestFactory())->createServerRequest('GET', '/'), $handler);
    }

    public function testHtmlResponseGetsEnforcedPolicyWithThisRequestsNonce(): void
    {
        unset($_ENV['CSP_MODE']);
        $policy = $this->respond('text/html; charset=utf-8')->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("'nonce-" . ContentSecurityPolicy::nonce() . "'", $policy);
        $this->assertStringContainsString("'strict-dynamic'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString('report-uri /ytan/public/api/v1/csp-report', $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
    }

    public function testJsonResponseGetsNoPolicy(): void
    {
        $this->assertFalse($this->respond('application/json')->hasHeader('Content-Security-Policy'));
    }

    public function testReportModeUsesReportOnlyHeader(): void
    {
        $_ENV['CSP_MODE'] = 'report';
        $response = $this->respond('text/html');

        $this->assertFalse($response->hasHeader('Content-Security-Policy'));
        $this->assertTrue($response->hasHeader('Content-Security-Policy-Report-Only'));
    }

    public function testOffModeSendsNothing(): void
    {
        $_ENV['CSP_MODE'] = 'off';
        $response = $this->respond('text/html');

        $this->assertFalse($response->hasHeader('Content-Security-Policy'));
        $this->assertFalse($response->hasHeader('Content-Security-Policy-Report-Only'));
    }

    public function testNonceIsStableWithinARequestAndAttributeShaped(): void
    {
        $this->assertSame(ContentSecurityPolicy::nonce(), ContentSecurityPolicy::nonce());
        $this->assertSame(' nonce="' . ContentSecurityPolicy::nonce() . '"', ContentSecurityPolicy::nonceAttr());
        $this->assertGreaterThanOrEqual(24, strlen(ContentSecurityPolicy::nonce()));
    }
}
