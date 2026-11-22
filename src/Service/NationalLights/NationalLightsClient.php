<?php

declare(strict_types=1);

namespace Ytan\Service\NationalLights;

/**
 * Injection seam for the outbound calls of the Finnish and Norwegian lights
 * import, so tests never touch the network (same idea as NgaMsiClient). Every
 * list holds flat associative rows: the attributes of a feature, and for
 * lights the position as `lat`/`lon` (WGS84 degrees).
 */
interface NationalLightsClient
{
    /**
     * Väylävirasto's open WFS: the lighthouse-type navigation aids (names), all their lights, all light sectors.
     *
     * @return array{aids: list<array<string,mixed>>, lights: list<array<string,mixed>>, sectors: list<array<string,mixed>>}
     * @throws \Ytan\Exception\NationalLightsException when the service doesn't deliver usable data
     */
    public function fetchFinland(): array;

    /**
     * Kystverket's navigation installations WFS: the lights of the classes FS (lighthouse station) and FL
     * (sector/fairway light) and every light sector.
     *
     * @return array{lights: list<array<string,mixed>>, sectors: list<array<string,mixed>>}
     * @throws \Ytan\Exception\NationalLightsException when the service doesn't deliver usable data
     */
    public function fetchNorway(): array;
}
