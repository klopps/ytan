<?php

declare(strict_types=1);

namespace Ytan\Tests\Fixtures;

use Ytan\Service\Water\ShoreSource;

/** An in-memory coast for the autorouting tests. */
final class FakeShoreSource implements ShoreSource
{
    /** @param list<array{0: int, 1: float, 2: float, 3: float, 4: float}> $segments */
    public function __construct(private readonly array $segments, private readonly bool $covered = true, private readonly bool $available = true)
    {
    }

    /**
     * A land block as a shore: counter-clockwise, so the water (right of the
     * direction of travel) is outside.
     */
    public static function island(float $south, float $west, float $north, float $east): self
    {
        return new self(self::ring($south, $west, $north, $east));
    }

    /** @return list<array{0: int, 1: float, 2: float, 3: float, 4: float}> */
    public static function ring(float $south, float $west, float $north, float $east): array
    {
        return [
            [0, $south, $west, $south, $east],
            [0, $south, $east, $north, $east],
            [0, $north, $east, $north, $west],
            [0, $north, $west, $south, $west],
        ];
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function covers(float $lat, float $lng): bool
    {
        return $this->covered;
    }

    public function segmentsInBox(float $south, float $west, float $north, float $east): array
    {
        return array_values(array_filter(
            $this->segments,
            static fn (array $s): bool => max($s[1], $s[3]) >= $south && min($s[1], $s[3]) <= $north
                && max($s[2], $s[4]) >= $west && min($s[2], $s[4]) <= $east
        ));
    }
}
