<?php

declare(strict_types=1);

namespace Ytan\Tests\Fixtures;

use Ytan\Service\Tide\TideHttp;

/**
 * Test double for TideHttp: answers by the first registered URL prefix that
 * the requested URL starts with (null = a failed request), records every
 * request. Never touches the network.
 */
final class FakeTideHttp implements TideHttp
{
    /** @var array<string, string|null> */
    private array $responses = [];

    /** @var list<array{method: string, url: string, body: ?array, headers?: list<string>}> */
    public array $requests = [];

    public function on(string $urlPrefix, ?string $body): void
    {
        $this->responses[$urlPrefix] = $body;
    }

    public function get(string $url, array $headers = []): ?string
    {
        $this->requests[] = ['method' => 'GET', 'url' => $url, 'body' => null, 'headers' => $headers];

        return $this->answer($url, null);
    }

    public function postJson(string $url, array $body): ?string
    {
        $this->requests[] = ['method' => 'POST', 'url' => $url, 'body' => $body];

        return $this->answer($url, $body);
    }

    private function answer(string $url, ?array $body): ?string
    {
        // a POST to the same URL is told apart by a marker in its body (RWS: catalogue vs. observations)
        $key = $url . ($body !== null && isset($body['Locatie']['Code']) ? '#' . $body['Locatie']['Code'] : '');
        $best = null;
        foreach ($this->responses as $prefix => $response) {
            if (str_starts_with($key, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? null : $this->responses[$best];
    }
}
