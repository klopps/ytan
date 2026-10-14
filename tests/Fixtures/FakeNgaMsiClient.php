<?php

declare(strict_types=1);

namespace Ytan\Tests\Fixtures;

use Ytan\Exception\NgaMsiException;
use Ytan\Service\NgaMsi\NgaMsiClient;

/**
 * Test double for NgaMsiClient: answers with canned rows (or fails like an
 * unreachable service) and remembers which volumes were asked for. Never
 * makes a network request.
 */
final class FakeNgaMsiClient implements NgaMsiClient
{
    /** @var list<int> */
    public array $volumes = [];

    /** @param list<array<string,mixed>>|null $rows null = simulate a failure */
    public function __construct(private ?array $rows = [])
    {
    }

    /** @param list<array<string,mixed>>|null $rows */
    public function willAnswer(?array $rows): void
    {
        $this->rows = $rows;
    }

    public function fetchVolume(int $volume): array
    {
        $this->volumes[] = $volume;
        if ($this->rows === null) {
            throw new NgaMsiException('NGA Maritime Safety Information not available (fake: HTTP 503).');
        }

        return $this->rows;
    }
}
