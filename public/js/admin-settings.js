/**
 * Logic for /admin/settings (templates/admin-settings.php) - the site-wide
 * settings: on/off switches saved immediately (Google search requires
 * login, GPX export for everyone) and the track-filter/font-size value
 * groups with their own Save buttons.
 */

function toggleGoogleSearchRequiresLogin(checkbox) {
    toggleSetting(checkbox, '/settings/google-search-requires-login');
}

function toggleGpxExportPublic(checkbox) {
    toggleSetting(checkbox, '/settings/gpx-export-public');
}

/**
 * Saves one on/off site setting right away; reverts the switch if the
 * request fails.
 */
function toggleSetting(checkbox, path) {
    var enabled = checkbox.checked;
    Ytan.put(path, { enabled: enabled }).then(function () {
        showAdminToast(t('common.saved'), 'success');
    }).catch(function (err) {
        checkbox.checked = !enabled;
        showAdminToast(t('common.save_failed', { error: err.message }), 'error');
    });
}

function saveTrackDistanceFilterPresets() {
    var btn = document.getElementById('settingTrackFilterSaveBtn');
    var body = {
        precise: parseInt(document.getElementById('settingTrackFilterPrecise').value, 10),
        balanced: parseInt(document.getElementById('settingTrackFilterBalanced').value, 10),
        battery: parseInt(document.getElementById('settingTrackFilterBattery').value, 10)
    };
    btn.disabled = true;
    Ytan.put('/settings/track-distance-filter', body).then(function () {
        showAdminToast(t('common.saved'), 'success');
    }).catch(function (err) {
        showAdminToast(t('common.save_failed', { error: err.message }), 'error');
    }).finally(function () {
        btn.disabled = false;
    });
}

function saveRouteLabelFontSizeRange() {
    var btn = document.getElementById('settingRouteLabelFontSizeSaveBtn');
    var body = {
        min: parseInt(document.getElementById('settingRouteLabelFontSizeMin').value, 10),
        max: parseInt(document.getElementById('settingRouteLabelFontSizeMax').value, 10)
    };
    btn.disabled = true;
    Ytan.put('/settings/route-label-font-size-range', body).then(function () {
        showAdminToast(t('common.saved'), 'success');
    }).catch(function (err) {
        showAdminToast(t('common.save_failed', { error: err.message }), 'error');
    }).finally(function () {
        btn.disabled = false;
    });
}

function initSettingsPage() {
    document.getElementById('settingGoogleSearchRequiresLogin').addEventListener('change', function () {
        toggleGoogleSearchRequiresLogin(this);
    });
    document.getElementById('settingGpxExportPublic').addEventListener('change', function () {
        toggleGpxExportPublic(this);
    });
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initSettingsPage });
