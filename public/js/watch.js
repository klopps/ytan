/**
 * Garmin watch data field (watch/ in the repo): pairing and "send route to
 * watch". The data field itself polls GET /api/v1/watch/device/route with
 * the device token baked into its sideloaded build (bin\build-watch.bat);
 * this file only manages that token and which route the watch shows.
 *
 * watchStatus is null until loaded (and while logged out) -
 * {has_token, route: {id, name}|null, unit} afterwards.
 */
let watchStatus = null;

function loadWatchStatus() {
    if (user.id === null) {
        watchStatus = null;
        return Promise.resolve(null);
    }
    return Ytan.get('/watch').then((answer) => {
        watchStatus = answer.data;
        return watchStatus;
    }).catch((err) => {
        log('loadWatchStatus() failed', LOG_WARN, err);
        return null;
    });
}

function isWatchPaired() {
    return !!(watchStatus && watchStatus.has_token);
}

/**
 * In the YTAN Android app: wakes the data field through Garmin Connect
 * (capacitor-bridge.js's wakeGarminWatch()), so it fetches what was just
 * changed within seconds instead of at its next 5-minute slot.
 * @returns {Promise<boolean>} true if at least one watch confirmed it;
 * false in a browser/PWA, on an app build without the plugin, or whenever
 * it didn't get through - the watch's regular fetch still delivers then.
 */
function wakeWatch() {
    if (!isWatchPaired() || !CapacitorBridge.isAvailable()) {
        return Promise.resolve(false);
    }
    return CapacitorBridge.wakeGarminWatch().then((result) => !!result && result.sent > 0);
}

/**
 * Icon for a route's InfoWindow (route.js's showRouteInfoWindow()) - only
 * offered once a watch is paired, otherwise it would just be clutter.
 */
function watchRouteIconHtml(i) {
    if (!isWatchPaired()) {
        return '';
    }
    const isCurrent = watchStatus.route && watchStatus.route.id == routes[i].id;
    const title = isCurrent ? t('watch.route_is_current') : t('watch.send_route');
    return '<i class="material-icons-round watch-route-icon' + (isCurrent ? ' is-current' : '') + '" title="' + escapeHTML(title) + '" onClick="sendRouteToWatch(' + i + ');">watch</i>';
}

function sendRouteToWatch(i) {
    const route = routes[i];
    if (!(route.id > 0)) {
        // Created offline and not synced yet - the server doesn't know it.
        showToast(t('watch.route_not_synced'), 'warning');
        return;
    }
    Ytan.put('/watch/route', { route_id: route.id, unit: settings.unit }).then((answer) => {
        watchStatus = answer.data;
        document.querySelectorAll('.watch-route-icon').forEach((icon) => icon.classList.add('is-current'));
        // The toast waits for the wake-up (a second or two in the app,
        // immediate elsewhere) so it can say when the route will arrive.
        return wakeWatch().then((woke) => {
            showToast(t(woke ? 'watch.route_sent_now' : 'watch.route_sent', { name: route.name }), 'success');
        });
    }).catch((err) => {
        showToast(translateApiError(err.data) || err.message, 'error');
    });
}

/**
 * "Garmin watch" submenu of the profile screen (user.js's showUserWindow()
 * row), rendered via openProfileSub() like the change-password/edit-profile
 * forms - and re-rendered in place there after each action below.
 */
function showWatchSection(newToken) {
    const title = t('watch.title');
    openProfileSub(title, '<div class="nav-form-message">' + escapeHTML(t('watch.loading')) + '</div>' + navCancelButtonHtml());

    loadWatchStatus().then((status) => {
        if (!status) {
            openProfileSub(title, '<div class="nav-form-message">' + escapeHTML(t('watch.load_failed')) + '</div>' + navCancelButtonHtml());
            return;
        }

        let html = '<div class="nav-form-message">' + escapeHTML(status.has_token ? t('watch.paired') : t('watch.not_paired')) + '</div>';

        if (newToken) {
            html +=
                '<div class="nav-field"><label for="watchTokenValue">' + escapeHTML(t('watch.token_label')) + '</label>' +
                '<div class="watch-token-row"><input id="watchTokenValue" type="text" readonly value="' + escapeHTML(newToken) + '">' +
                '<button type="button" class="nav-chip-btn" onclick="copyWatchToken();">' + escapeHTML(t('watch.copy')) + '</button></div></div>' +
                '<div class="nav-form-message">' + escapeHTML(t('watch.token_hint')) + '</div>';
        }

        if (status.has_token) {
            html += '<div class="nav-form-message">' + escapeHTML(status.route
                ? t('watch.current_route', { name: status.route.name })
                : t('watch.no_route')) + '</div>';
            if (status.route) {
                html += '<span class="nav-link-small" onclick="clearWatchRoute();">' + escapeHTML(t('watch.clear_route')) + '</span>';
            }
        }

        if (status.has_token) {
            html += watchColorsHtml(status);
        }

        html += '<button type="button" class="nav-btn-secondary" onclick="createWatchToken();"><i class="material-icons-round">vpn_key</i>&nbsp;' +
            escapeHTML(status.has_token ? t('watch.regenerate_token') : t('watch.create_token')) + '</button>';
        if (status.has_token) {
            html += '<button type="button" class="nav-btn-secondary nav-btn-danger" onclick="unpairWatch();"><i class="material-icons-round">link_off</i>&nbsp;' + escapeHTML(t('watch.unpair')) + '</button>';
        }
        openProfileSub(title, html + navCancelButtonHtml());
    });
}

