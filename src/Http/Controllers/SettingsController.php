<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Settings\SettingsRepository;

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
}
