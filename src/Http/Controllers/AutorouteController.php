<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\RequestThrottle;
use Ytan\Service\Water\AutorouteApplier;
use Ytan\Service\Water\Autorouter;

/**
 * Autorouting (route context menu "Autorouting", route.js): moves the parts of
 * a route that lead over land - or nearer to the shore than wanted - onto
 * water. Stateless: the line comes in the request, nothing is stored (the
 * client puts the result into the normal route editor).
 *
 * - POST /routes/autoroute/check  {points, scan?}: are start and end usable? Answers
 *   {ok, problems[]} (start_not_covered, end_not_covered, start_on_land,
 *   end_on_land); the client opens its dialog only for ok. With scan (and ok)
 *   also land_m = metres of the line over land (asked before saving a route).
 * - POST /routes/autoroute  {points, segment: true}: a part of a line (live mode /
 *   waypoint menu of the route editor): the user's saved preferences, no dialog,
 *   own rate limit; an unusable start/end is no 422 but the line unchanged with
 *   warning {code: not_usable, problems}.
 * - POST /routes/autoroute  {points, shore_m, max_m, enabled}: the new line,
 *   {points, changed_sections, length_before, length_after, warnings[]}; start/end
 *   are checked again (422), the dialog's values become the user's preferences.
 * - GET/PUT /auth/autoroute: the preferences {shore_m, max_m, enabled, default_m, default_max_m}.
 *
 * Points are [{lat, lng}, ...] like route.points. Any signed-in user; routes
 * longer than Autorouter::MAX_ROUTE_M are refused (CPU).
 */
final class AutorouteController extends BaseController
{
    private readonly AutorouteApplier $applier;

    public function __construct(
        private readonly Autorouter $router,
        private readonly UserRepository $users,
        private readonly SettingsRepository $settings,
        private readonly RequestThrottle $throttle,
    ) {
        $this->applier = new AutorouteApplier($router, $users, $settings);
    }

    public function preferences(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);

