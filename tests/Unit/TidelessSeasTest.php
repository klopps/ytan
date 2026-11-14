<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Water\TidelessSeas;

/**
 * Coast points as WaterDistanceService::nearestSea() returns them for real
 * rivers (measured on the dev box's water index, 2026-11-14) plus a few coasts
 * near the polygons' borders.
 */
final class TidelessSeasTest extends TestCase
{
    /** @return array<string, array{0: float, 1: float}> */
    public static function tidal(): array
    {
        return [
            'Elbe at Wedel (Hamburg, Geesthacht)' => [53.581, 9.658],
            'Elbe at Brunsbüttel' => [53.89, 9.14],
            'Weser in Bremen' => [53.075, 8.80],
            'Ems near Leer' => [53.266, 7.232],
            'Eider at Tönning' => [54.32, 8.95],
            'Husum' => [54.48, 9.05],
            'Esbjerg' => [55.47, 8.45],
            'Seine below Rouen' => [49.896, 0.878],
            'Thames estuary' => [51.499, 0.503],
            'Biarritz (Atlantic)' => [43.48, -1.56],
            'Cádiz (Atlantic)' => [36.53, -6.30],
            'Bergen' => [60.39, 5.32],
        ];
    }

    /** @return array<string, array{0: float, 1: float}> */
    public static function tideless(): array
    {
        return [
            'Warnow (Rostock)' => [54.178, 12.107],
            'Trave (Lübeck)' => [53.994, 10.795],
            'Eckernförde (Rendsburg)' => [54.449, 9.860],
            'Flensburg' => [54.80, 9.43],
            'Wismar' => [53.90, 11.45],
            'Szczecin lagoon' => [53.75, 14.20],
            'Gudenå at Randers' => [56.459, 10.042],
            'Aarhus' => [56.15, 10.22],
            'Gothenburg' => [57.70, 11.90],
            'Oslofjord' => [59.90, 10.70],
            'Stockholm' => [59.32, 18.07],
            'Gulf of Bothnia (Oulu)' => [65.01, 25.47],
            'Marseille' => [43.30, 5.37],
            'Genoa' => [44.40, 8.93],
            'Venice' => [45.43, 12.33],
            'Athens' => [37.94, 23.64],
            'Odesa (Black Sea)' => [46.48, 30.75],
        ];
    }

    /** @dataProvider tidal */
    public function testTidalCoasts(float $lat, float $lng): void
    {
        $this->assertFalse(TidelessSeas::contains($lat, $lng));
    }

    /** @dataProvider tideless */
    public function testTidelessCoasts(float $lat, float $lng): void
    {
        $this->assertTrue(TidelessSeas::contains($lat, $lng));
    }
}
