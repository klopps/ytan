<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ValidationException;

final class SettingsController extends BaseController
{
    /** The highest POI type id (app.poitype.N in the translations, detail0-detail16 in the drawer). */
    public const MAX_POI_TYPE = 16;

    /** Upper limit of the route-merge midpoint distance (meters). */
    public const MAX_ROUTE_MERGE_MIDPOINT_M = 1000;

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function updateGoogleSearchRequiresLogin(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $enabled = (bool) ($this->jsonBody($request)['enabled'] ?? false);
        $this->settings->setGoogleSearchRequiresLogin($enabled);

        return $this->json($response, ['data' => ['google_search_requires_login' => $enabled]]);
    }

    public function updateGpxExportPublic(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $enabled = (bool) ($this->jsonBody($request)['enabled'] ?? false);
        $this->settings->setGpxExportPublic($enabled);

        return $this->json($response, ['data' => ['gpx_export_public' => $enabled]]);
    }

    /** Body {poitypes: [int, ...]} - the POI types whose distance to water is stored. */
    public function updateWaterDistancePoiTypes(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $types = $this->jsonBody($request)['poitypes'] ?? null;
        if (!is_array($types)) {
            throw new ValidationException('poitypes must be a list of POI type ids.');
        }
        foreach ($types as $type) {
            if (!is_int($type) || $type < 0 || $type > self::MAX_POI_TYPE) {
                throw new ValidationException('poitypes must be a list of POI type ids.');
            }
        }
        $this->settings->setWaterDistancePoiTypes($types);

        return $this->json($response, ['data' => ['water_distance_poitypes' => $this->settings->waterDistancePoiTypes()]]);
    }

    public function updateTrackDistanceFilterPresets(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        $precise = filter_var($body['precise'] ?? null, FILTER_VALIDATE_INT);
        $balanced = filter_var($body['balanced'] ?? null, FILTER_VALIDATE_INT);
        $battery = filter_var($body['battery'] ?? null, FILTER_VALIDATE_INT);

        if ($precise === false || $balanced === false || $battery === false
            || $precise < 1 || $balanced < 1 || $battery < 1
            || $precise > 65535 || $balanced > 65535 || $battery > 65535) {
            throw new ValidationException('precise, balanced and battery must each be a positive whole number of meters.');
        }

        $this->settings->setTrackDistanceFilterPresets($precise, $balanced, $battery);

        return $this->json($response, ['data' => ['precise' => $precise, 'balanced' => $balanced, 'battery' => $battery]]);
    }

    /** Body {midpoint_m} - see SettingsRepository::routeMergeMidpointMeters(). */
    public function updateRouteMerge(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $meters = filter_var($this->jsonBody($request)['midpoint_m'] ?? null, FILTER_VALIDATE_INT);
        if ($meters === false || $meters < 0 || $meters > self::MAX_ROUTE_MERGE_MIDPOINT_M) {
            throw new ValidationException('midpoint_m must be a whole number of meters from 0 to ' . self::MAX_ROUTE_MERGE_MIDPOINT_M . '.');
        }

        $this->settings->setRouteMergeMidpointMeters($meters);

        return $this->json($response, ['data' => ['midpoint_m' => $meters]]);
    }

    public function updateRouteLabelFontSizeRange(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        $min = filter_var($body['min'] ?? null, FILTER_VALIDATE_INT);
        $max = filter_var($body['max'] ?? null, FILTER_VALIDATE_INT);

        if ($min === false || $max === false || $min < 1 || $max > 255 || $min >= $max) {
            throw new ValidationException('min and max must be whole numbers of pixels, with min less than max.');
        }

        $this->settings->setRouteLabelFontSizeRange($min, $max);

        return $this->json($response, ['data' => ['min' => $min, 'max' => $max]]);
    }

    /** Colour, line width, zoom level, end pause and default speed of the route/tour animation. */
    public function updateRouteAnimation(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        $color = is_string($body['color'] ?? null) ? strtoupper(trim($body['color'])) : '';
        $lineWidth = filter_var($body['line_width'] ?? null, FILTER_VALIDATE_INT);
        $zoom = filter_var($body['zoom'] ?? null, FILTER_VALIDATE_FLOAT);
        $delay = filter_var($body['end_delay_s'] ?? null, FILTER_VALIDATE_FLOAT);
        $speed = filter_var($body['speed'] ?? null, FILTER_VALIDATE_INT);

        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            throw new ValidationException('color must be a #RRGGBB colour.');
        }
        if ($lineWidth === false || $lineWidth < 1 || $lineWidth > 30) {
            throw new ValidationException('line_width must be a whole number of pixels from 1 to 30.');
        }
        if ($zoom === false || $zoom < 3 || $zoom > 21) {
            throw new ValidationException('zoom must be a number from 3 to 21.');
        }
        if ($delay === false || $delay < 0 || $delay > 600) {
            throw new ValidationException('end_delay_s must be a number of seconds from 0 to 600.');
        }
        if ($speed === false || $speed < 10 || $speed > 2000) {
            throw new ValidationException('speed must be a whole number of pixels per second from 10 to 2000.');
        }
        $zoom = round($zoom, 1);
        $delay = round($delay, 2);

        $this->settings->setRouteAnimationSettings($color, $lineWidth, $zoom, $delay, $speed);

        return $this->json($response, ['data' => ['color' => $color, 'line_width' => $lineWidth, 'zoom' => $zoom, 'end_delay_s' => $delay, 'speed' => $speed]]);
    }
}
