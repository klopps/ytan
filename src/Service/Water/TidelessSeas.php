<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

use Ytan\Service\LighthousePolygon;

/**
 * The seas of Europe with (almost) no tide - the Baltic with the Kattegat and
 * the Skagerrak, the Mediterranean and the Black Sea - as rough polygons
 * ([lat, lng] rings). Routencheck: a river section near the sea counts as
 * tidal only when that sea is NOT one of them (the Elbe below Geesthacht, the
 * Weser, the Ems, the Seine or the Thames yes, the Warnow or the Trave no).
 *
 * Only coast points are ever tested, so the borders run through land (Jutland,
 * Schleswig-Holstein between the Elbe estuary and the Baltic coast, Anatolia)
 * or across open sea where the tide is small anyway (west of Lindesnes,
 * Strait of Gibraltar). Measured with Open-Meteo's sea level (2026-11-14): the
 * Baltic coasts at Rostock, Lübeck and Eckernförde about 0.4 m in two days
 * (wind, not tide), Ems mouth 2.2 m, Seine 5.5 m, Thames 3.4 m. Asking
 * Open-Meteo per route instead does not work: in the estuaries (Elbe at Wedel
 * and Glückstadt, Gudenå at Randers) it answers no sea level at all.
 */
final class TidelessSeas
{
    /** @var list<list<array{0: float, 1: float}>> */
    private const POLYGONS = [
        // Baltic, Danish straits, Kattegat, Skagerrak (Norway's south coast to
        // Lindesnes, Jutland's north-west coast to Thyborøn and the Limfjord)
        [
            [58.10, 6.90], [57.05, 8.40], [56.60, 8.15], [56.00, 9.00], [55.00, 9.25], [54.60, 9.25],
            [54.20, 9.60], [53.95, 10.00], [53.70, 10.80], [53.60, 12.00], [53.30, 14.50], [53.50, 20.00],
            [54.20, 22.00], [55.00, 27.00], [60.00, 31.00], [66.50, 26.00], [66.50, 18.00], [61.00, 15.00],
            [60.20, 10.00], [59.00, 6.90],
        ],
        // Mediterranean (east of Gibraltar), Sea of Marmara and Black Sea
        [
            [35.00, -5.62], [36.20, -5.62], [37.50, -4.00], [41.00, 0.00], [42.60, 1.50], [43.50, 2.50],
            [44.50, 5.00], [44.80, 8.50], [45.00, 11.50], [46.00, 12.00], [46.00, 14.00], [45.00, 15.50],
            [42.00, 19.50], [40.00, 21.00], [41.20, 23.00], [41.40, 26.50], [41.50, 27.50], [46.50, 28.00],
            [47.00, 32.00], [47.50, 39.50], [41.00, 42.00], [41.00, 28.50], [38.00, 30.00], [36.00, 36.50],
            [31.00, 35.00], [30.00, 30.00], [31.00, 10.00], [35.00, -2.00],
        ],
    ];

    public static function contains(float $lat, float $lng): bool
    {
        foreach (self::POLYGONS as $polygon) {
            if (LighthousePolygon::contains($polygon, $lat, $lng)) {
                return true;
            }
        }

        return false;
    }
}
