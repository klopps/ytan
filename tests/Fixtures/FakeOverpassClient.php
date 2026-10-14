<?php

declare(strict_types=1);

namespace Ytan\Tests\Fixtures;

use Ytan\Exception\OverpassException;
use Ytan\Service\Overpass\OverpassClient;

/**
 * Test double for OverpassClient: answers with a canned decoded JSON answer
 * (or fails like an overloaded server) and remembers the queries it got.
 * Never makes a network request.
 */
final class FakeOverpassClient implements OverpassClient
{
    /** @var list<string> */
    public array $queries = [];

    /** @param array<string,mixed>|null $answer null = simulate a failure */
    public function __construct(private ?array $answer = ['elements' => []])
    {
    }

    /** @param array<string,mixed>|null $answer */
    public function willAnswer(?array $answer): void
    {
        $this->answer = $answer;
    }

    public function query(string $query): array
    {
        $this->queries[] = $query;
        if ($this->answer === null) {
            throw new OverpassException('Overpass API not available (fake: HTTP 504).');
        }

        return $this->answer;
    }
}
