<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\RequestThrottle;
use Ytan\Service\Tide\TidalRiver;
use Ytan\Service\Water\ReachConditions;
use Ytan\Service\Water\WaterReach;
use Ytan\Service\Wind\WindField;
use Ytan\Service\Wind\WindSampler;

/**
 * Etmal: the user's distance per day (GET/PUT /auth/etmal) and the reach lines
 * over water from a point of the map (POST /water/reach, WaterReach).
 */
final class WaterReachController extends BaseController
{
    /** Paddling speed without one in the profile (= the Routencheck's). */
    public const DEFAULT_SPEED_KMH = AiBriefingController::DEFAULT_SPEED_KMH;
    /** A timed reach lasts at most this long (an etmal of 60 km at 2.5 km/h). */
    public const MAX_DURATION_S = 24 * 3600;
    /** How far ahead a start may lie (the weather forecasts' horizon). */
    public const MAX_DAYS_AHEAD = 7;
    public const MAX_CURRENT_KMH = AiBriefingController::MAX_CURRENT_KMH;

    /**
     * @param WindSampler|null $wind wind for the timed reach; null = calm (tests)
     * @param TidalRiver|null $tidal tidal rivers for the timed reach; null = never tidal
     */
    public function __construct(
        private readonly WaterReach $reach,
        private readonly UserRepository $users,
        private readonly SettingsRepository $settings,
        private readonly RequestThrottle $throttle,
        private readonly ?WindSampler $wind = null,
        private readonly ?TidalRiver $tidal = null,
    ) {
    }

    public function etmal(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);

