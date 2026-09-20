<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Ytan\Exception\AppUpdateRequiredException;
use Ytan\Http\Middleware\AppVersionMiddleware;

final class AppVersionMiddlewareTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }

    private function request(string $method, ?string $appVersion = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/v1/pois');

        return $appVersion !== null ? $request->withHeader('X-App-Version', $appVersion) : $request;
    }

    public function testDoesNothingWhenTheMinimumIsDisabled(): void
    {
        $middleware = new AppVersionMiddleware(0);

        $response = $middleware->process($this->request('POST', '1'), $this->handler());

        $this->assertFalse($response->hasHeader('X-App-Update-Required'));
    }

    public function testAllowsAndDoesNotTagARequestWithNoVersionHeader(): void
    {
        // A plain browser tab never sends X-App-Version at all - treated as
        // compatible unconditionally, same as capacitor-bridge.js's own
        // fail-open convention for an undeterminable native capability.
        $middleware = new AppVersionMiddleware(100);

        $response = $middleware->process($this->request('POST'), $this->handler());

        $this->assertFalse($response->hasHeader('X-App-Update-Required'));
    }

    public function testAllowsAnUpToDateAppAndDoesNotTagTheResponse(): void
    {
        $middleware = new AppVersionMiddleware(100);

        $response = $middleware->process($this->request('POST', '100'), $this->handler());

        $this->assertFalse($response->hasHeader('X-App-Update-Required'));
    }

    public function testTagsButDoesNotBlockAGetRequestFromAnOutdatedApp(): void
    {
        $middleware = new AppVersionMiddleware(100);

        $response = $middleware->process($this->request('GET', '50'), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('1', $response->getHeaderLine('X-App-Update-Required'));
    }

    public function testBlocksAPostRequestFromAnOutdatedApp(): void
    {
        $middleware = new AppVersionMiddleware(100);

        $this->expectException(AppUpdateRequiredException::class);

        $middleware->process($this->request('POST', '50'), $this->handler());
    }

    public function testBlocksPutAndDeleteRequestsFromAnOutdatedAppToo(): void
    {
        $middleware = new AppVersionMiddleware(100);

        foreach (['PUT', 'DELETE'] as $method) {
            try {
                $middleware->process($this->request($method, '50'), $this->handler());
                $this->fail("Expected AppUpdateRequiredException for {$method}");
            } catch (AppUpdateRequiredException $e) {
                $this->assertSame(426, $e->getStatusCode());
            }
        }
    }
}
