<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Water\WaterCorrectionRepository;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;
use Ytan\Service\LighthousePolygon;
use Ytan\Service\Log\ImportLog;
use Ytan\Service\Water\PoiWaterDistanceService;
use Ytan\Service\Water\WaterDistanceService;

/**
 * Backend of the admin page /admin/water (templates/admin-water.php): the
 * water index's lines for the map overlay and the admin's corrections of it
 * (WaterCorrections). Every change rewrites storage/water/water-corrections.json,
 * so autorouting, Routencheck and the POI distances see it at once, and
 * recomputes the stored distances of the POIs around it. Admin only.
 */
final class WaterIndexAdminController extends BaseController
{
    /** Largest box the overlay serves (degrees per side) - beyond that: zoom in. */
    public const MAX_BOX_DEGREES = 0.6;
    public const MAX_SEGMENTS = 40000;
    /** POIs this far around a correction get their distances again. */
    public const RECOMPUTE_PAD_DEGREES = 0.1;
    public const RECOMPUTE_LIMIT = 2000;

    public function __construct(
        private readonly WaterDistanceService $water,
        private readonly WaterCorrectionRepository $corrections,
        private readonly PoiWaterDistanceService $poiDistances,
        private readonly string $correctionsPath,
        private readonly ImportLog $log = new ImportLog(),
    ) {
    }

    /** GET /admin/water/segments?south&west&north&east[&raw=1] */
    public function segments(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $params = $request->getQueryParams();
        $box = [];
        foreach (['south', 'west', 'north', 'east'] as $name) {
            if (!isset($params[$name]) || !is_numeric($params[$name])) {
                throw new ValidationException('south, west, north and east are required.');
            }
            $box[$name] = (float) $params[$name];
        }
        if ($box['north'] < $box['south'] || $box['east'] < $box['west']) {
            throw new ValidationException('The box is empty.');
        }
        $tooLarge = $box['north'] - $box['south'] > self::MAX_BOX_DEGREES || $box['east'] - $box['west'] > self::MAX_BOX_DEGREES;
        if ($tooLarge) {
            return $this->json($response, ['data' => [
                'available' => $this->water->available(),
                'too_large' => true,
                'segments' => [],
                'removed' => [],
                'truncated' => false,
            ]]);
        }
        $raw = filter_var($params['raw'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $result = $this->water->overlaySegments($box['south'], $box['west'], $box['north'], $box['east'], $raw, self::MAX_SEGMENTS);
        $centre = [($box['south'] + $box['north']) / 2, ($box['west'] + $box['east']) / 2];

        return $this->json($response, ['data' => [
            'available' => $this->water->available(),
            'version' => $this->water->version(),
            'covered' => $this->water->covers($centre[0], $centre[1]),
            'too_large' => false,
        ] + $result]);
    }

    /** GET /admin/water/corrections */
    public function index(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);

        return $this->json($response, ['data' => $this->corrections->all()]);
    }

    /** POST /admin/water/corrections - body {type, kinds, points, note} */
    public function create(Request $request, Response $response): Response
    {
        $authUser = $this->requireAdmin($request);
        $correction = WaterCorrectionRepository::normalize($this->jsonBody($request));

        $result = $this->log->run($authUser, 'add correction', [
            'type' => $correction['type'],
            'kinds' => implode(',', $correction['kinds']),
            'points' => count($correction['points']),
        ], function () use ($correction, $authUser) {
            $row = $this->corrections->create($correction, (int) $authUser['sub'], (string) ($authUser['username'] ?? ''));

            return ['correction' => $row, 'pois' => $this->afterChange([$row])];
        });

        return $this->json($response, ['data' => $result], 201);
    }

    /**
     * PUT /admin/water/corrections/{id} - body {points, note?}: the line of an
     * own correction changed in place (add: the drawn line; replace: its new
     * course, empty = the replaced line is just deleted). Type, kinds and the
     * replaced original stay as they are.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $authUser = $this->requireAdmin($request);
        $id = (int) ($args['id'] ?? 0);
        $row = $this->corrections->find($id);
        if ($row === null) {
            throw new NotFoundException('Correction not found.');
        }
        if ($row['type'] !== 'add' && $row['type'] !== 'replace') {
            throw new ValidationException('Only a correction with a line can be changed.');
        }
        $body = $this->jsonBody($request);
        $correction = WaterCorrectionRepository::normalize([
            'type' => $row['type'],
            'kinds' => $row['kinds'],
            'points' => $body['points'] ?? null,
            'original' => $row['original'],
            'note' => array_key_exists('note', $body) ? $body['note'] : $row['note'],
        ]);

        $result = $this->log->run($authUser, 'change correction', [
            'id' => $id,
            'type' => $row['type'],
            'points' => count($correction['points']),
        ], function () use ($id, $row, $correction) {
            $updated = (array) $this->corrections->updateLine($id, $correction['points'], $correction['note']);

            // the old and the new course: POIs along both get their distances again
            return ['correction' => $updated, 'pois' => $this->afterChange([$row, $updated])];
        });

        return $this->json($response, ['data' => $result]);
    }

    /** DELETE /admin/water/corrections/{id} */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $authUser = $this->requireAdmin($request);
        $id = (int) ($args['id'] ?? 0);
        $row = $this->corrections->find($id);
        if ($row === null) {
            throw new NotFoundException('Correction not found.');
        }

        $result = $this->log->run($authUser, 'delete correction', ['id' => $id, 'type' => $row['type']], function () use ($id, $row) {
            $this->corrections->delete($id);

            return ['pois' => $this->afterChange([$row])];
        });

        return $this->json($response, ['data' => $result]);
    }

