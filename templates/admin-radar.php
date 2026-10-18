<?php
/**
 * /admin/radar - the rain radar job (tools/radar/): what it did on its last
 * runs (storage/radar/status.json, rendered by admin-radar.js) and its
 * settings (RadarConfig, saved via PUT /api/v1/settings/radar).
 */
$pageTitle = $t('admin.radar.title');
$adminActiveNav = 'radar';
$pageScripts = ['/js/admin-radar.js', '/js/admin-wind.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';

use Ytan\Service\RadarConfig;

// Countries by translated name; a Collator sorts umlauts right (Österreich under O).
$radarCountryNames = [];
foreach (array_keys(RadarConfig::PRESETS) as $code) {
    $radarCountryNames[$code] = $t('admin.settings.radar_region.' . $code);
}
if (class_exists(\Collator::class)) {
    $collator = new \Collator($translator->locale());
    uasort($radarCountryNames, fn ($a, $b) => $collator->compare($a, $b));
} else {
    $sortKey = fn ($s) => strtr($s, ['Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
    uasort($radarCountryNames, fn ($a, $b) => strcasecmp($sortKey($a), $sortKey($b)));
}
$minuteOptions = function (int $from, int $to, int $step, int $selected, ?string $zeroLabel = null) use ($t): string {
    $html = '';
    for ($m = $from; $m <= $to; $m += $step) {
        $label = ($m === 0 && $zeroLabel !== null) ? $zeroLabel : $t('admin.settings.radar_forecast_minutes', ['min' => $m]);
        $html .= '<option value="' . $m . '"' . ($m === $selected ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
    }
    return $html;
};
?>
<div class="card" id="radarStatusCard">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h2 class="h6"><?= $t('admin.radar.status_title') ?></h2>
            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <button type="button" class="btn btn-primary btn-sm" id="radarRunNow"><i class="bi bi-play-fill"></i> <?= $t('admin.radar.run_now') ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="radarStatusRefresh"><i class="bi bi-arrow-clockwise"></i> <?= $t('admin.radar.status_refresh') ?></button>
            </div>
        </div>
        <div class="alert alert-info small py-2" id="radarRunning" hidden></div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="radarPauseRow">
            <span class="small fw-semibold"><?= $t('admin.radar.fetching') ?>:</span>
            <span class="small" id="radarPauseState"></span>
            <div class="btn-group btn-group-sm ms-sm-auto" role="group" aria-label="<?= htmlspecialchars($t('admin.radar.pause'), ENT_QUOTES) ?>">
                <?php foreach (RadarConfig::PAUSE_MINUTES as $m): ?>
                <button type="button" class="btn btn-outline-warning" data-radar-pause="<?= $m ?>"><?= $t('admin.radar.pause_minutes', ['min' => $m]) ?></button>
                <?php endforeach; ?>
                <button type="button" class="btn btn-outline-danger" data-radar-pause="forever"><?= $t('admin.radar.pause_forever') ?></button>
            </div>
            <button type="button" class="btn btn-success btn-sm" id="radarResume" hidden><i class="bi bi-play-fill"></i> <?= $t('admin.radar.resume') ?></button>
        </div>
        <dl class="row small mb-2">
            <dt class="col-sm-4"><?= $t('admin.radar.current_regions') ?></dt>
            <dd class="col-sm-8" id="radarCurrentRegions"></dd>
            <dt class="col-sm-4"><?= $t('admin.radar.storage_total') ?></dt>
            <dd class="col-sm-8" id="radarStorageTotal"></dd>
            <dt class="col-sm-4"><?= $t('admin.radar.last_run') ?></dt>
            <dd class="col-sm-8" id="radarLastRun"></dd>
        </dl>
        <h3 class="h6 small text-uppercase text-secondary"><?= $t('admin.radar.last_processing') ?></h3>
        <p class="small text-secondary" id="radarNoProcessing" hidden><?= $t('admin.radar.no_processing') ?></p>
        <div class="table-responsive" id="radarProcessingTable">
            <table class="table table-sm small mb-0">
                <tbody>
                    <tr class="table-light"><th colspan="2"><?= $t('admin.radar.overall') ?></th></tr>
                    <tr><td><?= $t('admin.radar.start') ?></td><td id="radarRunStart"></td></tr>
                    <tr><td><?= $t('admin.radar.end') ?></td><td id="radarRunEnd"></td></tr>
                    <tr><td><?= $t('admin.radar.duration') ?></td><td id="radarRunDuration"></td></tr>
                    <tr><td><?= $t('admin.radar.regions_of_run') ?></td><td id="radarRunRegions"></td></tr>
                    <tr class="table-light"><th colspan="2"><?= $t('admin.radar.download') ?></th></tr>
                    <tr><td><?= $t('admin.radar.start') ?></td><td id="radarDownloadStart"></td></tr>
                    <tr><td><?= $t('admin.radar.end') ?></td><td id="radarDownloadEnd"></td></tr>
                    <tr><td><?= $t('admin.radar.duration') ?></td><td id="radarDownloadDuration"></td></tr>
                    <tr><td><?= $t('admin.radar.size') ?></td><td id="radarDownloadSize"></td></tr>
                    <tr class="table-light"><th colspan="2"><?= $t('admin.radar.processing') ?></th></tr>
                    <tr><td><?= $t('admin.radar.start') ?></td><td id="radarProcessingStart"></td></tr>
                    <tr><td><?= $t('admin.radar.end') ?></td><td id="radarProcessingEnd"></td></tr>
                    <tr><td><?= $t('admin.radar.duration') ?></td><td id="radarProcessingDuration"></td></tr>
                    <tr><td><?= $t('admin.radar.workers_used') ?></td><td id="radarProcessingWorkers"></td></tr>
                    <tr><td><?= $t('admin.radar.generated') ?></td><td id="radarProcessingSize"></td></tr>
                    <tr class="table-light"><th colspan="2"><?= $t('admin.radar.finishing') ?></th></tr>
                    <tr><td><?= $t('admin.radar.start') ?></td><td id="radarFinishingStart"></td></tr>
                    <tr><td><?= $t('admin.radar.end') ?></td><td id="radarFinishingEnd"></td></tr>
                    <tr><td><?= $t('admin.radar.duration') ?></td><td id="radarFinishingDuration"></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mt-3" id="radarSettingsCard">
    <div class="card-body">
        <h2 class="h6"><?= $t('admin.radar.settings_title') ?></h2>
        <p class="text-muted small"><?= $t('admin.settings.radar_hint') ?></p>
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <label for="radarHistoryMinutes" class="form-label"><?= $t('admin.radar.history') ?></label>
                <select class="form-select" id="radarHistoryMinutes"><?= $minuteOptions(RadarConfig::HISTORY_MIN_MINUTES, RadarConfig::HISTORY_MAX_MINUTES, 5, $radarSettings['history_minutes']) ?></select>
            </div>
            <div class="col-6 col-md-3">
                <label for="radarForecastMinutes" class="form-label"><?= $t('admin.settings.radar_forecast') ?></label>
                <select class="form-select" id="radarForecastMinutes"><?= $minuteOptions(0, RadarConfig::FORECAST_MAX_MINUTES, RadarConfig::FORECAST_STEP_MINUTES, $radarSettings['forecast_minutes'], $t('admin.settings.radar_forecast_none')) ?></select>
            </div>
            <div class="col-6 col-md-3">
                <label for="radarFetchInterval" class="form-label"><?= $t('admin.radar.interval') ?></label>
                <select class="form-select" id="radarFetchInterval"><?= $minuteOptions(RadarConfig::INTERVAL_MIN_MINUTES, RadarConfig::INTERVAL_MAX_MINUTES, 5, $radarSettings['fetch_interval_minutes']) ?></select>
            </div>
            <div class="col-6 col-md-3">
                <label for="radarWorkers" class="form-label"><?= $t('admin.radar.workers') ?></label>
                <select class="form-select" id="radarWorkers">
                    <?php for ($w = RadarConfig::WORKERS_MIN; $w <= RadarConfig::WORKERS_MAX; $w++): ?>
                    <option value="<?= $w ?>" <?= $w === $radarSettings['workers'] ? 'selected' : '' ?>><?= $w ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        <p class="small text-secondary"><?= $t('admin.radar.interval_hint') ?></p>
        <div class="row g-3 mb-3">
            <div class="col-sm-6 col-md-4">
                <label for="radarMaxMosaic" class="form-label"><?= $t('admin.radar.max_mosaic') ?></label>
                <div class="input-group">
                    <input type="number" class="form-control" id="radarMaxMosaic"
                           min="<?= RadarConfig::MAX_MOSAIC_PIXELS_MIN / 1_000_000 ?>" max="<?= RadarConfig::MAX_MOSAIC_PIXELS_LIMIT / 1_000_000 ?>" step="1"
                           value="<?= (int) round($radarSettings['max_mosaic_pixels'] / 1_000_000) ?>">
                    <span class="input-group-text"><?= $t('admin.radar.max_mosaic_unit') ?></span>
                </div>
            </div>
            <div class="col-sm-6 col-md-8 small text-secondary align-self-end"><?= $t('admin.radar.max_mosaic_hint') ?></div>
        </div>

        <h3 class="h6 small text-uppercase text-secondary"><?= $t('admin.settings.radar_presets') ?></h3>
        <p class="small text-secondary mb-2"><?= $t('admin.settings.radar_presets_hint') ?></p>
        <div class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-1 mb-3" id="radarPresets">
            <?php foreach ($radarCountryNames as $code => $name): ?>
            <div class="col">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="radarPreset-<?= $code ?>" value="<?= $code ?>" <?= in_array($code, $radarSettings['presets'], true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="radarPreset-<?= $code ?>"><?= htmlspecialchars($name) ?></label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <h3 class="h6 small text-uppercase text-secondary"><?= $t('admin.settings.radar_custom') ?></h3>
        <p class="small text-secondary mb-2"><?= $t('admin.settings.radar_custom_hint') ?></p>
        <p class="small text-danger mb-2" id="radarNoKey" hidden><?= $t('admin.lighthouse_import.area_no_key') ?></p>
        <button type="button" class="btn btn-outline-primary btn-sm mb-2" id="radarAddRegion"><i class="bi bi-plus-square"></i> <?= $t('admin.settings.radar_add_region') ?></button>
        <div id="radarMap" class="border rounded mb-2" style="height:50vh;min-height:280px;"></div>
        <ul class="list-group mb-2" id="radarCustomList"></ul>
        <p class="small mb-0" id="radarSize"></p>
        <button type="button" class="btn btn-primary mt-3" id="radarSaveBtn"><?= $t('common.save') ?></button>
    </div>
</div>
<div class="card mt-3" id="windCard">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h2 class="h6"><?= $t('admin.wind.title') ?></h2>
            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <button type="button" class="btn btn-primary btn-sm" id="windRunNow"><i class="bi bi-play-fill"></i> <?= $t('admin.radar.run_now') ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="windStatusRefresh"><i class="bi bi-arrow-clockwise"></i> <?= $t('admin.radar.status_refresh') ?></button>
            </div>
        </div>
        <p class="text-muted small"><?= $t('admin.wind.hint') ?></p>
        <div class="alert alert-info small py-2" id="windRunning" hidden><?= $t('admin.wind.running') ?></div>
        <dl class="row small mb-2">
            <dt class="col-sm-4"><?= $t('admin.wind.model_runs') ?></dt>
            <dd class="col-sm-8" id="windModelRuns"></dd>
            <dt class="col-sm-4"><?= $t('admin.radar.last_run') ?></dt>
            <dd class="col-sm-8" id="windLastRun"></dd>
            <dt class="col-sm-4"><?= $t('admin.wind.last_processed') ?></dt>
            <dd class="col-sm-8" id="windLastProcessed"></dd>
            <dt class="col-sm-4"><?= $t('admin.wind.duration') ?></dt>
            <dd class="col-sm-8" id="windDuration"></dd>
            <dt class="col-sm-4"><?= $t('admin.wind.download') ?></dt>
            <dd class="col-sm-8" id="windDownload"></dd>
            <dt class="col-sm-4"><?= $t('admin.wind.written') ?></dt>
            <dd class="col-sm-8" id="windWritten"></dd>
            <dt class="col-sm-4"><?= $t('admin.wind.storage_total') ?></dt>
            <dd class="col-sm-8" id="windStorage"></dd>
        </dl>

        <h3 class="h6 small text-uppercase text-secondary mt-3"><?= $t('admin.radar.settings_title') ?></h3>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="windEnabled" <?= $windSettings['enabled'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="windEnabled"><?= $t('admin.wind.enabled') ?></label>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-3">
                <label for="windHorizon" class="form-label"><?= $t('admin.wind.horizon') ?></label>
                <select class="form-select" id="windHorizon">
                    <?php foreach (\Ytan\Service\WindConfig::HORIZONS as $h): ?>
                    <option value="<?= $h ?>" <?= $h === $windSettings['horizon_hours'] ? 'selected' : '' ?>><?= $t('admin.wind.hours_option', ['h' => $h]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="windInterval" class="form-label"><?= $t('admin.wind.interval') ?></label>
                <select class="form-select" id="windInterval">
                    <?php foreach (\Ytan\Service\WindConfig::INTERVALS as $h): ?>
                    <option value="<?= $h ?>" <?= $h === $windSettings['run_interval_hours'] ? 'selected' : '' ?>><?= $t('admin.wind.interval_option', ['h' => $h]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="windFine" <?= $windSettings['fine'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="windFine"><?= $t('admin.wind.fine') ?></label>
        </div>
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="windGusts" <?= $windSettings['gusts'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="windGusts"><?= $t('admin.wind.gusts') ?></label>
        </div>
        <p class="small text-secondary"><?= $t('admin.wind.settings_hint') ?></p>
        <button type="button" class="btn btn-primary" id="windSaveBtn"><?= $t('common.save') ?></button>
    </div>
</div>
<script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?>>window.YTAN_MAPS_API_KEY = <?= json_encode($mapsApiKey) ?>;
window.YTAN_RADAR_SETTINGS = <?= json_encode($radarSettings + [
    'status' => RadarConfig::readStatus($rootDir . '/storage/radar'),
    'running' => (new \Ytan\Service\RadarJobRunner($rootDir))->isRunning(),
    'paused' => RadarConfig::isPaused($radarSettings),
    'preset_regions' => RadarConfig::PRESETS,
    'max_mosaic_pixels_min' => RadarConfig::MAX_MOSAIC_PIXELS_MIN,
    'max_mosaic_pixels_limit' => RadarConfig::MAX_MOSAIC_PIXELS_LIMIT,
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
window.YTAN_WIND_SETTINGS = <?= json_encode($windSettings + [
    'status' => \Ytan\Service\WindConfig::readStatus($rootDir . '/storage/wind'),
    'running' => (new \Ytan\Service\RadarJobRunner($rootDir, 'wind'))->isRunning(),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;</script>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
