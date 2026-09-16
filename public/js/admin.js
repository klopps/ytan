/**
 * Logic for the standalone /admin menu page (templates/admin.php) - the
 * entry point for admin-only tools. Login-gate handled entirely by
 * admin-auth.js (initAdminAuth()) - this file just wires it to this page's
 * content element, plus the "Google search requires login" site-wide toggle
 * (moved here from the main app's drawer - it's a site setting, not a map
 * feature, so it belongs on this page rather than requiring the main SPA).
 */

function toggleGoogleSearchRequiresLogin(checkbox) {
    var enabled = checkbox.checked;
    Ytan.put('/settings/google-search-requires-login', { enabled: enabled }).catch(function (err) {
        checkbox.checked = !enabled;
        window.alert('Failed to save: ' + err.message);
    });
}

document.getElementById('settingGoogleSearchRequiresLogin').addEventListener('change', function () {
    toggleGoogleSearchRequiresLogin(this);
});

initAdminAuth({ contentId: 'adminMenu' });
