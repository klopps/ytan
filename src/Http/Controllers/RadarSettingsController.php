<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ValidationException;
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
        if (RadarConfig::isPaused($this->settings->radarSettings())) {
            throw new ApiException('The radar job is paused.', 409, 'radar.job_paused');
        }
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
        $stored = $this->settings->radarSettings();
        $config = RadarConfig::normalize($this->jsonBody($request));
        $this->settings->setRadarSettings($config);
        // the pause is set separately (pause()/resume()) and stays as it is
        $config['paused_until'] = $stored['paused_until'];
        $config['paused_forever'] = $stored['paused_forever'];
        RadarConfig::writeJobConfig($this->radarDir, $config, $this->settings->windSettings());

        return $this->json($response, ['data' => $this->payload($config)]);
    }

    /**
     * Pause the job: {minutes: 15|30|60} for that long from now, or
     * {forever: true} until resume(). The job skips its runs meanwhile.
     */
    public function pause(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        if (($body['forever'] ?? false) === true) {
            $this->settings->setRadarPause(null, true);
        } else {
            $minutes = filter_var($body['minutes'] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($minutes, RadarConfig::PAUSE_MINUTES, true)) {
                throw new ValidationException('minutes must be 15, 30 or 60.', 'radar.pause_invalid');
            }
            $this->settings->setRadarPause(gmdate('Y-m-d H:i:s', time() + $minutes * 60), false);
        }

        return $this->afterPauseChange($response);
    }

    /** Resume a paused job; its next cron run fetches again. */
    public function resume(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $this->settings->setRadarPause(null, false);

        return $this->afterPauseChange($response);
    }

    private function afterPauseChange(Response $response): Response
    {
        $config = $this->settings->radarSettings();
        RadarConfig::writeJobConfig($this->radarDir, $config, $this->settings->windSettings());

        return $this->json($response, ['data' => $this->payload($config)]);
    }

    private function payload(array $config): array
    {
        return $config + [
            'status' => RadarConfig::readStatus($this->radarDir),
            'running' => $this->runner->isRunning(),
            'paused' => RadarConfig::isPaused($config),
            'preset_regions' => RadarConfig::PRESETS,
            'mosaic_pixels' => RadarConfig::regions($config) === [] ? 0
                : (int) RadarConfig::mosaicPixels(RadarConfig::enclosing(RadarConfig::regions($config))),
            'max_mosaic_pixels_min' => RadarConfig::MAX_MOSAIC_PIXELS_MIN,
            'max_mosaic_pixels_limit' => RadarConfig::MAX_MOSAIC_PIXELS_LIMIT,
        ];
    }
}