/**
 * The data field's text colors, per element and per watch background
 * (status.colors: {element: {dark, light}}, see WatchColors.php). Each cell
 * previews its color on the background it is meant for - black or white no
 * matter the app theme, since that is the watch's, not the app's.
 */
const WATCH_COLOR_ELEMENTS = [
    { key: 'bearing', sample: '221°' },
    { key: 'distance', sample: '311 m' },
    { key: 'text', sample: 'ETA 6:52' },
    { key: 'north', sample: '▲' },
];

function watchColorsHtml(status) {
    let html = '<div class="nav-divider"></div><p class="nav-field-label">' + escapeHTML(t('watch.colors_title')) + '</p>' +
        '<div class="watch-colors">' +
        '<span></span><span class="watch-colors-head">' + escapeHTML(t('watch.colors_dark')) + '</span>' +
        '<span class="watch-colors-head">' + escapeHTML(t('watch.colors_light')) + '</span>';
    WATCH_COLOR_ELEMENTS.forEach((element) => {
        html += '<span>' + escapeHTML(t('watch.color_' + element.key)) + '</span>';
        ['dark', 'light'].forEach((background) => {
            const color = escapeHTML(status.colors[element.key][background]);
            html += '<label class="watch-color-cell is-' + background + '">' +
                '<span class="watch-color-sample" style="color:' + color + '">' + escapeHTML(element.sample) + '</span>' +
                '<input type="color" data-element="' + element.key + '" data-background="' + background + '" value="' + color + '" ' +
                'oninput="this.previousElementSibling.style.color = this.value;"></label>';
        });
    });
    html += '</div>' +
        '<div class="nav-form-message">' + escapeHTML(t('watch.colors_hint')) + '</div>' +
        '<button type="button" class="nav-btn-secondary" onclick="saveWatchColors();"><i class="material-icons-round">palette</i>&nbsp;' + escapeHTML(t('watch.colors_save')) + '</button>' +
        '<span class="nav-link-small" onclick="resetWatchColors();">' + escapeHTML(t('watch.colors_reset')) + '</span>';
    return html;
}

function saveWatchColors() {
    const colors = {};
    document.querySelectorAll('.watch-colors input[type="color"]').forEach((input) => {
        colors[input.dataset.element] = colors[input.dataset.element] || {};
        colors[input.dataset.element][input.dataset.background] = input.value;
    });
    Ytan.put('/watch/colors', { colors: colors }).then(() => {
        showToast(t('watch.colors_saved'), 'success');
        wakeWatch();
    }).catch((err) => showToast(translateApiError(err.data) || err.message, 'error'));
}

function resetWatchColors() {
    Ytan.del('/watch/colors').then(() => {
        showToast(t('watch.colors_reset_done'), 'success');
        wakeWatch();
        showWatchSection();
    }).catch((err) => showToast(translateApiError(err.data) || err.message, 'error'));
}

function copyWatchToken() {
    copyTextToClipboard(document.getElementById('watchTokenValue').value);
    showToast(t('watch.copied'), 'success');
}

function createWatchToken() {
    const proceed = isWatchPaired()
        ? showConfirmDialog(t('watch.regenerate_confirm'))
        : Promise.resolve(true);
    proceed.then((confirmed) => {
        if (!confirmed) {
            return;
        }
        Ytan.post('/watch/token').then((answer) => {
            showWatchSection(answer.data.token);
        }).catch((err) => showToast(err.message, 'error'));
    });
}

function unpairWatch() {
    showConfirmDialog(t('watch.unpair_confirm')).then((confirmed) => {
        if (!confirmed) {
            return;
        }
        Ytan.del('/watch/token').then(() => showWatchSection()).catch((err) => showToast(err.message, 'error'));
    });
}

function clearWatchRoute() {
    Ytan.del('/watch/route').then(() => {
        wakeWatch();
        showWatchSection();
    }).catch((err) => showToast(err.message, 'error'));
}
