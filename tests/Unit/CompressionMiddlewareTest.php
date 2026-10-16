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

/**
 * gzip only for clients that accept it, text types, bodies from 1 KB, not
 * twice and not for the Garmin watch endpoint.
 */
final class CompressionMiddlewareTest extends TestCase
{
    private function compress(string $path, string $type, string $body, string $acceptEncoding = 'gzip, deflate, br', array $headers = []): ResponseInterface
    {
        $handler = new class ($type, $body, $headers) implements RequestHandlerInterface {
            public function __construct(private readonly string $type, private readonly string $body, private readonly array $headers)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new ResponseFactory())->createResponse()->withHeader('Content-Type', $this->type);
                foreach ($this->headers as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }
                $response->getBody()->write($this->body);

                return $response;
            }
        };
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        if ($acceptEncoding !== '') {
            $request = $request->withHeader('Accept-Encoding', $acceptEncoding);
        }

        return (new CompressionMiddleware())->process($request, $handler);
    }

    public function testLargeJsonIsGzipped(): void
    {
        $json = json_encode(['data' => array_fill(0, 200, ['name' => 'Leuchtfeuer', 'lat' => 54.1])]);
        $response = $this->compress('/api/v1/pois', 'application/json', $json);

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        $this->assertStringContainsString('Accept-Encoding', $response->getHeaderLine('Vary'));
        $this->assertSame($json, gzdecode((string) $response->getBody()));
        $this->assertLessThan(strlen($json), strlen((string) $response->getBody()));
    }

    public function testNotCompressedWithoutAcceptEncoding(): void
    {
        $response = $this->compress('/api/v1/pois', 'application/json', str_repeat('x', 5000), '');

        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }

    public function testSmallBodiesAndBinaryTypesStayAsTheyAre(): void
    {
        $this->assertFalse($this->compress('/api/v1/health', 'application/json', '{"status":"ok"}')->hasHeader('Content-Encoding'));
        $this->assertFalse($this->compress('/api/v1/tours/1/document', 'application/pdf', str_repeat('x', 5000))->hasHeader('Content-Encoding'));
    }

    public function testAlreadyEncodedResponseIsNotCompressedAgain(): void
    {
        $response = $this->compress('/x', 'text/html', str_repeat('x', 5000), 'gzip', ['Content-Encoding' => 'br']);

        $this->assertSame('br', $response->getHeaderLine('Content-Encoding'));
        $this->assertSame(str_repeat('x', 5000), (string) $response->getBody());
    }

    public function testWatchDeviceEndpointIsLeftAlone(): void
    {
        $response = $this->compress('/ytan/public/api/v1/watch/device/route', 'application/json', str_repeat('1,', 3000));

        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }
}
