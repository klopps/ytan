<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Ytan\Http\Middleware\CompressionMiddleware;
use Ytan\Http\Middleware\ETagMiddleware;

/**
 * ETag from the body for the list endpoints, 304 on a matching
 * If-None-Match, nothing for other paths/methods/statuses.
 */
final class ETagMiddlewareTest extends TestCase
{
    private function handler(string $body, int $status = 200, array $headers = []): RequestHandlerInterface
    {
        return new class ($body, $status, $headers) implements RequestHandlerInterface {
            public function __construct(private readonly string $body, private readonly int $status, private readonly array $headers)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new ResponseFactory())->createResponse($this->status)->withHeader('Content-Type', 'application/json');
                foreach ($this->headers as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }
                $response->getBody()->write($this->body);

                return $response;
            }
        };
    }

    private function send(string $path, string $body, string $ifNoneMatch = '', string $method = 'GET', int $status = 200, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($ifNoneMatch !== '') {
            $request = $request->withHeader('If-None-Match', $ifNoneMatch);
        }

        return (new ETagMiddleware())->process($request, $this->handler($body, $status, $headers));
    }

    public function testListGetsATagAndRevalidationHeaders(): void
    {
        $response = $this->send('/api/v1/pois', '{"data":[1,2,3]}');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertMatchesRegularExpression('#^W/"[0-9a-f]{32}"$#', $response->getHeaderLine('ETag'));
        $this->assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('Authorization', $response->getHeaderLine('Vary'));
        $this->assertSame('{"data":[1,2,3]}', (string) $response->getBody());
    }

    public function testMatchingIfNoneMatchAnswers304WithoutBody(): void
    {
        $etag = $this->send('/ytan/public/api/v1/routes', '{"data":[]}')->getHeaderLine('ETag');

        $response = $this->send('/ytan/public/api/v1/routes', '{"data":[]}', $etag);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame($etag, $response->getHeaderLine('ETag'));
    }

    public function testChangedBodyGetsANewTag(): void
    {
        $old = $this->send('/api/v1/areas', '{"data":[1]}')->getHeaderLine('ETag');

        $response = $this->send('/api/v1/areas', '{"data":[2]}', $old);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame($old, $response->getHeaderLine('ETag'));
    }

    public function testGzipSuffixAndStrongFormAndListsMatch(): void
    {
        $etag = $this->send('/api/v1/tours', '{"data":[]}')->getHeaderLine('ETag');
        $opaque = substr($etag, 3, -1);

        $this->assertTrue(ETagMiddleware::matches('"' . $opaque . '-gzip"', $etag));
        $this->assertTrue(ETagMiddleware::matches('"other", W/"' . $opaque . '"', $etag));
        $this->assertTrue(ETagMiddleware::matches('*', $etag));
        $this->assertFalse(ETagMiddleware::matches('"other"', $etag));
        $this->assertFalse(ETagMiddleware::matches('', $etag));
    }

    public function testOtherPathsMethodsAndStatusesAreLeftAlone(): void
    {
        $this->assertFalse($this->send('/api/v1/pois/12', '{}')->hasHeader('ETag'));
        $this->assertFalse($this->send('/api/v1/pois/bounds', '{}')->hasHeader('ETag'));
        $this->assertFalse($this->send('/api/v1/pois', '{}', '', 'POST')->hasHeader('ETag'));
        $this->assertFalse($this->send('/api/v1/pois', '{}', '', 'GET', 401)->hasHeader('ETag'));
        $this->assertSame('no-store', $this->send('/api/v1/pois', '{}', '', 'GET', 200, ['Cache-Control' => 'no-store'])->getHeaderLine('Cache-Control'));
    }

    public function testA304IsNotCompressedAroundIt(): void
    {
        $body = json_encode(['data' => array_fill(0, 200, ['name' => 'x'])]);
        $etag = $this->send('/api/v1/pois', $body)->getHeaderLine('ETag');
        $inner = new class ($this->handler($body)) implements RequestHandlerInterface {
            public function __construct(private readonly RequestHandlerInterface $next)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ETagMiddleware())->process($request, $this->next);
            }
        };
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/pois')
            ->withHeader('Accept-Encoding', 'gzip')
            ->withHeader('If-None-Match', $etag);

        $response = (new CompressionMiddleware())->process($request, $inner);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }
}
