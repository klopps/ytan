<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ValidationException;

final class SettingsController extends BaseController
{
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
}
