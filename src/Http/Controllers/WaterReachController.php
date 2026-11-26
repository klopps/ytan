<?php

declare(strict_types=1);

namespace Ytan\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\User\UserRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\RequestThrottle;
use Ytan\Service\Water\WaterReach;

/**
 * Etmal: the user's distance per day (GET/PUT /auth/etmal) and the reach lines
 * over water from a point of the map (POST /water/reach, WaterReach).
 */
final class WaterReachController extends BaseController
{
    public function __construct(
        private readonly WaterReach $reach,
        private readonly UserRepository $users,
        private readonly SettingsRepository $settings,
        private readonly RequestThrottle $throttle,
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

    /** Body {lat, lng}: the reach lines of the user's etmal from there. */
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

        $km = $this->users->getEtmalKm($userId);
        $km = max(SettingsRepository::ETMAL_MIN_KM, min(SettingsRepository::ETMAL_MAX_KM, $km));
        $result = $this->reach->reach((float) $lat, (float) $lng, (float) $km);
        if ($result['status'] !== 'ok') {
            throw new ValidationException('No reach lines from this position: ' . $result['status'] . '.', 'etmal.' . $result['status']);
        }
        unset($result['status']);

        return $this->json($response, ['data' => $result]);
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