    /** GET /admin/water/corrections/export - transfer file for another system */
    public function exportFile(Request $request, Response $response): Response
    {
        $this->requireAdmin($request);
        $entries = array_map(static fn (array $row): array => [
            'type' => $row['type'],
            'kinds' => $row['kinds'],
            'points' => $row['points'],
            'original' => $row['original'],
            'note' => $row['note'],
            'username' => $row['username'],
            'created_at' => $row['created_at'],
        ], $this->corrections->all());

        return $this->json($response, ['data' => [
            'format' => 'ytan-water-corrections',
            'version' => 1,
            'corrections' => $entries,
        ]]);
    }

    /** POST /admin/water/corrections/file - body = a transfer file; identical corrections are skipped */
    public function importFile(Request $request, Response $response): Response
    {
        $authUser = $this->requireAdmin($request);
        $body = $this->jsonBody($request);
        if (($body['format'] ?? null) !== 'ytan-water-corrections' || !isset($body['corrections']) || !is_array($body['corrections'])) {
            throw new ValidationException('This is no water corrections file.');
        }
        // check everything first, so a bad entry adds nothing
        $entries = [];
        foreach ($body['corrections'] as $i => $entry) {
            if (!is_array($entry)) {
                throw new ValidationException('Entry ' . ($i + 1) . ' is not valid.');
            }
            $created = isset($entry['created_at']) && is_string($entry['created_at'])
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['created_at']) ? $entry['created_at'] : null;
            $entries[] = [WaterCorrectionRepository::normalize($entry), (string) ($entry['username'] ?? ''), $created];
        }

        $result = $this->log->run($authUser, 'read transfer file', ['entries' => count($entries)], function () use ($entries) {
            $added = [];
            $skipped = 0;
            foreach ($entries as [$correction, $username, $created]) {
                if ($this->corrections->exists($correction)) {
                    $skipped++;
                    continue;
                }
                $added[] = $this->corrections->create($correction, null, $username, $created);
            }

            return ['added' => count($added), 'skipped' => $skipped, 'pois' => $this->afterChange($added)];
        });

        return $this->json($response, ['data' => $result]);
    }

    /**
     * After a change: the file the index readers use, then the POI distances
     * around the changed corrections.
     *
     * @param list<array<string, mixed>> $changed
     * @return array{computed: int, more: bool}
     */
    private function afterChange(array $changed): array
    {
        $this->corrections->writeFile($this->correctionsPath);
        $this->water->reloadCorrections();
        $total = ['computed' => 0, 'more' => false];
        foreach ($changed as $row) {
            // a replaced line's old course counts too (its new one may be empty)
            $box = LighthousePolygon::boundingBox(array_merge($row['points'], $row['original'] ?? []));
            $pad = self::RECOMPUTE_PAD_DEGREES;
            $done = $this->poiDistances->recomputeBox($box['south'] - $pad, $box['west'] - $pad, $box['north'] + $pad, $box['east'] + $pad, self::RECOMPUTE_LIMIT);
            $total['computed'] += $done['computed'];
            $total['more'] = $total['more'] || $done['more'];
        }

        return $total;
    }
}
