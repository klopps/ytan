<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Area\AreaRepository;
use Ytan\Domain\MapChangeRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Exception\ValidationException;

/**
 * GET /map/changes?since=CURSOR - the live map's change feed (todo.md
 * "Automatische Update bei Veränderungen", public/js/live-sync.js).
 *
 * Without `since` it only hands out a cursor. With it, it answers every
 * POI/route/area written since then that the caller may see (public ones,
 * plus their own when signed in - the map's `mine_public` scope) in the same
 * shape as a single-entity GET, and the ids that dropped out: deleted, or
 * changed into something the caller may not see (made private). A kind with
 * more than MAX_ROWS changes (an import) is answered with `reload` instead,
 * and so is everything when the cursor is older than the tombstones reach.
 *
 * The next cursor is the database's time minus OVERLAP_SECONDS: a write whose
 * transaction committed after the cursor was taken still carries an earlier
 * `changed_at`, and the overlap catches it on the next poll. The client skips
 * rows whose `changed_at` it already has, so repeats cost nothing.
 */
final class MapChangesController extends BaseController
{
    public const OVERLAP_SECONDS = 30;
    public const MAX_ROWS = 300;
    /** Tombstones live a day (MapChangeRepository) - an older cursor can't know all deletions. */
    private const MAX_AGE_SECONDS = 23 * 3600;
    private const PLURAL = ['poi' => 'pois', 'route' => 'routes', 'area' => 'areas'];

    public function __construct(
        private readonly MapChangeRepository $changes,
        private readonly PoiRepository $pois,
        private readonly RouteRepository $routes,
        private readonly AreaRepository $areas,
    ) {
    }

    public function changes(Request $request, Response $response): Response
    {
        $auth = $request->getAttribute('auth');
        $userId = $auth !== null ? (int) $auth['sub'] : null;
        $now = new \DateTimeImmutable($this->changes->now());
        $cursor = $now->modify('-' . self::OVERLAP_SECONDS . ' seconds')->format('Y-m-d H:i:s.v');

        $since = $request->getQueryParams()['since'] ?? null;
        if ($since === null || $since === '') {
            return $this->json($response, ['data' => ['cursor' => $cursor]]);
        }
        if (!is_string($since) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $since) !== 1) {
            throw new ValidationException('since must be a cursor from this endpoint.');
        }

        $data = ['cursor' => $cursor, 'reload' => [], 'removed' => []];
        $tooOld = $now->getTimestamp() - (new \DateTimeImmutable($since))->getTimestamp() > self::MAX_AGE_SECONDS;
        foreach (self::PLURAL as $entity => $plural) {
            $data[$plural] = [];
            $data['removed'][$plural] = [];
            if ($tooOld || $this->changes->countChanged($entity, $since) > self::MAX_ROWS) {
                $data['reload'][] = $plural;
                continue;
            }
            $removed = $this->changes->deletedSince($entity, $since);
            if (count($removed) > self::MAX_ROWS) {
                $data['reload'][] = $plural;
                continue;
            }
            foreach ($this->changedRows($entity, $since) as $row) {
                if ((int) $row['public'] === 1 || ($userId !== null && (int) $row['user_id'] === $userId)) {
                    $data[$plural][] = $this->present($entity, $row, $auth);
                } else {
                    $removed[] = (int) $row['id'];
                }
            }
            $data['removed'][$plural] = array_values(array_unique($removed));
        }

        return $this->json($response, ['data' => $data]);
    }

    private function changedRows(string $entity, string $since): array
    {
        return match ($entity) {
            'poi' => $this->pois->findChangedSince($since),
            'route' => $this->routes->findChangedSince($since),
            'area' => $this->areas->findChangedSince($since),
        };
    }

    /** The same shape the entity's own GET /{entity}/{id} answers. */
    private function present(string $entity, array $row, ?array $auth): array
    {
        if ($entity === 'poi') {
            return PoiController::packWaterDistances($row);
        }
        // Same redaction as RouteController::redactRecordingInfo().
        if ($entity === 'route' && !$this->hasRight($auth, 'route_view_recording')) {
            unset($row['recorded_at'], $row['recording_duration_seconds'], $row['recorded_by_username']);
        }

        return $row;
    }
}
