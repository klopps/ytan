<?php
$pageTitle = $t('admin.settings.title');
$adminActiveNav = 'settings';
$pageScripts = ['/js/admin-settings.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card">
    <div class="card-body">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="settingGoogleSearchRequiresLogin" <?= $googleSearchRequiresLogin ? 'checked' : '' ?>>
            <label class="form-check-label" for="settingGoogleSearchRequiresLogin"><?= $t('admin.settings.google_search_requires_login') ?></label>
        </div>
    </div>
</div>
<div class="card mt-3">
    <div class="card-body">
        <h2 class="h6"><?= $t('admin.settings.track_distance_filter_title') ?></h2>
        <p class="text-muted small"><?= $t('admin.settings.track_distance_filter_hint') ?></p>
        <div class="row g-3">
            <div class="col-sm-4">
                <label for="settingTrackFilterPrecise" class="form-label"><?= $t('admin.settings.track_distance_filter_precise') ?></label>
                <div class="input-group">
                    <input type="number" min="1" max="65535" step="1" class="form-control" id="settingTrackFilterPrecise" value="<?= (int) $trackDistanceFilterPresets['precise'] ?>">
                    <span class="input-group-text">m</span>
                </div>
            </div>
            <div class="col-sm-4">
                <label for="settingTrackFilterBalanced" class="form-label"><?= $t('admin.settings.track_distance_filter_balanced') ?></label>
                <div class="input-group">
                    <input type="number" min="1" max="65535" step="1" class="form-control" id="settingTrackFilterBalanced" value="<?= (int) $trackDistanceFilterPresets['balanced'] ?>">
                    <span class="input-group-text">m</span>
                </div>
            </div>
            <div class="col-sm-4">
                <label for="settingTrackFilterBattery" class="form-label"><?= $t('admin.settings.track_distance_filter_battery') ?></label>
                <div class="input-group">
                    <input type="number" min="1" max="65535" step="1" class="form-control" id="settingTrackFilterBattery" value="<?= (int) $trackDistanceFilterPresets['battery'] ?>">
                    <span class="input-group-text">m</span>
                </div>
            </div>
        </div>
        <button type="button" class="btn btn-primary mt-3" id="settingTrackFilterSaveBtn" onclick="saveTrackDistanceFilterPresets();"><?= $t('common.save') ?></button>
    </div>
</div>
<div class="card mt-3">
    <div class="card-body">
        <h2 class="h6"><?= $t('admin.settings.route_label_font_size_title') ?></h2>
        <p class="text-muted small"><?= $t('admin.settings.route_label_font_size_hint') ?></p>
        <div class="row g-3">
            <div class="col-sm-4">
                <label for="settingRouteLabelFontSizeMin" class="form-label"><?= $t('admin.settings.route_label_font_size_min') ?></label>
                <div class="input-group">
                    <input type="number" min="1" max="255" step="1" class="form-control" id="settingRouteLabelFontSizeMin" value="<?= (int) $routeLabelFontSizeRange['min'] ?>">
                    <span class="input-group-text">px</span>
                </div>
            </div>
            <div class="col-sm-4">
                <label for="settingRouteLabelFontSizeMax" class="form-label"><?= $t('admin.settings.route_label_font_size_max') ?></label>
                <div class="input-group">
                    <input type="number" min="1" max="255" step="1" class="form-control" id="settingRouteLabelFontSizeMax" value="<?= (int) $routeLabelFontSizeRange['max'] ?>">
                    <span class="input-group-text">px</span>
                </div>
            </div>
        </div>
        <button type="button" class="btn btn-primary mt-3" id="settingRouteLabelFontSizeSaveBtn" onclick="saveRouteLabelFontSizeRange();"><?= $t('common.save') ?></button>
    </div>
</div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
