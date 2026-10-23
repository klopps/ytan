<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

use Ytan\Domain\Poi\PoiWaterDistanceRepository;
use Ytan\Domain\Settings\SettingsRepository;

/**
 * Keeps poi_water_distance up to date for the POI types the admin enabled
 * (app_settings.water_distance_poitypes, /admin/settings): computed when such
 * a POI is created or moved, removed when it changes to a type that isn't
 * enabled. Never fatal - a missing index or a failure leaves the POI as it is.
 */
final class PoiWaterDistanceService
{
    public function __construct(
        private readonly WaterDistanceService $water,
        private readonly PoiWaterDistanceRepository $distances,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * @param array<string,mixed> $poi the saved POI (id, poitype_id, latitude, longitude)
     * @param array<string,mixed>|null $before the POI before an update, null for a new one
     */
    public function afterSave(array $poi, ?array $before = null): void
    {
        try {
            $id = (int) $poi['id'];
            if (!in_array((int) $poi['poitype_id'], $this->settings->waterDistancePoiTypes(), true)) {
                if ($before !== null) {
                    $this->distances->delete($id);
                }

                return;
            }
            $moved = $before === null
                || (float) $before['latitude'] !== (float) $poi['latitude']
                || (float) $before['longitude'] !== (float) $poi['longitude'];
            if ($moved || $this->distances->find($id) === null) {
                $this->compute($poi);
            }
        } catch (\Throwable $e) {
            error_log('YTAN: water distance for POI ' . ($poi['id'] ?? '?') . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Computes and stores the distances of one POI; false when there is no index.
     *
     * @param array<string,mixed> $poi id, latitude, longitude
     */
    public function compute(array $poi): bool
    {
        if (!$this->water->available()) {
            return false;
        }
        // Outside the index's countries every value would be wrong - none at all.
        if (!$this->water->covers((float) $poi['latitude'], (float) $poi['longitude'])) {
            $this->distances->delete((int) $poi['id']);

            return true;
        }
        $this->distances->save(
            (int) $poi['id'],
            $this->water->distances((float) $poi['latitude'], (float) $poi['longitude']),
            $this->water->version()
        );

        return true;
    }

    /**
     * Stores distances computed elsewhere (e.g. while staging an import) -
     * not for a POI outside the index's countries (see compute()).
     *
     * @param array<string,mixed> $poi id, latitude, longitude
     * @param array{sea:?int, river:?int, lake:?int} $distances
     */
    public function store(array $poi, array $distances): void
    {
        if (!$this->water->covers((float) $poi['latitude'], (float) $poi['longitude'])) {
            return;
        }
        $this->distances->save((int) $poi['id'], $distances, $this->water->version());
    }
}
