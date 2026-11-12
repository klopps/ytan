<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Exception\ValidationException;
use Ytan\Service\Log\ImportLog;
use Ytan\Service\Overpass\OsmImportJob;

/**
 * Loading from OpenStreetMap in the background for the campsite, club and
 * shelter import tools (a country) and the lighthouse tool (an area)
 * (OsmImportJob): POST /admin/osm-import {tool, country} or {tool:
 * "lighthouse", south, west, north, east, label} starts it and answers at
 * once (202), GET /admin/osm-import/status
 * is what the pages poll. The import keeps running when the admin leaves the
 * page. Admins only.
 */
final class OsmImportJobController extends BaseController
{
    /** @param \Closure(string): ImportLog $logFor the ImportLog of a tool's log name */
    public function __construct(private readonly OsmImportJob $job, private readonly \Closure $logFor)
    {
    }

    public function start(Request $request, Response $response): Response
    {
        $authUser = $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        $tool = (string) ($body['tool'] ?? '');
        $country = (string) ($body['country'] ?? '');
        if (!isset(OsmImportJob::TOOLS[$tool])) {
            throw new ValidationException('tool must be one of: ' . implode(', ', array_keys(OsmImportJob::TOOLS)) . '.');
        }
        $logName = OsmImportJob::TOOLS[$tool][2];
        $username = (string) ($authUser['username'] ?? '');

        if ($tool === 'lighthouse') {
            // an area instead of a country: {south, west, north, east, label}
            $params = ['label' => (string) ($body['label'] ?? '')];
            foreach (['south', 'west', 'north', 'east'] as $key) {
                $params[$key] = is_scalar($body[$key] ?? null) ? $body[$key] : null;
            }
            $start = function () use ($body, $username) {
                foreach (['south', 'west', 'north', 'east'] as $key) {
                    if (!isset($body[$key]) || !is_numeric($body[$key])) {
                        throw new ValidationException("$key must be a number.");
                    }
                }

                return $this->job->startArea((float) $body['south'], (float) $body['west'], (float) $body['north'], (float) $body['east'], (string) ($body['label'] ?? ''), $username);
            };
        } else {
            $params = ['country' => $country];
            $start = fn () => $this->job->start($tool, $country, $username);
        }

        return $this->json($response, ['data' => ($this->logFor)($logName)->run(
            $authUser,
            'start OpenStreetMap import in the background',
            $params,
            $start,
            false,
            fn (array $status) => ['state' => $status['state']]
        )], 202);
    }

    public function status(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->job->status()]);
    }
}
