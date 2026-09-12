<?php

declare(strict_types=1);

namespace Ytan\Tests\Fixtures;

use Ytan\Service\WeatherHttpClient;

/**
 * Test double for WeatherHttpClient - holds canned responses keyed by
 * which base URL was requested (forecast vs marine), and counts calls so
 * tests can assert cache-hit behavior (the fake was NOT called again).
 * Never makes a real network request.
 */
final class FakeWeatherHttpClient implements WeatherHttpClient
{
    /** @var array<string, array<string, mixed>|null> */
    private array $responses = [];

    /** @var list<string> */
    public array $requestedUrls = [];

    /**
     * @param array<string, mixed>|null $response null simulates a failed
     *        request (network error / non-200 / invalid JSON)
     */
    public function setResponseFor(string $baseUrl, ?array $response): void
    {
        $this->responses[$baseUrl] = $response;
    }

    public function getJson(string $baseUrl, array $query): ?array
    {
        $this->requestedUrls[] = $baseUrl;

        return $this->responses[$baseUrl] ?? null;
    }

    public function callCount(): int
    {
        return count($this->requestedUrls);
    }
}
