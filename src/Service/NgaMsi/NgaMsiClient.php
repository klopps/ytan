<?php

declare(strict_types=1);

namespace Ytan\Service\NgaMsi;

/**
 * Injection seam for the one outbound call of the NGA lighthouse import - one
 * volume of the List of Lights from the Maritime Safety Information web
 * service - so tests never touch the network (same idea as JsonHttpClient
 * and OverpassClient).
 */
interface NgaMsiClient
{
    /**
     * @param int $volume 110-116 (NGA Pub. 110-116)
     * @return list<array<string,mixed>> the volume's rows of the list ("ngalol" in the answer)
     * @throws \Ytan\Exception\NgaMsiException when the service doesn't deliver usable JSON
     */
    public function fetchVolume(int $volume): array;
}