        return $this->json($response, ['data' => $this->preferencesFor((int) $auth['sub'])]);
    }

    /** Body {shore_m: int|null (null = the default), enabled: bool}; either may be left out. */
    public function savePreferences(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $body = $this->jsonBody($request);
        $current = $this->users->getAutoroutePrefs((int) $auth['sub']);
        $shore = array_key_exists('shore_m', $body) ? $this->shoreMeters($body['shore_m']) : $current['shore_m'];
        $max = array_key_exists('max_m', $body) ? $this->maxMeters($body['max_m']) : $current['max_m'];
        $enabled = array_key_exists('enabled', $body) ? (bool) $body['enabled'] : $current['enabled'];
        $this->users->setAutoroutePrefs((int) $auth['sub'], $shore, $max, $enabled);

        return $this->json($response, ['data' => $this->preferencesFor((int) $auth['sub'])]);
    }

    public function check(Request $request, Response $response): Response
    {
        $this->requireAuthUser($request);
        $body = $this->jsonBody($request);
        $points = $this->points($body['points'] ?? null);
        $problems = $this->router->check($points);
        $data = ['ok' => $problems === [], 'problems' => $problems];
        // scan: how much of the line is over land (asked before a route is saved)
        if (!empty($body['scan']) && $problems === []) {
            $data['land_m'] = (int) round($this->applier->landMeters($points));
        }

        return $this->json($response, ['data' => $data]);
    }

    public function route(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $userId = (int) $auth['sub'];
        $body = $this->jsonBody($request);
        $points = $this->points($body['points'] ?? null);

        // segment: part of a line (live mode, waypoint menu) - saved preferences, no dialog, an unusable end is no error
        if (!empty($body['segment'])) {
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
            $this->throttle->assertAllowed(RequestThrottle::AUTOROUTE_SEGMENT, 'user:' . $userId, $ip);
            $this->throttle->record(RequestThrottle::AUTOROUTE_SEGMENT, 'user:' . $userId, $ip);

            return $this->json($response, ['data' => $this->rounded($this->applier->apply($userId, $points))]);
        }

        $this->throttle->assertAllowed(RequestThrottle::AUTOROUTE, 'user:' . $userId, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        $problems = $this->router->check($points);
        if ($problems !== []) {
            throw new ValidationException('Start or end cannot be used for autorouting: ' . $problems[0] . '.', 'autoroute.' . $problems[0]);
        }
        $this->throttle->record(RequestThrottle::AUTOROUTE, 'user:' . $userId, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));

        $enabled = array_key_exists('enabled', $body) ? (bool) $body['enabled'] : true;
        $default = $this->settings->autorouteShoreMeters();
        $shore = array_key_exists('shore_m', $body) && $body['shore_m'] !== null ? $this->shoreMeters($body['shore_m']) : null;
        // a value equal to the default is kept as "the default", so a later change of it reaches the user
        $defaultMax = $this->settings->autorouteShoreMaxMeters();
        $max = array_key_exists('max_m', $body) && $body['max_m'] !== null ? $this->maxMeters($body['max_m']) : null;
        $this->users->setAutoroutePrefs($userId, $shore === $default ? null : $shore, $max === $defaultMax ? null : $max, $enabled);

        $result = $this->router->route(
            $points,
            $enabled ? (float) ($shore ?? $default) : null,
            $enabled ? (float) ($max ?? $defaultMax) : null
        );
        return $this->json($response, ['data' => $this->rounded($result)]);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function rounded(array $result): array
    {
        $result['points'] = array_map(
            static fn (array $p): array => ['lat' => round($p[0], 6), 'lng' => round($p[1], 6)],
            $result['points']
        );
        $result['length_before'] = (int) round($result['length_before']);
        $result['length_after'] = (int) round($result['length_after']);

        return $result;
    }

    /** @return array{shore_m: int, custom: bool, max_m: int, max_custom: bool, enabled: bool, default_m: int, default_max_m: int} */
    private function preferencesFor(int $userId): array
    {
        $prefs = $this->users->getAutoroutePrefs($userId);
        $default = $this->settings->autorouteShoreMeters();
        $defaultMax = $this->settings->autorouteShoreMaxMeters();

        return [
            'shore_m' => $prefs['shore_m'] ?? $default,
            'custom' => $prefs['shore_m'] !== null,
            'max_m' => $prefs['max_m'] ?? $defaultMax,
            'max_custom' => $prefs['max_m'] !== null,
            'enabled' => $prefs['enabled'],
            'default_m' => $default,
            'default_max_m' => $defaultMax,
        ];
    }

    /** The largest distance to the shore: 0 = no limit, null = the default. */
    private function maxMeters(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $meters = filter_var($raw, FILTER_VALIDATE_INT);
        if ($meters === false || $meters < 0 || $meters > SettingsRepository::AUTOROUTE_SHORE_FAR_MAX_M) {
            throw new ValidationException(
                'max_m must be a whole number of metres from 0 to ' . SettingsRepository::AUTOROUTE_SHORE_FAR_MAX_M . '.',
                'autoroute.max_invalid'
            );
        }

        return $meters;
    }

    private function shoreMeters(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $meters = filter_var($raw, FILTER_VALIDATE_INT);
        if ($meters === false || $meters < 0 || $meters > SettingsRepository::AUTOROUTE_SHORE_MAX_M) {
            throw new ValidationException(
                'shore_m must be a whole number of metres from 0 to ' . SettingsRepository::AUTOROUTE_SHORE_MAX_M . '.',
                'autoroute.shore_invalid'
            );
        }

        return $meters;
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function points(mixed $raw): array
    {
        if (!is_array($raw) || count($raw) < 2 || count($raw) > 5000) {
            throw new ValidationException('points must be a list of 2 to 5000 positions.', 'autoroute.points_invalid');
        }
        $points = [];
        foreach ($raw as $point) {
            if (!is_array($point) || !isset($point['lat'], $point['lng']) || !is_numeric($point['lat']) || !is_numeric($point['lng'])) {
                throw new ValidationException('Every point needs lat and lng.', 'autoroute.points_invalid');
            }
            $lat = (float) $point['lat'];
            $lng = (float) $point['lng'];
            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                throw new ValidationException('A position is outside the valid range.', 'autoroute.points_invalid');
            }
            $points[] = [$lat, $lng];
        }
        if (Autorouter::length($points) > Autorouter::MAX_ROUTE_M) {
            throw new ValidationException('The route is too long for autorouting.', 'autoroute.too_long');
        }

        return $points;
    }
}
