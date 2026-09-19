<?php

declare(strict_types=1);

namespace Ytan\Service\Weather;

/**
 * WeatherRegionResolver::resolve()'s result - which WeatherProviderInterface
 * WeatherService should use for a coordinate, plus a human-readable code
 * for the stored weather_forecast.region_code column (debugging/future
 * cleanup-by-region, not shown to the user).
 *
 * $openMeteoModel/$sourceLabel are only ever set together with
 * `provider === PROVIDER_OPEN_METEO`, for a country whose "gesonderte
 * Datenquelle" turned out to already be reachable through Open-Meteo's own
 * API via its `models=` parameter (DWD/DMI/MET Norway/Météo-France/KNMI -
 * see WeatherRegionResolver's own doc comment for why those five don't need
 * a real separate WeatherProviderInterface implementation the way SMHI
 * does). Both null means "no specific model" - Open-Meteo's own best-match
 * default, source "Open-Meteo".
 */
final class WeatherRegion
{
    public const PROVIDER_OPEN_METEO = 'open_meteo';
    public const PROVIDER_SMHI = 'smhi';

    public function __construct(
        public readonly string $provider,
        public readonly string $regionCode,
        public readonly ?string $openMeteoModel = null,
        public readonly ?string $sourceLabel = null,
    ) {
    }
}
