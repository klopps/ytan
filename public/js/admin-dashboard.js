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

/**
 * The water index tile: its age in full months (days when younger than a month), green up to 12 months,
 * yellow up to 13, red beyond (WaterIndexAge::describe()); grey "no index" when there is none.
 */
function waterIndexTile(info) {
    if (!info) {
        return statTile('bi-water', 'secondary', escapeHTML(t('admin.dashboard.water_index_none')), escapeHTML(t('admin.dashboard.water_index')));
    }
    var colors = { ok: 'success', warn: 'warning', old: 'danger' };
    var value;
    if (info.age_months >= 1) {
        value = info.age_months === 1 ? t('admin.dashboard.water_index_month_one') : t('admin.dashboard.water_index_months', { n: info.age_months });
    } else {
        value = info.age_days === 1 ? t('admin.dashboard.water_index_day_one') : t('admin.dashboard.water_index_days', { n: info.age_days });
    }
    var built = new Date(info.built_at).toLocaleDateString(window.YTAN_LOCALE || undefined, { day: '2-digit', month: '2-digit', year: 'numeric' });
    return statTile('bi-water', colors[info.state] || 'secondary', escapeHTML(value), escapeHTML(t('admin.dashboard.water_index_built', { date: built })));
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
            statTile('bi-people-fill', 'info', data.users.total, t('admin.dashboard.users_total')) +
            waterIndexTile(data.water_index);
    } catch (err) {
        container.innerHTML = '<div class="col-12"><p class="text-danger">' + t('admin.dashboard.load_failed', { error: err.message }) + '</p></div>';
    }
}

/**
 * The "Probleme" card: GET /api/v1/admin/health (AdminHealthController /
 * AdminHealthService) - failed or stalled jobs, stale radar/wind data, a
 * silent YTAN worker, failed OSM imports, cleanup cron jobs not running.
 * Reloaded every minute while the page is visible.
 */
function healthText(issue) {
    var v = issue.vars || {};
    var vars = {};
    Object.keys(v).forEach(function (key) {
        var value = v[key];
        // ISO times: local date/time plus how long ago
        vars[key] = (typeof value === 'string' && /^\d{4}-\d\d-\d\dT\d\d:\d\d/.test(value)) ? healthTime(value) : (value === null ? '' : String(value));
    });
    switch (issue.code) {
    case 'cron.never': return t('admin.health.cron.never', vars);
    case 'cron.silent': return t('admin.health.cron.silent', vars);
    case 'radar.paused': return t('admin.health.radar.paused', vars);
    case 'radar.paused_forever': return t('admin.health.radar.paused_forever', vars);
    case 'radar.never': return t('admin.health.radar.never', vars);
    case 'radar.failed': return t('admin.health.radar.failed', vars);
    case 'radar.silent': return t('admin.health.radar.silent', vars);
    case 'radar.no_data': return t('admin.health.radar.no_data', vars);
    case 'radar.stale': return t('admin.health.radar.stale', vars);
    case 'wind.never': return t('admin.health.wind.never', vars);
    case 'wind.failed': return t('admin.health.wind.failed', vars);
    case 'wind.silent': return t('admin.health.wind.silent', vars);
    case 'wind.no_data': return t('admin.health.wind.no_data', vars);
    case 'wind.stale': return vars.until ? t('admin.health.wind.stale_until', vars) : t('admin.health.wind.stale', vars);
    case 'worker.none_alive': return t('admin.health.worker.none_alive', vars);
    case 'worker.failover': return t('admin.health.worker.failover', vars);
    case 'worker.name_conflict': return t('admin.health.worker.name_conflict', vars);
    case 'worker.unknown': return t('admin.health.worker.unknown', vars);
    case 'worker.silent': return t('admin.health.worker.silent', vars);
    case 'worker.error': return t('admin.health.worker.error', vars);
    case 'worker.job_failed': return t('admin.health.worker.job_failed', vars);
    case 'osm.failed': return t('admin.health.osm.failed', vars);
    case 'osm.interrupted': return t('admin.health.osm.interrupted', vars);
    case 'osm.failed_tiles': return t('admin.health.osm.failed_tiles', vars);
    case 'weather.old_rows': return t('admin.health.weather.old_rows', vars);
    case 'waterindex.aging': return t('admin.health.waterindex.aging', vars);
    case 'waterindex.outdated': return t('admin.health.waterindex.outdated', vars);
    case 'cleanup.never': return t('admin.health.cleanup.never', vars);
    case 'cleanup.silent': return t('admin.health.cleanup.silent', vars);
    case 'cleanup.failed': return t('admin.health.cleanup.failed', vars);
    case 'errorlog.errors': return t('admin.health.errorlog.errors', vars);
    case 'errorlog.warnings': return t('admin.health.errorlog.warnings', vars);
    case 'errorlog.none': return t('admin.health.errorlog.none', vars);
    default: return issue.code;
    }
}

function healthTime(iso) {
    var date = new Date(iso);
    if (isNaN(date.getTime())) {
        return iso;
    }
    var minutes = Math.max(0, Math.round((Date.now() - date.getTime()) / 60000));
    var ago = minutes < 120 ? t('admin.health.ago_minutes', { n: minutes })
        : minutes < 2880 ? t('admin.health.ago_hours', { n: Math.round(minutes / 60) })
        : t('admin.health.ago_days', { n: Math.round(minutes / 1440) });
    if (date.getTime() > Date.now()) {
        ago = '';
    }
    return date.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' }) + (ago ? ' (' + ago + ')' : '');
}

function healthAdminUrl(page) {
    var base = (window.YTAN_API_BASE || '/api/v1').replace(/\/api\/v1\/?$/, '');
    return base + '/admin/' + page;
}

async function loadDashboardHealth() {
    var list = document.getElementById('adminDashboardHealthList');
    if (!list) {
        return;
    }
    try {
        var issues = (await Ytan.get('/admin/health')).data.issues;
        if (!issues.length) {
            list.innerHTML = '<li class="list-group-item text-success"><i class="bi bi-check-circle-fill me-2"></i>' + escapeHTML(t('admin.health.none')) + '</li>';
            return;
        }
        var icons = { error: 'bi-x-octagon-fill text-danger', warning: 'bi-exclamation-triangle-fill text-warning', info: 'bi-info-circle-fill text-info' };
        list.innerHTML = issues.map(function (issue) {
            var link = issue.page
                ? ' <a class="ms-1 small text-nowrap" href="' + escapeHTML(healthAdminUrl(issue.page)) + '">' + escapeHTML(t('admin.health.open')) + '</a>'
                : '';
            return '<li class="list-group-item d-flex align-items-start">' +
                '<i class="bi ' + (icons[issue.severity] || icons.info) + ' me-2 mt-1"></i>' +
                '<span>' + escapeHTML(healthText(issue)) + link + '</span>' +
                '</li>';
        }).join('');
    } catch (err) {
        list.innerHTML = '<li class="list-group-item text-danger">' + escapeHTML(t('admin.health.load_failed', { error: err.message })) + '</li>';
    }
}

function loadDashboard() {
    loadDashboardStats();
    loadDashboardHealth();
    setInterval(function () {
        if (!document.hidden) {
            loadDashboardHealth();
        }
    }, 60000);
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: loadDashboard });
