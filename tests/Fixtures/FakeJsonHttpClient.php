<?php

declare(strict_types=1);

namespace Ytan\Tests\Fixtures;

use Ytan\Service\JsonHttpClient;

/**
 * Test double for JsonHttpClient - holds canned responses keyed by which
 * base URL was requested, and counts calls so tests can assert cache-hit
 * behavior (the fake was NOT called again). Never makes a real network
 * request. Shared between WeatherService and GeocodingService tests since
 * both depend on the same generic interface.
 */
final class FakeJsonHttpClient implements JsonHttpClient
{
    /** @var array<string, array<string, mixed>|null> */
    private array $responses = [];

    /** @var list<string> */
    public array $requestedUrls = [];

    /** @var list<array<string, scalar>> parallel to $requestedUrls */
    public array $requestedQueries = [];

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
        $this->requestedQueries[] = $query;

        return $this->responses[$baseUrl] ?? null;
    }

    public function callCount(): int
    {
        return count($this->requestedUrls);
    }
}
