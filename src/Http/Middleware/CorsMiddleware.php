<?php

declare(strict_types=1);

namespace Ytan\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Server\MiddlewareInterface;

final class CorsMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private readonly array $allowedOrigins;

    public function __construct(string $allowedOriginsCsv)
    {
        $this->allowedOrigins = array_map('trim', explode(',', $allowedOriginsCsv));
    }

    public function process(Request $request, Handler $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');
        $response = $handler->handle($request);

        $allow = in_array('*', $this->allowedOrigins, true) || in_array($origin, $this->allowedOrigins, true);

        if ($allow && $origin !== '') {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Vary', 'Origin')
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-App-Version')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                // Non-"simple" response headers are invisible to JS in a
                // cross-origin fetch() unless explicitly exposed here - see
                // AppVersionMiddleware, whose X-App-Update-Required header
                // api-client.js reads on every response.
                ->withHeader('Access-Control-Expose-Headers', 'X-App-Update-Required');
        }

        return $response;
    }
}
