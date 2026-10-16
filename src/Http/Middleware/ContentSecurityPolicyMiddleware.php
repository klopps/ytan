<?php

declare(strict_types=1);

namespace Ytan\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Ytan\Http\ContentSecurityPolicy;

/**
 * Adds the Content-Security-Policy (ContentSecurityPolicy) to every HTML
 * response - only those: the nonce must match the <script> tags rendered in
 * this same request, so it can't come from .htaccess.
 */
final class ContentSecurityPolicyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $baseUrl)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        $header = ContentSecurityPolicy::headerName();
        if ($header === null || !str_starts_with($response->getHeaderLine('Content-Type'), 'text/html')) {
            return $response;
        }

        return $response->withHeader($header, ContentSecurityPolicy::policy($this->baseUrl . '/api/v1/csp-report'));
    }
}
