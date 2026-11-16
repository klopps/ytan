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

/** Merging two routes: end points closer than this many meters become their midpoint (RouteMerger). */
function saveRouteMergeSettings() {
    var btn = document.getElementById('settingRouteMergeSaveBtn');
    var body = { midpoint_m: parseInt(document.getElementById('settingRouteMergeMidpoint').value, 10) };
    btn.disabled = true;
    Ytan.put('/settings/route-merge', body).then(function () {
        showAdminToast(t('common.saved'), 'success');
    }).catch(function (err) {
        showAdminToast(t('common.save_failed', { error: err.message }), 'error');
    }).finally(function () {
        btn.disabled = false;
    });
}

/** Colour, line width, zoom level, end pause and default speed of the route/tour animation (route-animation.js). */
function saveRouteAnimationSettings() {
    var btn = document.getElementById('settingRouteAnimationSaveBtn');
    var body = {
        color: document.getElementById('settingRouteAnimationColor').value,
        line_width: parseInt(document.getElementById('settingRouteAnimationLineWidth').value, 10),
        zoom: parseFloat(document.getElementById('settingRouteAnimationZoom').value),
        end_delay_s: parseFloat(document.getElementById('settingRouteAnimationEndDelay').value),
        speed: parseInt(document.getElementById('settingRouteAnimationSpeed').value, 10)
    };
    btn.disabled = true;
    Ytan.put('/settings/route-animation', body).then(function () {
        showAdminToast(t('common.saved'), 'success');
    }).catch(function (err) {
        showAdminToast(t('common.save_failed', { error: err.message }), 'error');
    }).finally(function () {
        btn.disabled = false;
    });
}

/** Default radius/POI count of the AR view and their upper limits; the fields show the values as stored (clamped). */
function saveArSettings() {
    var btn = document.getElementById('settingArSaveBtn');
    var fields = { radius_m: 'settingArRadius', radius_max_m: 'settingArRadiusMax', max_pois: 'settingArMaxPois', max_pois_limit: 'settingArMaxPoisLimit' };
    var body = {};
    Object.keys(fields).forEach(function (key) {
        body[key] = parseInt(document.getElementById(fields[key]).value, 10);
    });
    btn.disabled = true;
    Ytan.put('/settings/ar', body).then(function (answer) {
        var stored = answer && answer.data ? answer.data : answer;
        Object.keys(fields).forEach(function (key) {
            if (stored && stored[key] !== undefined) {
                document.getElementById(fields[key]).value = stored[key];
            }
        });
        showAdminToast(t('common.saved'), 'success');
    }).catch(function (err) {
        showAdminToast(t('common.save_failed', { error: err.message }), 'error');
    }).finally(function () {
        btn.disabled = false;
    });
}

/** The POI types whose distance to water is computed (poi_water_distance). */
function saveWaterDistancePoiTypes() {
    var btn = document.getElementById('settingWaterDistanceSaveBtn');
    var types = Array.from(document.querySelectorAll('.settingWaterDistanceType:checked')).map(function (box) {
        return parseInt(box.value, 10);
    });
    btn.disabled = true;
    Ytan.put('/settings/water-distance-poitypes', { poitypes: types }).then(function () {
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
