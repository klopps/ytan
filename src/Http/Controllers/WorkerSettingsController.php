<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\JobPlacement;
use Ytan\Service\RadarConfig;

/**
 * The YTAN worker registry and the priority list of workers per job (admin page
 * /admin/workers, JobPlacement): who may compute what, who is alive and who works on
 * each job right now.
 */
final class WorkerSettingsController extends BaseController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly string $storageDir,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->payload()]);
    }

    /**
     * Saves the registry and the lists together ({workers: [{id, label, note}], job_workers: {job: [{id, enabled}]}}):
     * the lists are checked against the NEW registry, so a worker can be removed and dropped from its lists in one go.
     */
    public function update(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        $registry = JobPlacement::normalizeRegistry($body['workers'] ?? null);
        $lists = JobPlacement::normalizeLists($body['job_workers'] ?? null, array_column($registry, 'id'));
        $this->settings->setWorkerRegistry($registry);
        $this->settings->setJobWorkers($lists);
        $this->writeConfig($lists);
        foreach (JobPlacement::JOBS as $job) {
            // a request for a job the server runs itself again would only wait for a worker that no longer counts
            if (JobPlacement::startsLocally($this->storageDir, $job, $lists)) {
                JobPlacement::clearRequest($this->storageDir, $job);
            }
        }

        return $this->json($response, ['data' => $this->payload()]);
    }

    /** Frees a name: removes what the system behind it reported (it was replaced, or it is a stranger that went away). */
    public function reset(Request $request, Response $response, array $args): Response
    {
        $this->requireAdmin($request);
        $id = (string) ($args['id'] ?? '');
        if (!JobPlacement::isValidId($id) || !array_key_exists($id, JobPlacement::workers($this->storageDir))) {
            throw new NotFoundException('No worker with this name has reported.');
        }
        JobPlacement::resetWorker($this->storageDir, $id);

        return $this->json($response, ['data' => $this->payload()]);
    }

    /** @param array<string, list<array{id: string, enabled: bool}>> $lists */
    private function writeConfig(array $lists): void
    {
        RadarConfig::writeJobConfig($this->storageDir . '/radar', $this->settings->radarSettings(), $this->settings->windSettings(), $lists);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $now = time();
        $registry = $this->settings->workerRegistry();
        $registered = array_column($registry, 'id');
        $lists = $this->settings->jobWorkers();
        $reports = [];
        foreach (JobPlacement::workers($this->storageDir, $now) as $id => $report) {
            $reports[$id] = $report + ['registered' => in_array($id, $registered, true)];
        }
        $serverTick = JobPlacement::serverTick($this->storageDir);
        $active = [];
        foreach (JobPlacement::JOBS as $job) {
            $active[$job] = JobPlacement::activeFor($this->storageDir, $job, $lists, $now);
        }

        return [
            'workers' => $registry,
            'job_workers' => $lists,
            'jobs' => JobPlacement::JOBS,
            'active' => $active,
            'reports' => $reports,
            'server' => ['tick' => $serverTick, 'alive' => $serverTick !== null && JobPlacement::heartbeatAlive(['tick' => $serverTick], $now)],
            'limits' => ['max_workers' => JobPlacement::MAX_WORKERS, 'max_list' => JobPlacement::MAX_LIST, 'stale_seconds' => JobPlacement::WORKER_STALE_SECONDS],
        ];
    }
}
