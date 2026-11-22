<?php

declare(strict_types=1);

namespace Ytan\Tests\Fixtures;

use Ytan\Exception\NationalLightsException;
use Ytan\Service\NationalLights\NationalLightsClient;

/**
 * Test double for NationalLightsClient: answers with canned rows (or fails like an unreachable service) and
 * remembers what was asked for. Never makes a network request.
 */
final class FakeNationalLightsClient implements NationalLightsClient
{
    /** @var list<string> */
    public array $calls = [];
    public bool $fail = false;

    /**
     * @param array<string,list<array<string,mixed>>> $finland aids, lights, sectors
     * @param array<string,list<array<string,mixed>>> $norway lights, sectors
     */
    public function __construct(public array $finland = [], public array $norway = [])
    {
    }

    public function fetchFinland(): array
    {
        $this->calls[] = 'fi';
        $this->failIfAsked();

        return $this->finland + ['aids' => [], 'lights' => [], 'sectors' => []];
    }

    public function fetchNorway(): array
    {
        $this->calls[] = 'no';
        $this->failIfAsked();

        return $this->norway + ['lights' => [], 'sectors' => []];
    }

    private function failIfAsked(): void
    {
        if ($this->fail) {
            throw new NationalLightsException('The lights service is not available (fake: HTTP 503).');
        }
    }
}
