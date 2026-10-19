<?php
/**
 * /admin/wind - the wind layer (tools/wind/): what its job did on its last
 * runs (storage/wind/status.json, rendered by admin-wind.js) and its settings
 * (WindConfig, saved via PUT /api/v1/settings/wind). The regions are the rain
 * radar's (/admin/radar).
 */
$pageTitle = $t('admin.wind.title');
$adminActiveNav = 'wind';
$pageScripts = ['/js/admin-wind.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card" id="windCard">
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
        <div class="row g-3 mb-3 mt-1">
            <div class="col-12 col-md-6">
                <label for="windFillOpacity" class="form-label"><?= $t('admin.wind.fill_opacity') ?>: <strong id="windFillOpacityValue"><?= (int) $windSettings['fill_opacity'] ?> %</strong></label>
                <input type="range" class="form-range" id="windFillOpacity" min="0" max="100" step="1" value="<?= (int) $windSettings['fill_opacity'] ?>">
                <div class="small text-secondary"><?= $t('admin.wind.fill_opacity_hint') ?></div>
            </div>
        </div>
        <p class="small text-secondary"><?= $t('admin.wind.settings_hint') ?></p>
        <button type="button" class="btn btn-primary" id="windSaveBtn"><?= $t('common.save') ?></button>
    </div>
</div>
<script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?>>window.YTAN_WIND_SETTINGS = <?= json_encode($windSettings + [
    'status' => \Ytan\Service\WindConfig::readStatus($rootDir . '/storage/wind'),
    'running' => (new \Ytan\Service\RadarJobRunner($rootDir, 'wind'))->isRunning(),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;</script>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
