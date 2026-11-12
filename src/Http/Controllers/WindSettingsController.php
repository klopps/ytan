<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ValidationException;
use Ytan\Service\JobPlacement;
use Ytan\Service\RadarConfig;
use Ytan\Service\RadarJobRunner;
use Ytan\Service\WindConfig;

/**
 * Wind card of the admin area (/admin/radar): the wind job's settings
 * (WindConfig) and what it reported about its last runs
 * (storage/wind/status.json). Saving writes storage/radar/config.json, which
 * both jobs read - the regions are the rain radar's.
 */
final class WindSettingsController extends BaseController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly string $radarDir,
        private readonly string $windDir,
        private readonly RadarJobRunner $runner,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->payload($this->settings->windSettings())]);
    }

    public function update(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $config = WindConfig::normalize($this->jsonBody($request));
        $this->settings->setWindSettings($config);
        RadarConfig::writeJobConfig($this->radarDir, $this->settings->radarSettings(), $config, $this->settings->externalJobs());
        if (!$config['enabled']) {
            // switched off: the layer disappears for everybody at once (no manifest = "no wind data"),
            // the job removes it too when it runs; switching on again makes its next run publish anew
            @unlink($this->windDir . '/manifest.json');
        }

        return $this->json($response, ['data' => $this->payload($config)]);
    }

    /** "Update now": starts the wind job (--force) in the background unless a run is in progress (409). */
    public function run(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        if (!$this->settings->windSettings()['enabled']) {
            throw new ApiException('The wind job is switched off.', 409, 'wind.job_disabled');
        }
        if (in_array('wind', $this->settings->externalJobs(), true)) {
            // runs on the external worker: it picks the request up at its next tick (JobPlacement)
            JobPlacement::requestRun(dirname($this->windDir), 'wind');

            return $this->json($response, ['data' => ['started' => true, 'external' => true]], 202);
        }
        $this->runner->start();

        return $this->json($response, ['data' => ['started' => true]], 202);
    }

    /** Where the job runs: {external: true} = on the YTAN worker (JobPlacement), false = on this server. */
    public function placement(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $external = $this->jsonBody($request)['external'] ?? null;
        if (!is_bool($external)) {
            throw new ValidationException('external must be true or false.', 'job.placement_invalid');
        }
        $jobs = JobPlacement::with($this->settings->externalJobs(), 'wind', $external);
        $this->settings->setExternalJobs($jobs);
        if (!$external) {
            JobPlacement::clearRequest(dirname($this->windDir), 'wind');
        }
        $config = $this->settings->windSettings();
        RadarConfig::writeJobConfig($this->radarDir, $this->settings->radarSettings(), $config, $jobs);

        return $this->json($response, ['data' => $this->payload($config)]);
    }

    private function payload(array $config): array
    {
        return $config + [
            'status' => WindConfig::readStatus($this->windDir),
            'running' => $this->runner->isRunning(),
            'horizons' => WindConfig::HORIZONS,
            'intervals' => WindConfig::INTERVALS,
        ] + JobPlacement::payload(dirname($this->windDir), 'wind', $this->settings->externalJobs());
    }
}
