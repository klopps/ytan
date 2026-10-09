/**
 * Garmin watch data field (watch/ in the repo): "send route to watch". Two
 * ways to the watch, which a build with a key can use side by side:
 *  - with a watch key: the data field polls GET /api/v1/watch/device/route
 *    with the device token baked into its sideloaded build
 *    (bin\build-watch.bat); this file manages that token;
 *  - in the YTAN Android app, no key: wakeWatch() fetches GET /watch/payload
 *    (login) and hands it to the watch over the Connect IQ Mobile SDK
 *    (capacitor-bridge.js).
 *
 * watchStatus is null until loaded (and while logged out) -
 * {has_token, route: {id, name}|null, unit} afterwards.
 */
let watchStatus = null;
// The app's Garmin plugin can send the route itself (an APK with send()).
let watchDirectSend = false;

function loadWatchStatus() {
    if (user.id === null) {
        watchStatus = null;
        return Promise.resolve(null);
    }
    return Promise.all([
        Ytan.get('/watch'),
        CapacitorBridge.hasGarminWatchSend(),
    ]).then(([answer, directSend]) => {
        watchDirectSend = directSend;
        watchStatus = answer.data;
        return watchStatus;
    }).catch((err) => {
        log('loadWatchStatus() failed', LOG_WARN, err);
        return null;
    });
}

/** A watch key exists, i.e. a built data field polls the server. */
function isWatchPaired() {
    return !!(watchStatus && watchStatus.has_token);
}

/**
 * Whether routes can be sent to a watch at all: a key exists, or this is the
 * Android app, which sends them to the watch directly. Gates every "send to
 * watch" control.
 */
function isWatchUsable() {
    return !!watchStatus && (watchStatus.has_token || watchDirectSend);
}

/**
 * In the YTAN Android app: gets what was just changed (route, colors, speed)
 * to the watch through Garmin Connect within seconds - the payload itself
 * when the plugin can send it (no key needed), else just a wake-up that makes
 * a watch with a key fetch now instead of at its next 5-minute slot.
 * @returns {Promise<boolean>} true if at least one watch confirmed it;
 * false in a browser/PWA, on an app build without the plugin, or whenever
 * it didn't get through - a watch with a key still fetches it regularly.
 */
function wakeWatch() {
    if (!isWatchUsable() || !CapacitorBridge.isAvailable()) {
        return Promise.resolve(false);
    }
    const wakeOnly = () => isWatchPaired()
        ? CapacitorBridge.wakeGarminWatch().then((result) => !!result && result.sent > 0)
        : false;
    if (!watchDirectSend) {
        return wakeOnly();
    }
    // The payload is what the watch would fetch with its key, so a watch
    // with a key takes it just the same (same version: no restart).
    return Ytan.get('/watch/payload')
        .then((payload) => CapacitorBridge.sendGarminWatch(payload))
        .then((result) => !!result && result.sent > 0)
        .catch((err) => {
            log('wakeWatch() payload failed', LOG_WARN, err);
            return wakeOnly();
        });
}

/**
 * Icon for a route's InfoWindow (route.js's showRouteInfoWindow()) - only
 * offered once a watch is paired, otherwise it would just be clutter.
 */
function watchRouteIconHtml(i) {
    if (!isWatchUsable()) {
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
            if (woke) {
                showToast(t('watch.route_sent_now', { name: route.name }), 'success');
            } else if (isWatchPaired()) {
                showToast(t('watch.route_sent', { name: route.name }), 'success');
            } else {
                // No key: the watch can't fetch it later itself - it only
                // gets the route while the data field is running.
                showToast(t('watch.route_not_reached', { name: route.name }), 'warning');
            }
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

        let html = '<div class="nav-form-message">' + escapeHTML(status.has_token ? t('watch.paired') : t(watchDirectSend ? 'watch.direct' : 'watch.not_paired')) + '</div>';
        if (watchDirectSend && status.has_token) {
            html += '<div class="nav-form-message">' + escapeHTML(t('watch.direct_also')) + '</div>';
        }

        if (newToken) {
            html +=
                '<div class="nav-field"><label for="watchTokenValue">' + escapeHTML(t('watch.token_label')) + '</label>' +
                '<div class="watch-token-row"><input id="watchTokenValue" type="text" readonly value="' + escapeHTML(newToken) + '">' +
                '<button type="button" class="nav-chip-btn" onclick="copyWatchToken();">' + escapeHTML(t('watch.copy')) + '</button></div></div>' +
                '<div class="nav-form-message">' + escapeHTML(t('watch.token_hint')) + '</div>';
        }

        // The settings file with the key stays available until a new key is created.
        if (status.has_settings_file) {
            html += '<div class="nav-form-message">' + escapeHTML(t('watch.settings_file_hint')) + '</div>' +
                '<button type="button" class="nav-btn-secondary" onclick="downloadWatchSettingsFile();"><i class="material-icons-round">file_download</i>&nbsp;' +
                escapeHTML(t('watch.settings_file_download')) + '</button>';
        } else if (status.has_token) {
            html += '<div class="nav-form-message">' + escapeHTML(t('watch.settings_file_missing')) + '</div>';
        }

        if (isWatchUsable()) {
            html += '<div class="nav-form-message">' + escapeHTML(status.route
                ? t('watch.current_route', { name: status.route.name })
                : t('watch.no_route')) + '</div>';
            if (status.route) {
                html += '<span class="nav-link-small" onclick="clearWatchRoute();">' + escapeHTML(t('watch.clear_route')) + '</span>';
            }
        }

        if (isWatchUsable()) {
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

/**
 * Downloads the Connect IQ settings file (.SET) holding the current watch
 * key (GET /watch/settings-file) - in the Android app via the share sheet,
 * see helper.js's downloadBlob().
 */
async function downloadWatchSettingsFile() {
    try {
        const blob = await Ytan.fetchBlob('/watch/settings-file');
        await downloadBlob(blob, 'ytan-ReplaceByWatchName.SET');
    } catch (err) {
        log('downloadWatchSettingsFile() failed', LOG_ERROR, err);
        showToast(t('watch.settings_file_failed'), 'error');
    }
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
