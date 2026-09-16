/**
 * Logic for the /admin dashboard (templates/admin.php) - fetches GET
 * /api/v1/admin/dashboard-stats (AdminController::dashboardStats()) and
 * renders one stat tile per entity type/visibility, plus a users total.
 */

function statTile(iconClass, colorClass, value, label) {
    return (
        '<div class="col-lg-3 col-6">' +
            '<div class="small-box text-bg-' + colorClass + '">' +
                '<div class="inner">' +
                    '<h3>' + value + '</h3>' +
                    '<p>' + label + '</p>' +
                '</div>' +
                '<i class="small-box-icon bi ' + iconClass + '"></i>' +
            '</div>' +
        '</div>'
    );
}

async function loadDashboardStats() {
    var container = document.getElementById('adminDashboardStats');
    try {
        var answer = await Ytan.get('/admin/dashboard-stats');
        var data = answer.data;

        container.innerHTML =
            statTile('bi-geo-alt-fill', 'primary', data.poi.public, t('admin.dashboard.pois_public')) +
            statTile('bi-geo-alt', 'secondary', data.poi.private, t('admin.dashboard.pois_private')) +
            statTile('bi-signpost-split-fill', 'primary', data.route.public, t('admin.dashboard.routes_public')) +
            statTile('bi-signpost-split', 'secondary', data.route.private, t('admin.dashboard.routes_private')) +
            statTile('bi-hexagon-fill', 'primary', data.area.public, t('admin.dashboard.areas_public')) +
            statTile('bi-hexagon', 'secondary', data.area.private, t('admin.dashboard.areas_private')) +
            statTile('bi-flag-fill', 'primary', data.tour.public, t('admin.dashboard.tours_public')) +
            statTile('bi-flag', 'secondary', data.tour.private, t('admin.dashboard.tours_private')) +
            statTile('bi-people-fill', 'info', data.users.total, t('admin.dashboard.users_total'));
    } catch (err) {
        container.innerHTML = '<div class="col-12"><p class="text-danger">' + t('admin.dashboard.load_failed', { error: err.message }) + '</p></div>';
    }
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: loadDashboardStats });
