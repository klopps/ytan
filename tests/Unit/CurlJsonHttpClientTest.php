<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\CurlJsonHttpClient;

final class CurlJsonHttpClientTest extends TestCase
{
    /** @var list<string> */
    private array $requested = [];
    /** @var list<string> */
    private array $logged = [];
    /** @var array<string, array{status: int, body: ?string, error: string}> host => answer */
    private array $answers = [];
    private int $now = 1_000_000;

    private function client(): CurlJsonHttpClient
    {
        return new CurlJsonHttpClient(
            function (string $url): array {
                $this->requested[] = $url;
                return $this->answers[parse_url($url, PHP_URL_HOST)];
            },
            function (string $line): void {
                $this->logged[] = $line;
            },
            fn () => $this->now,
        );
    }

    public function testDecodesA200Answer(): void
    {
        $this->answers['api.example'] = ['status' => 200, 'body' => '{"a":1}', 'error' => ''];

        $this->assertSame(['a' => 1], $this->client()->getJson('https://api.example/v1/x', ['lat' => 1]));
        $this->assertSame(['https://api.example/v1/x?lat=1'], $this->requested);
        $this->assertSame([], $this->logged);
    }

    public function testNoConnectionIsLoggedWithoutTheQueryAndPausesThatHost(): void
    {
        $this->answers['api.example'] = ['status' => 0, 'body' => null, 'error' => 'Resolving timed out after 4000 milliseconds'];
        $this->answers['other.example'] = ['status' => 200, 'body' => '{}', 'error' => ''];
        $client = $this->client();

        $this->assertNull($client->getJson('https://api.example/v1/forecast', ['latitude' => 54.35, 'apikey' => 'secret']));
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('api.example/v1/forecast failed: no connection (Resolving timed out', $this->logged[0]);
        $this->assertStringNotContainsString('54.35', $this->logged[0]);
        $this->assertStringNotContainsString('secret', $this->logged[0]);

        // same host: answered at once, no request, no second log line
        $this->assertNull($client->getJson('https://api.example/v1/marine', ['latitude' => 55]));
        $this->assertCount(1, $this->requested);
        $this->assertCount(1, $this->logged);
        // other hosts are not affected
        $this->assertSame([], $client->getJson('https://other.example/x', []));
        $this->assertCount(2, $this->requested);

        // after the pause the host is asked again
        $this->now += CurlJsonHttpClient::HOST_PAUSE_SECONDS;
        $this->answers['api.example'] = ['status' => 200, 'body' => '{"ok":true}', 'error' => ''];
        $this->assertSame(['ok' => true], $client->getJson('https://api.example/v1/marine', []));
    }

    public function testServerErrorsAreLoggedButClientErrorsAreNot(): void
    {
        $client = $this->client();
        $this->answers['api.example'] = ['status' => 503, 'body' => 'busy', 'error' => ''];
        $this->assertNull($client->getJson('https://api.example/v1/a', []));
        $this->answers['api.example'] = ['status' => 429, 'body' => 'slow down', 'error' => ''];
        $this->assertNull($client->getJson('https://api.example/v1/b', []));
        $this->answers['api.example'] = ['status' => 404, 'body' => 'none', 'error' => ''];
        $this->assertNull($client->getJson('https://api.example/v1/c', []));

        $this->assertSame(['YTAN HTTP: api.example/v1/a failed: HTTP 503', 'YTAN HTTP: api.example/v1/b failed: HTTP 429'], $this->logged);
        $this->assertCount(3, $this->requested, 'an HTTP error does not pause the host');
    }

    public function testInvalidJsonIsNull(): void
    {
        $this->answers['api.example'] = ['status' => 200, 'body' => '<html>', 'error' => ''];

        $this->assertNull($this->client()->getJson('https://api.example/x', []));
    }
}
