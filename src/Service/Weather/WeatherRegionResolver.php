<?php

declare(strict_types=1);

namespace Ytan\Service\Weather;

use Ytan\Service\GeocodingService;

/**
 * Maps a coordinate to which WeatherProviderInterface (or, for the five
 * countries in COUNTRY_MODELS below, which Open-Meteo `models=` parameter)
 * WeatherService should use for its general (non-marine, non-sun-times)
 * forecast - the todo item's "gesonderte Datenquellen für bestimmte Länder/
 * Regionen". SMHI (Sweden + international Baltic waters) is the only
 * country needing a genuinely separate WeatherProviderInterface
 * implementation - DWD/DMI/MET Norway/Météo-France/KNMI turned out to
 * already be reachable through Open-Meteo's own API (confirmed live: each
 * model parameter below returns a complete, gap-free 168-hour series on
 * its own, so none of these five need WeatherService::fillHourlyGaps()
 * either - unlike SMHI's real point-forecast API, Open-Meteo already blends
 * every "_seamless" model with ECMWF internally to cover the full week).
 * Only Finland/FMI (WFS/XML, its own API key, no Open-Meteo equivalent) is
 * still a later, separate step - see todo.md.
 *
 * Country lookup goes through GeocodingService (already doing reverse
 * geocoding for the weather panel's place-name title - see its own doc
 * comment for why this reuses that call/cache instead of a second one). A
 * country code alone can't express "international Baltic waters" though -
 * open sea resolves to no country at all - so a coordinate that Nominatim
 * can't attribute to any country falls back to a rough Baltic Sea bounding
 * box before finally defaulting to Open-Meteo. Deliberately just a
 * bounding box, not an exact sea-basin polygon, for this first pass - see
 * BALTIC_BOUNDS below.
 */
final class WeatherRegionResolver
{
    // Lowercase ISO 3166-1 alpha-2 (matches GeocodingService::
    // resolveCountryCode()'s own Nominatim-derived casing) -> the
    // Open-Meteo `models=` value + display source label for that country's
    // own national service. Each "_seamless" variant blends that provider's
    // own regional model with ECMWF automatically once its native
    // resolution/range runs out - confirmed live for all five (full
    // 168-hour series, no API key, same api.open-meteo.com/v1/forecast
    // endpoint WeatherProviderInterface's own Open-Meteo path already
    // uses). Extend this map for future countries the todo item lists
    // (only Finland/FMI is left, and it needs its own real provider, not a
    // models= entry here).
    private const COUNTRY_MODELS = [
        'de' => ['model' => 'icon_seamless', 'source' => 'DWD'],
        'dk' => ['model' => 'dmi_seamless', 'source' => 'DMI'],
        'no' => ['model' => 'metno_seamless', 'source' => 'MET Norway'],
        'fr' => ['model' => 'meteofrance_seamless', 'source' => 'Météo-France'],
        'nl' => ['model' => 'knmi_seamless', 'source' => 'KNMI'],
    ];

    // Rough Baltic Sea basin envelope - covers everything from the Danish
    // straits/Kattegat up through the Gulf of Bothnia and Gulf of Finland.
    // Intentionally generous rather than precise: this only ever fires for
    // a point with NO resolvable country (i.e. already established to be
    // open water, per resolve() below), so overshooting slightly into e.g.
    // the northern North Sea near Skagen costs nothing but an Open-Meteo-
    // quality-equivalent SMHI forecast instead of Open-Meteo's own - never
    // a wrong result, just a slightly generous SMHI catchment. Refining
    // this into a real polygon is future work, not this step's.
    private const BALTIC_MIN_LAT = 53.0;
    private const BALTIC_MAX_LAT = 66.0;
    private const BALTIC_MIN_LNG = 9.0;
    private const BALTIC_MAX_LNG = 30.0;

    public function __construct(private readonly GeocodingService $geocoding)
    {
    }

    public function resolve(float $lat, float $lng): WeatherRegion
    {
        $countryCode = $this->geocoding->resolveCountryCode($lat, $lng);

        if ($countryCode === 'se') {
            return new WeatherRegion(WeatherRegion::PROVIDER_SMHI, 'SE');
        }

        if ($countryCode !== null && isset(self::COUNTRY_MODELS[$countryCode])) {
            $config = self::COUNTRY_MODELS[$countryCode];

            return new WeatherRegion(
                WeatherRegion::PROVIDER_OPEN_METEO,
                strtoupper($countryCode),
                $config['model'],
                $config['source'],
            );
        }

        if ($countryCode === null && $this->isWithinBalticBounds($lat, $lng)) {
            return new WeatherRegion(WeatherRegion::PROVIDER_SMHI, 'BALTIC-INTL');
        }

        return new WeatherRegion(WeatherRegion::PROVIDER_OPEN_METEO, 'default');
    }

    private function isWithinBalticBounds(float $lat, float $lng): bool
    {
        return $lat >= self::BALTIC_MIN_LAT && $lat <= self::BALTIC_MAX_LAT
            && $lng >= self::BALTIC_MIN_LNG && $lng <= self::BALTIC_MAX_LNG;
    }
}