        return $this->json($response, ['data' => $this->etmalFor((int) $auth['sub'])]);
    }

    /** Body {etmal_km: int 5-60} - always a valid value, there is no "empty". */
    public function saveEtmal(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $km = $this->kilometres($this->jsonBody($request)['etmal_km'] ?? null);
        $this->users->setEtmalKm((int) $auth['sub'], $km);

        return $this->json($response, ['data' => $this->etmalFor((int) $auth['sub'])]);
    }

    /**
     * Body {lat, lng}: the reach lines of the user's etmal from there (pure
     * distance over water); with timed = {start, current_kmh?,
     * tidal_current_kmh?} paddling-time lines with wind and current instead.
     */
    public function reach(Request $request, Response $response): Response
    {
        $auth = $this->requireAuthUser($request);
        $body = $this->jsonBody($request);
        $lat = filter_var($body['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($body['lng'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            throw new ValidationException('lat and lng must be a valid position.', 'etmal.position_invalid');
        }
        $userId = (int) $auth['sub'];
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $this->throttle->assertAllowed(RequestThrottle::WATER_REACH, 'user:' . $userId, $ip);
        $this->throttle->record(RequestThrottle::WATER_REACH, 'user:' . $userId, $ip);

        $timed = $body['timed'] ?? null;
        if ($timed !== null && !is_array($timed)) {
            throw new ValidationException('timed must be an object.', 'etmal.timed_invalid');
        }
        $km = $this->users->getEtmalKm($userId);
        $km = max(SettingsRepository::ETMAL_MIN_KM, min(SettingsRepository::ETMAL_MAX_KM, $km));
        if ($timed !== null) {
            return $this->json($response, ['data' => $this->timedReach((float) $lat, (float) $lng, (float) $km, $timed, $userId)]);
        }
        $result = $this->reach->reach((float) $lat, (float) $lng, (float) $km);
        if ($result['status'] !== 'ok') {
            throw new ValidationException('No reach lines from this position: ' . $result['status'] . '.', 'etmal.' . $result['status']);
        }
        unset($result['status']);

        return $this->json($response, ['data' => ['mode' => 'distance'] + $result]);
    }

    /**
     * The reach with wind and current (WaterReach::reachTimed()): body
     * timed = {start: unix seconds, current_kmh?, tidal_current_kmh?}. The
     * duration is the etmal over the profile's default speed.
     *
     * @param array<string, mixed> $timed
     * @return array<string, mixed>
     */
    private function timedReach(float $lat, float $lng, float $km, array $timed, int $userId): array
    {
        $start = filter_var($timed['start'] ?? null, FILTER_VALIDATE_INT);
        $now = time();
        if ($start === false || $start < $now - 3600 || $start > $now + self::MAX_DAYS_AHEAD * 86400) {
            throw new ValidationException('timed.start must be a moment from now to ' . self::MAX_DAYS_AHEAD . ' days ahead.', 'etmal.start_invalid');
        }
        $rules = $this->settings->briefingRules();
        $river = $this->currentKmh($timed['current_kmh'] ?? null, (float) $rules['river_current_kmh']);
        $tidalKmh = $this->currentKmh($timed['tidal_current_kmh'] ?? null, (float) $rules['tidal_current_kmh']);
        $speed = $this->users->getDefaultSpeed($userId) ?? self::DEFAULT_SPEED_KMH;
        $duration = (int) min(self::MAX_DURATION_S, round($km / $speed * 3600));
        $end = $start + $duration;

        $conditions = new ReachConditions(
            fn (float $south, float $west, float $north, float $east): ?WindField => $this->wind?->field($south, $west, $north, $east, $start, $end),
            // tide times for the start day and the next one (an afternoon start ends after midnight)
            $this->tidal === null ? null
                : fn (float $tLat, float $tLng): ?array => $this->tidal->check($tLat, $tLng, $rules, $start - 86400, $end + 86400),
            (float) $rules['headwind_slowdown'],
            $river,
            $tidalKmh,
            (int) round((float) $rules['tidal_lag_min'] * 60),
            (float) $rules['river_max_m'],
        );
        $result = $this->reach->reachTimed($lat, $lng, $start, $duration, $speed, $conditions);
        if ($result['status'] !== 'ok') {
            throw new ValidationException('No reach lines from this position: ' . $result['status'] . '.', 'etmal.' . $result['status']);
        }
        unset($result['status']);

        return [
            'mode' => 'timed',
            'etmal_km' => $km,
            'speed_kmh' => round($speed, 1),
            'start' => $start,
            'end' => $end,
            'duration_min' => (int) round($duration / 60),
        ] + $result + [
            'current_kmh' => ['river' => $river, 'tidal' => $tidalKmh, 'river_default' => (float) $rules['river_current_kmh'], 'tidal_default' => (float) $rules['tidal_current_kmh']],
        ];
    }

    /** A current in km/h from the request, null/'' = the admin's default. */
    private function currentKmh(mixed $raw, float $default): float
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_numeric($raw) || $raw < 0 || $raw > self::MAX_CURRENT_KMH) {
            throw new ValidationException('The current must be between 0 and ' . self::MAX_CURRENT_KMH . ' km/h.', 'route_check.current_invalid');
        }

        return round((float) $raw, 1);
    }

    /** @return array{etmal_km: int, default_km: int, min_km: int, max_km: int} */
    private function etmalFor(int $userId): array
    {
        return [
            'etmal_km' => $this->users->getEtmalKm($userId),
            'default_km' => $this->settings->etmalKm(),
            'min_km' => SettingsRepository::ETMAL_MIN_KM,
            'max_km' => SettingsRepository::ETMAL_MAX_KM,
        ];
    }

    private function kilometres(mixed $raw): int
    {
        $km = filter_var($raw, FILTER_VALIDATE_INT);
        if ($raw === null || $raw === '' || $km === false || $km < SettingsRepository::ETMAL_MIN_KM || $km > SettingsRepository::ETMAL_MAX_KM) {
            throw new ValidationException(
                'etmal_km must be a whole number of kilometres from ' . SettingsRepository::ETMAL_MIN_KM . ' to ' . SettingsRepository::ETMAL_MAX_KM . '.',
                'etmal.invalid'
            );
        }

        return $km;
    }
}
