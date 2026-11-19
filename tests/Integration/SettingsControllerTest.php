<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Domain\Settings\SettingsRepository;
use Ytan\Exception\ForbiddenException;
use Ytan\Exception\UnauthorizedException;
use Ytan\Exception\ValidationException;
use Ytan\Http\Controllers\SettingsController;

/**
 * Covers SettingsController::updateTrackDistanceFilterPresets() and
 * ::updateRouteLabelFontSizeRange() - the admin-gating, validation, and that
 * a successful call actually persists via SettingsRepository. The
 * pre-existing google-search-requires-login toggle had no test of its own
 * before this file; not backfilled here to keep this change scoped to the
 * new endpoints.
 */
final class SettingsControllerTest extends ControllerTestCase
{
    private SettingsController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // app_settings is a singleton-row config table (id = 1) - the test
        // DB is a structure-only clone (bin/setup-test-db.php), so it starts
        // with no rows at all, unlike the seeded live/dev DB.
        $this->pdo->exec('INSERT INTO app_settings (id, google_search_requires_login, track_distance_filter_precise_m, track_distance_filter_balanced_m, track_distance_filter_battery_m, route_label_font_size_min, route_label_font_size_max) VALUES (1, 0, 20, 50, 100, 8, 20)');

        $this->controller = new SettingsController(new SettingsRepository($this->pdo));
    }

    public function testUpdateTrackDistanceFilterPresetsPersistsNewValues(): void
    {
        $userId = $this->createUser();

        $response = $this->controller->updateTrackDistanceFilterPresets(
            $this->request('PUT', '/api/v1/settings/track-distance-filter', $this->authPayload($userId, ['is_admin' => true]), ['precise' => 10, 'balanced' => 30, 'battery' => 200]),
            $this->response()
        );

        $decoded = $this->decode($response);
        $this->assertSame(200, $decoded['status']);
        $this->assertSame(['precise' => 10, 'balanced' => 30, 'battery' => 200], $decoded['data']);

        $stmt = $this->pdo->query('SELECT track_distance_filter_precise_m, track_distance_filter_balanced_m, track_distance_filter_battery_m FROM app_settings WHERE id = 1');
        $row = $stmt->fetch();
        $this->assertSame(10, (int) $row['track_distance_filter_precise_m']);
        $this->assertSame(30, (int) $row['track_distance_filter_balanced_m']);
        $this->assertSame(200, (int) $row['track_distance_filter_battery_m']);
    }

    public function testUpdateTrackDistanceFilterPresetsRejectsNonPositiveValues(): void
    {
        $userId = $this->createUser();
        $this->expectException(ValidationException::class);

        $this->controller->updateTrackDistanceFilterPresets(
            $this->request('PUT', '/api/v1/settings/track-distance-filter', $this->authPayload($userId, ['is_admin' => true]), ['precise' => 0, 'balanced' => 30, 'battery' => 200]),
            $this->response()
        );
    }

    public function testUpdateTrackDistanceFilterPresetsRejectsNonNumericValues(): void
    {
        $userId = $this->createUser();
        $this->expectException(ValidationException::class);

        $this->controller->updateTrackDistanceFilterPresets(
            $this->request('PUT', '/api/v1/settings/track-distance-filter', $this->authPayload($userId, ['is_admin' => true]), ['precise' => 'abc', 'balanced' => 30, 'battery' => 200]),
            $this->response()
        );
    }

    public function testUpdateTrackDistanceFilterPresetsRejectsANonAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->controller->updateTrackDistanceFilterPresets(
            $this->request('PUT', '/api/v1/settings/track-distance-filter', $this->authPayload(1, ['is_admin' => false]), ['precise' => 10, 'balanced' => 30, 'battery' => 200]),
            $this->response()
        );
    }

    public function testUpdateTrackDistanceFilterPresetsRejectsAnUnauthenticatedRequest(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->controller->updateTrackDistanceFilterPresets(
            $this->request('PUT', '/api/v1/settings/track-distance-filter', null, ['precise' => 10, 'balanced' => 30, 'battery' => 200]),
            $this->response()
        );
    }

    public function testUpdateRouteLabelFontSizeRangePersistsNewValues(): void
    {
        $userId = $this->createUser();

        $response = $this->controller->updateRouteLabelFontSizeRange(
            $this->request('PUT', '/api/v1/settings/route-label-font-size-range', $this->authPayload($userId, ['is_admin' => true]), ['min' => 10, 'max' => 24]),
            $this->response()
        );

        $decoded = $this->decode($response);
        $this->assertSame(200, $decoded['status']);
        $this->assertSame(['min' => 10, 'max' => 24], $decoded['data']);

        $stmt = $this->pdo->query('SELECT route_label_font_size_min, route_label_font_size_max FROM app_settings WHERE id = 1');
        $row = $stmt->fetch();
        $this->assertSame(10, (int) $row['route_label_font_size_min']);
        $this->assertSame(24, (int) $row['route_label_font_size_max']);
    }

    public function testUpdateRouteLabelFontSizeRangeRejectsMinNotLessThanMax(): void
    {
        $userId = $this->createUser();
        $this->expectException(ValidationException::class);

        $this->controller->updateRouteLabelFontSizeRange(
            $this->request('PUT', '/api/v1/settings/route-label-font-size-range', $this->authPayload($userId, ['is_admin' => true]), ['min' => 20, 'max' => 20]),
            $this->response()
        );
    }

    public function testUpdateRouteLabelFontSizeRangeRejectsNonNumericValues(): void
    {
        $userId = $this->createUser();
        $this->expectException(ValidationException::class);

        $this->controller->updateRouteLabelFontSizeRange(
            $this->request('PUT', '/api/v1/settings/route-label-font-size-range', $this->authPayload($userId, ['is_admin' => true]), ['min' => 'abc', 'max' => 20]),
            $this->response()
        );
    }

    public function testUpdateRouteLabelFontSizeRangeRejectsANonAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->controller->updateRouteLabelFontSizeRange(
            $this->request('PUT', '/api/v1/settings/route-label-font-size-range', $this->authPayload(1, ['is_admin' => false]), ['min' => 10, 'max' => 24]),
            $this->response()
        );
    }

    public function testUpdateRouteLabelFontSizeRangeRejectsAnUnauthenticatedRequest(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->controller->updateRouteLabelFontSizeRange(
            $this->request('PUT', '/api/v1/settings/route-label-font-size-range', null, ['min' => 10, 'max' => 24]),
            $this->response()
        );
    }

    public function testRouteAnimationHasDefaultsAndPersistsNewValues(): void
    {
        $repository = new SettingsRepository($this->pdo);
        $this->assertSame(['color' => '#FF6A00', 'line_width' => 6, 'zoom' => 15.0, 'end_delay_s' => 2.0, 'speed' => 250], $repository->routeAnimationSettings(), 'migration 064/065 defaults');

        $response = $this->controller->updateRouteAnimation(
            $this->request('PUT', '/api/v1/settings/route-animation', $this->authPayload($this->createUser(), ['is_admin' => true]),
                ['color' => '#00aa33', 'line_width' => 9, 'zoom' => '16.25', 'end_delay_s' => 1.5, 'speed' => '400']),
            $this->response()
        );

        $decoded = $this->decode($response);
        $this->assertSame(200, $decoded['status']);
        $expected = ['color' => '#00AA33', 'line_width' => 9, 'zoom' => 16.3, 'end_delay_s' => 1.5, 'speed' => 400];
        $this->assertSame($expected, $decoded['data'], 'colour upper-cased, zoom rounded to one decimal');
        // a fresh instance: each one keeps the row it read (one SELECT per request)
        $this->assertSame($expected, (new SettingsRepository($this->pdo))->routeAnimationSettings());
    }

    /** @return iterable<string, array{0: array<string,mixed>}> */
    public static function invalidRouteAnimationBodies(): iterable
    {
        $valid = ['color' => '#FF6A00', 'line_width' => 6, 'zoom' => 15, 'end_delay_s' => 2, 'speed' => 250];
        yield 'colour name' => [['color' => 'red'] + $valid];
        yield 'short colour' => [['color' => '#F60'] + $valid];
        yield 'width 0' => [['line_width' => 0] + $valid];
        yield 'width with decimals' => [['line_width' => 2.5] + $valid];
        yield 'zoom too far out' => [['zoom' => 2] + $valid];
        yield 'zoom too far in' => [['zoom' => 22] + $valid];
        yield 'negative delay' => [['end_delay_s' => -1] + $valid];
        yield 'delay missing' => [array_diff_key($valid, ['end_delay_s' => 0])];
        yield 'speed too slow' => [['speed' => 9] + $valid];
        yield 'speed too fast' => [['speed' => 2001] + $valid];
        yield 'speed missing' => [array_diff_key($valid, ['speed' => 0])];
    }

    /** @dataProvider invalidRouteAnimationBodies */
    public function testUpdateRouteAnimationRejectsInvalidValues(array $body): void
    {
        $this->expectException(ValidationException::class);

        $this->controller->updateRouteAnimation(
            $this->request('PUT', '/api/v1/settings/route-animation', $this->authPayload($this->createUser(), ['is_admin' => true]), $body),
            $this->response()
        );
    }

    public function testUpdateRouteAnimationRejectsANonAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->controller->updateRouteAnimation(
            $this->request('PUT', '/api/v1/settings/route-animation', $this->authPayload(1, ['is_admin' => false]), ['color' => '#FF6A00', 'line_width' => 6, 'zoom' => 15, 'end_delay_s' => 2, 'speed' => 250]),
            $this->response()
        );
    }

    public function testArSettingsHaveDefaultsAndPersistClamped(): void
    {
        $this->assertSame(['radius_m' => 2000, 'radius_max_m' => 10000, 'max_pois' => 10, 'max_pois_limit' => 30, 'route_range_m' => 3000, 'route_range_max_m' => 10000],
            (new SettingsRepository($this->pdo))->arSettings(), 'migration 075/076 defaults');

        $decoded = $this->decode($this->controller->updateAr(
            $this->request('PUT', '/api/v1/settings/ar', $this->authPayload($this->createUser(), ['is_admin' => true]),
                ['radius_m' => 3000, 'radius_max_m' => '8000', 'max_pois' => 12, 'max_pois_limit' => 20, 'route_range_m' => 5000, 'route_range_max_m' => 15000]),
            $this->response()
        ));

        $expected = ['radius_m' => 3000, 'radius_max_m' => 8000, 'max_pois' => 12, 'max_pois_limit' => 20, 'route_range_m' => 5000, 'route_range_max_m' => 15000];
        $this->assertSame(200, $decoded['status']);
        $this->assertSame($expected, $decoded['data']);
        $this->assertSame($expected, (new SettingsRepository($this->pdo))->arSettings());
    }

    public function testArDefaultsAboveTheirLimitAndOutOfRangeValuesAreClamped(): void
    {
        $decoded = $this->decode($this->controller->updateAr(
            $this->request('PUT', '/api/v1/settings/ar', $this->authPayload($this->createUser(), ['is_admin' => true]),
                ['radius_m' => 9000, 'radius_max_m' => 999999, 'max_pois' => 40, 'max_pois_limit' => 0, 'route_range_m' => 50, 'route_range_max_m' => 90000]),
            $this->response()
        ));

        // limit 999999 m -> 50 km, default stays 9 km; POI limit 0 -> 1, default 40 -> lowered to the limit;
        // route range 50 m -> 200 m minimum, its limit 90 km -> 30 km
        $this->assertSame(['radius_m' => 9000, 'radius_max_m' => 50000, 'max_pois' => 1, 'max_pois_limit' => 1, 'route_range_m' => 200, 'route_range_max_m' => 30000], $decoded['data']);
        $this->assertSame(['radius_m' => 500, 'radius_max_m' => 500, 'max_pois' => 5, 'max_pois_limit' => 50, 'route_range_m' => 1000, 'route_range_max_m' => 1000],
            SettingsRepository::clampAr(800, 500, 5, 60, 4000, 1000), 'default lowered to its limit');
    }

    public function testUpdateArRejectsMissingOrFractionalValuesAndNonAdmins(): void
    {
        $admin = $this->authPayload($this->createUser(), ['is_admin' => true]);
        $valid = ['radius_m' => 2000, 'radius_max_m' => 10000, 'max_pois' => 10, 'max_pois_limit' => 30, 'route_range_m' => 3000, 'route_range_max_m' => 10000];
        foreach ([array_diff_key($valid, ['max_pois_limit' => 0]), array_diff_key($valid, ['route_range_max_m' => 0]), ['radius_m' => 2000.5] + $valid] as $body) {
            try {
                $this->controller->updateAr($this->request('PUT', '/api/v1/settings/ar', $admin, $body), $this->response());
                $this->fail('expected a ValidationException');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ForbiddenException::class);
        $this->controller->updateAr(
            $this->request('PUT', '/api/v1/settings/ar', $this->authPayload(1, ['is_admin' => false]), $valid),
            $this->response()
        );
    }

    public function testRouteMergeMidpointDefaultsToTenAndPersists(): void
    {
        $repo = new SettingsRepository($this->pdo);
        $this->assertSame(10, $repo->routeMergeMidpointMeters(), 'column default');

        $decoded = $this->decode($this->controller->updateRouteMerge(
            $this->request('PUT', '/api/v1/settings/route-merge', $this->authPayload($this->createUser(), ['is_admin' => true]), ['midpoint_m' => 25]),
            $this->response()
        ));

        $this->assertSame(['midpoint_m' => 25], $decoded['data']);
        // a fresh instance: each one keeps the row it read (one SELECT per request)
        $this->assertSame(25, (new SettingsRepository($this->pdo))->routeMergeMidpointMeters());
    }

    public function testRouteMergeMidpointRejectsOutOfRangeValues(): void
    {
        foreach ([-1, 1001, 'abc', null, 2.5] as $value) {
            try {
                $this->controller->updateRouteMerge(
                    $this->request('PUT', '/api/v1/settings/route-merge', $this->authPayload($this->createUser(), ['is_admin' => true]), ['midpoint_m' => $value]),
                    $this->response()
                );
                $this->fail('Accepted ' . var_export($value, true));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRouteSmoothingShareDefaultsToTenAndPersists(): void
    {
        $repo = new SettingsRepository($this->pdo);
        $this->assertSame(10, $repo->routeSmoothingSharePercent(), 'column default');

        $decoded = $this->decode($this->controller->updateRouteSmoothing(
            $this->request('PUT', '/api/v1/settings/route-smoothing', $this->authPayload($this->createUser(), ['is_admin' => true]), ['share_pct' => 0]),
            $this->response()
        ));

        $this->assertSame(['share_pct' => 0], $decoded['data']);
        $this->assertSame(0, (new SettingsRepository($this->pdo))->routeSmoothingSharePercent());
    }

    public function testRouteSmoothingShareRejectsOutOfRangeValues(): void
    {
        foreach ([-1, 51, 'abc', null, 2.5] as $value) {
            try {
                $this->controller->updateRouteSmoothing(
                    $this->request('PUT', '/api/v1/settings/route-smoothing', $this->authPayload($this->createUser(), ['is_admin' => true]), ['share_pct' => $value]),
                    $this->response()
                );
                $this->fail('Accepted ' . var_export($value, true));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRouteSmoothingShareRejectsANonAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->controller->updateRouteSmoothing(
            $this->request('PUT', '/api/v1/settings/route-smoothing', $this->authPayload(1, ['is_admin' => false]), ['share_pct' => 20]),
            $this->response()
        );
    }

    public function testRouteMergeMidpointRejectsANonAdmin(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->controller->updateRouteMerge(
            $this->request('PUT', '/api/v1/settings/route-merge', $this->authPayload(1, ['is_admin' => false]), ['midpoint_m' => 5]),
            $this->response()
        );
    }
}
