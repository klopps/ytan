/**
 * Logic for /admin/settings (templates/admin-settings.php) - currently just
 * the site-wide "Google search requires login" toggle, moved here (from
 * the /admin dashboard) so future site-wide settings have a dedicated home
 * instead of crowding the dashboard.
 */

function toggleGoogleSearchRequiresLogin(checkbox) {
    var enabled = checkbox.checked;
    Ytan.put('/settings/google-search-requires-login', { enabled: enabled }).then(function () {
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

function initSettingsPage() {
    document.getElementById('settingGoogleSearchRequiresLogin').addEventListener('change', function () {
        toggleGoogleSearchRequiresLogin(this);
    });
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initSettingsPage });
