<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Service\RadarConfig;
use Ytan\Service\RadarJobRunner;

/**
 * Rain radar page of the admin area (/admin/radar): the job's settings
 * (RadarConfig) and what it reported about its last runs
 * (storage/radar/status.json). Saving also writes storage/radar/config.json,
 * which the radar job (tools/radar/) reads on its next run - changed
 * regions make it recompute every frame.
 */
final class RadarSettingsController extends BaseController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly string $radarDir,
        private readonly RadarJobRunner $runner,
    ) {
    }

    /** "Update now": starts the job in the background unless a run is in progress (409). */
    public function run(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $this->runner->start();

        return $this->json($response, ['data' => ['started' => true]], 202);
    }

    public function show(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->payload($this->settings->radarSettings())]);
    }

    public function update(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $config = RadarConfig::normalize($this->jsonBody($request));
        $this->settings->setRadarSettings($config);
        RadarConfig::writeJobConfig($this->radarDir, $config);

        return $this->json($response, ['data' => $this->payload($config)]);
    }

    private function payload(array $config): array
    {
        return $config + [
            'status' => RadarConfig::readStatus($this->radarDir),
            'running' => $this->runner->isRunning(),
            'preset_regions' => RadarConfig::PRESETS,
            'mosaic_pixels' => RadarConfig::regions($config) === [] ? 0
                : (int) RadarConfig::mosaicPixels(RadarConfig::enclosing(RadarConfig::regions($config))),
            'max_mosaic_pixels' => RadarConfig::MAX_MOSAIC_PIXELS,
        ];
    }
}
