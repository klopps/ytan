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

function initSettingsPage() {
    document.getElementById('settingGoogleSearchRequiresLogin').addEventListener('change', function () {
        toggleGoogleSearchRequiresLogin(this);
    });
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initSettingsPage });
