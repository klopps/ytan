<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Domain\User\UserRepository;

/**
 * Autorouting with the user's SAVED preferences (shore distance, largest
 * distance, on/off - what the autorouting dialog stored, else the admin's
 * defaults) and without any dialog: used where no dialog fits - single
 * segments and the live mode of the route editor, the GPX import. A start or
 * end that cannot be used (on land, outside the water data) is not an error
 * here: the line comes back unchanged with warning 'not_usable'.
 */
final class AutorouteApplier
{
    public function __construct(
        private readonly Autorouter $router,
        private readonly UserRepository $users,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $points [lat, lng], at least 2
     * @return array{points: list<array{0: float, 1: float}>, changed_sections: int, length_before: float, length_after: float, warnings: list<array<string, mixed>>}
     */
    public function apply(int $userId, array $points): array
    {
        $problems = $this->router->check($points);
        if ($problems !== []) {
            $length = Autorouter::length($points);

            return [
                'points' => $points,
                'changed_sections' => 0,
                'length_before' => $length,
                'length_after' => $length,
                'warnings' => [['code' => 'not_usable', 'problems' => $problems]],
            ];
        }

        $prefs = $this->users->getAutoroutePrefs($userId);
        $shore = (float) ($prefs['shore_m'] ?? $this->settings->autorouteShoreMeters());
        $max = (float) ($prefs['max_m'] ?? $this->settings->autorouteShoreMaxMeters());

        return $this->router->route($points, $prefs['enabled'] ? $shore : null, $prefs['enabled'] ? $max : null);
    }

    /** Metres of the line that lie over land (see Autorouter::landMeters()). */
    public function landMeters(array $points): float
    {
        return $this->router->landMeters($points);
    }

    public function check(array $points): array
    {
        return $this->router->check($points);
    }
}
