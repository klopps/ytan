/**
 * Routencheck (routencheck.md): tap a route -> "Routencheck" in its info
 * window (or its context menu) -> a bottom sheet (side panel on wide screens)
 * with the traffic light for the chosen day, start hour, direction and
 * experience level, the start hours of the day as a coloured bar, the route
 * coloured section by section on the map, the places next to it and the AI
 * text. Two requests per change: the traffic light first (text: false, fast),
 * then the text - so the sheet is never empty while the model writes.
 *
 * The map stays usable while the sheet is open (no panelOpened()); the sheet
 * sits below the drawer and the full-screen panels.
 */

var ROUTE_CHECK_CONSENT_KEY = 'ytan_route_check_consent';
var ROUTE_CHECK_LEVELS = ['beginner', 'experienced', 'expert'];
var ROUTE_CHECK_MAX_CURRENT_KMH = 10; // = AiBriefingController::MAX_CURRENT_KMH
var routeCheck = {
    routeIndex: null,
    routeId: null,
    date: null,
    startHour: null,
    reverse: false,
    level: null,
    currentKmh: null, // river current; null = the admin's default
    result: null,
    textState: null,
    seq: 0,
    overlays: [],
};

/** Whether the current user may use the Routencheck (switch on + right ai_use or admin). */
function canRouteCheck() {
    return window.YTAN_ROUTE_CHECK_ENABLED === true && user && user.id !== null && (user.is_admin === true || user.ai_use === true);
}

/** The button in the route info window (empty when not allowed). */
function routeCheckButtonHtml(i) {
    if (!canRouteCheck()) {
        return '';
    }
    return '<div class="infoWindowElement"><button type="button" class="button routeCheckButton" data-on-click="openRouteCheck(' + i + ');">' +
        '<i class="material-icons-round">traffic</i>' + t('route_check.button') + '</button></div>';
}

function routeContextMenuRouteCheck(i) {
    closeContextMenu();
    openRouteCheck(i);
}

function routeCheckConsentGiven() {
    try {
        return localStorage.getItem(ROUTE_CHECK_CONSENT_KEY) === '1';
    } catch (e) {
        return false;
    }
}

function routeCheckRememberConsent() {
    try {
        localStorage.setItem(ROUTE_CHECK_CONSENT_KEY, '1');
    } catch (e) {
        // private mode: ask again next time
    }
}

/** Local "YYYY-MM-DD" of a Date. */
function routeCheckIsoDate(date) {
    var month = String(date.getMonth() + 1).padStart(2, '0');
    var day = String(date.getDate()).padStart(2, '0');
    return date.getFullYear() + '-' + month + '-' + day;
}

async function openRouteCheck(i) {
    if (!canRouteCheck() || !routes[i]) {
        return;
    }
    if (typeof closeRouteInfoWindow === 'function') {
        closeRouteInfoWindow(i);
    }
    if (!routeCheckConsentGiven()) {
        var ok = await showConfirmDialog(t('route_check.consent'), { confirmLabel: t('route_check.consent_ok') });
        if (!ok) {
            return;
        }
        routeCheckRememberConsent();
    }

    var now = new Date();
    var useToday = now.getHours() < 10;
    var day = new Date(now.getTime());
    if (!useToday) {
        day.setDate(day.getDate() + 1);
    }
    routeCheckClearOverlays();
    routeCheck.routeIndex = i;
    routeCheck.routeId = routes[i].id;
    routeCheck.date = routeCheckIsoDate(day);
    routeCheck.startHour = useToday ? Math.min(23, Math.max(6, now.getHours() + 1)) : 10;
    routeCheck.reverse = false;
    routeCheck.level = null; // the server takes the stored level
    routeCheck.currentKmh = null;
    routeCheck.result = null;
    routeCheck.textState = 'loading';

    var sheet = document.getElementById('routeCheckSheet');
    sheet.hidden = false;
    sheet.classList.remove('expanded');
    sheet.style.maxHeight = '';
    document.addEventListener('keydown', routeCheckKeydown);
    routeCheckRender();
    routeCheckLoad();
}

function closeRouteCheck() {
    routeCheck.seq++;
    routeCheckClearOverlays();
    var sheet = document.getElementById('routeCheckSheet');
    if (sheet) {
        sheet.hidden = true;
    }
    document.removeEventListener('keydown', routeCheckKeydown);
}

function routeCheckKeydown(event) {
    if (event.key === 'Escape') {
        closeRouteCheck();
    }
}

function routeCheckToggleExpanded() {
    var sheet = document.getElementById('routeCheckSheet');
    if (routeCheckDragEndedAt && Date.now() - routeCheckDragEndedAt < 400) {
        return; // the click that follows a drag on the handle
    }
    if (sheet.style.maxHeight) {
        // after a drag a tap goes back to the default height
        sheet.style.maxHeight = '';
        sheet.classList.remove('expanded');
        return;
    }
    sheet.classList.toggle('expanded');
}

/*
 * Phones: the sheet's head (handle + title) can be dragged up and down to
 * show more or less of the text; a tap on the handle still toggles between
 * the default and the expanded height. The dragged height is an inline
 * max-height, so short content never leaves an empty area below it.
 */
var routeCheckDrag = null;
var routeCheckDragEndedAt = 0;
var ROUTE_CHECK_MIN_HEIGHT_PX = 120;
var ROUTE_CHECK_MAX_HEIGHT_RATIO = 0.92;

function routeCheckDragStart(event) {
    var sheet = document.getElementById('routeCheckSheet');
    if (!sheet || window.innerWidth >= 768 || event.button > 0 || (event.target.closest && event.target.closest('.rc-close'))) {
        return;
    }
    // no text selection / native drag of the title (that cancels the pointer)
    event.preventDefault();
    routeCheckDrag = { y: event.clientY, height: sheet.getBoundingClientRect().height, moved: false };
    document.addEventListener('pointermove', routeCheckDragMove, { passive: false });
    document.addEventListener('pointerup', routeCheckDragEnd);
    document.addEventListener('pointercancel', routeCheckDragEnd);
}

function routeCheckDragMove(event) {
    if (!routeCheckDrag) {
        return;
    }
    var dy = routeCheckDrag.y - event.clientY;
    if (!routeCheckDrag.moved && Math.abs(dy) < 6) {
        return;
    }
    var sheet = document.getElementById('routeCheckSheet');
    routeCheckDrag.moved = true;
    sheet.classList.add('dragging');
    var max = window.innerHeight * ROUTE_CHECK_MAX_HEIGHT_RATIO;
    var height = Math.max(ROUTE_CHECK_MIN_HEIGHT_PX, Math.min(max, routeCheckDrag.height + dy));
    sheet.style.maxHeight = Math.round(height) + 'px';
    event.preventDefault();
}

function routeCheckDragEnd() {
    document.removeEventListener('pointermove', routeCheckDragMove);
    document.removeEventListener('pointerup', routeCheckDragEnd);
    document.removeEventListener('pointercancel', routeCheckDragEnd);
    var sheet = document.getElementById('routeCheckSheet');
    if (sheet) {
        sheet.classList.remove('dragging');
    }
    if (routeCheckDrag && routeCheckDrag.moved) {
        routeCheckDragEndedAt = Date.now();
    }
    routeCheckDrag = null;
}

/** Traffic light first, then the text; a newer request makes older answers obsolete. */
async function routeCheckLoad() {
    var seq = ++routeCheck.seq;
    var body = { date: routeCheck.date, start_hour: routeCheck.startHour, reverse: routeCheck.reverse, text: false };
    if (routeCheck.level) {
        body.level = routeCheck.level;
    }
    if (routeCheck.currentKmh !== null) {
        body.current_kmh = routeCheck.currentKmh;
    }
    routeCheck.textState = 'loading';
    try {
        var answer = await Ytan.post('/routes/' + routeCheck.routeId + '/briefing', body);
        if (seq !== routeCheck.seq) {
            return;
        }
        routeCheck.result = answer.data;
        routeCheck.level = answer.data.level;
        routeCheck.textState = answer.data.text_status === 'no_data' ? 'no_data' : 'loading';
        routeCheckRender();
        routeCheckDrawOverlays();
        if (routeCheck.textState !== 'loading') {
            return;
        }
        body.text = true;
        body.level = routeCheck.level;
        var full = await Ytan.post('/routes/' + routeCheck.routeId + '/briefing', body);
        if (seq !== routeCheck.seq) {
            return;
        }
        routeCheck.result = full.data;
        routeCheck.textState = full.data.text_status;
        routeCheckRender();
    } catch (err) {
        if (seq !== routeCheck.seq) {
            return;
        }
        log('Routencheck failed: ' + err.message, LOG_ERROR);
        if (!routeCheck.result) {
            showToast(err.message, 'error');
            closeRouteCheck();
            return;
        }
        routeCheck.textState = 'error';
        routeCheckRender();
    }
}

function routeCheckSetDate(date) {
    routeCheck.date = date;
    routeCheckLoad();
    routeCheckRender();
}

function routeCheckSetStart(hour) {
    routeCheck.startHour = Math.max(0, Math.min(23, hour));
    routeCheckLoad();
    routeCheckRender();
}

function routeCheckShiftStart(delta) {
    routeCheckSetStart(routeCheck.startHour + delta);
}

/** River current -/+ 0.5 km/h (only offered when the route runs on a river). */
function routeCheckShiftCurrent(delta) {
    var current = routeCheck.result && routeCheck.result.current;
    var base = routeCheck.currentKmh !== null ? routeCheck.currentKmh : (current ? current.river_kmh : 0);
    var next = Math.round(Math.max(0, Math.min(ROUTE_CHECK_MAX_CURRENT_KMH, base + delta)) * 10) / 10;
    if (next === base) {
        return;
    }
    routeCheck.currentKmh = next;
    routeCheckLoad();
}

function routeCheckCurrentChipsHtml(result) {
    if (!result.current || !result.current.on_river) {
        return '';
    }
    return '<div class="rc-chips">' +
        '<button type="button" class="rc-chip" aria-label="' + escapeHTML(t('route_check.current_less')) + '" data-on-click="routeCheckShiftCurrent(-0.5);">&minus;</button>' +
        '<span class="rc-chip rc-static"><i class="material-icons-round">waves</i>' + t('route_check.current_at', { value: routeCheckNumber(result.current.river_kmh) }) + '</span>' +
        '<button type="button" class="rc-chip" aria-label="' + escapeHTML(t('route_check.current_more')) + '" data-on-click="routeCheckShiftCurrent(0.5);">+</button>' +
        '</div>';
}

function routeCheckToggleReverse() {
    routeCheck.reverse = !routeCheck.reverse;
    routeCheckLoad();
    routeCheckRender();
}

function routeCheckSetLevel(level) {
    if (ROUTE_CHECK_LEVELS.indexOf(level) === -1) {
        return;
    }
    routeCheck.level = level;
    Ytan.put('/auth/paddle-level', { paddle_level: level }).catch(function (err) {
        log('Saving the paddle level failed: ' + err.message, LOG_WARN);
    });
    routeCheckLoad();
    routeCheckRender();
}

function routeCheckRetry() {
    routeCheckLoad();
    routeCheckRender();
}

/** Literal keys (the translation tool finds usages by scanning). */
function routeCheckRatingLabel(rating) {
    switch (rating) {
    case 0: return t('route_check.rating.green');
    case 1: return t('route_check.rating.yellow');
    case 2: return t('route_check.rating.red');
    default: return t('route_check.rating.grey');
    }
}

function routeCheckRatingClass(rating) {
    return rating === 0 ? 'rc-green' : rating === 1 ? 'rc-yellow' : rating === 2 ? 'rc-red' : 'rc-grey';
}

function routeCheckLevelLabel(level) {
    switch (level) {
    case 'beginner': return t('route_check.level.beginner');
    case 'expert': return t('route_check.level.expert');
    default: return t('route_check.level.experienced');
    }
}

function routeCheckNumber(value) {
    return Intl.NumberFormat(language, { maximumFractionDigits: 1 }).format(value);
}

function routeCheckReasonText(reason) {
    var vars = { value: typeof reason.value === 'number' ? routeCheckNumber(reason.value) : escapeHTML(String(reason.value)), threshold: reason.threshold === null ? '' : (typeof reason.threshold === 'number' ? routeCheckNumber(reason.threshold) : escapeHTML(String(reason.threshold))) };
    switch (reason.criterion) {
    case 'wind': return t('route_check.reason.wind', vars);
    case 'gust': return t('route_check.reason.gust', vars);
    case 'wave': return t('route_check.reason.wave', vars);
    case 'thunder': return t('route_check.reason.thunder', vars);
    case 'dark': return t('route_check.reason.dark', vars);
    case 'dusk': return t('route_check.reason.dusk', vars);
    case 'cold_water': return t('route_check.reason.cold_water', vars);
    case 'offshore': return t('route_check.reason.offshore', vars);
    case 'beam_sea': return t('route_check.reason.beam_sea', vars);
    case 'current': return t('route_check.reason.current', vars);
    default: return escapeHTML(String(reason.criterion));
    }
}

function routeCheckRoleIcon(role) {
    return role === 'danger' ? 'warning' : role === 'portage' ? 'swap_calls' : 'place';
}

function routeCheckDayChips() {
    var html = '';
    var today = new Date();
    for (var d = 0; d < 7; d++) {
        var day = new Date(today.getTime());
        day.setDate(day.getDate() + d);
        var iso = routeCheckIsoDate(day);
        var label = d === 0 ? t('route_check.today') : d === 1 ? t('route_check.tomorrow')
            : day.toLocaleDateString(language, { weekday: 'short', day: 'numeric' });
        html += '<button type="button" class="rc-chip' + (iso === routeCheck.date ? ' active' : '') + '" data-on-click="routeCheckSetDate(\'' + iso + '\');">' + escapeHTML(label) + '</button>';
    }
    return html;
}

function routeCheckWindowsHtml(result) {
    if (!result || !result.windows) {
        return '';
    }
    var html = '<div class="rc-windows" role="list" aria-label="' + escapeHTML(t('route_check.start_hours')) + '">';
    result.windows.forEach(function (w) {
        var active = w.hour === routeCheck.startHour ? ' active' : '';
        var title = String(w.hour).padStart(2, '0') + ':00 - ' + routeCheckRatingLabel(w.rating);
        html += '<button type="button" role="listitem" class="rc-window ' + routeCheckRatingClass(w.rating) + active + '" title="' + escapeHTML(title) + '" data-on-click="routeCheckSetStart(' + w.hour + ');">' + w.hour + '</button>';
    });
    return html + '</div>';
}

function routeCheckTextHtml(result) {
    switch (routeCheck.textState) {
    case 'loading':
        return '<div class="rc-text rc-muted"><span class="rc-spinner"></span>' + t('route_check.text_loading') + '</div>';
    case 'ok': {
        var time = result.text_created_at ? new Date(result.text_created_at).toLocaleTimeString(language, { hour: '2-digit', minute: '2-digit' }) : '';
        return '<div class="rc-text">' + renderMarkdown(result.text || '') + '</div>' +
            (time ? '<div class="rc-muted rc-small">' + t('route_check.text_from', { time: time }) + '</div>' : '');
    }
    case 'limit':
        return '<div class="rc-text rc-muted">' + t('route_check.text_limit') + '</div>';
    case 'no_data':
        return '<div class="rc-text rc-muted">' + t('route_check.text_no_data') + '</div>';
    case 'not_configured':
        return '<div class="rc-text rc-muted">' + t('route_check.text_not_configured') + '</div>';
    default:
        return '<div class="rc-text rc-muted">' + t('route_check.text_error') +
            ' <button type="button" class="rc-chip" data-on-click="routeCheckRetry();">' + t('route_check.retry') + '</button></div>';
    }
}

function routeCheckRender() {
    var sheet = document.getElementById('routeCheckSheet');
    if (!sheet || sheet.hidden) {
        return;
    }
    var route = routes[routeCheck.routeIndex] || {};
    var result = routeCheck.result;
    var loading = !result;
    var html = '<div class="rc-head" data-on-pointerdown="routeCheckDragStart(event);">' +
        '<button type="button" class="rc-handle" aria-label="' + escapeHTML(t('route_check.expand')) + '" data-on-click="routeCheckToggleExpanded();"></button>' +
        '<div class="rc-title"><i class="material-icons-round">traffic</i><span>' + t('route_check.title') + '</span>' +
        '<span class="rc-route-name">' + escapeText(route.name || '') + '</span></div>' +
        '<button type="button" class="rc-close material-icons-round" aria-label="' + escapeHTML(t('route_check.close')) + '" data-on-click="closeRouteCheck();">close</button>' +
        '</div>';

    if (loading) {
        html += '<div class="rc-body"><div class="rc-muted"><span class="rc-spinner"></span>' + t('route_check.loading') + '</div></div>';
        sheet.innerHTML = html;
        return;
    }

    var main = result.reasons && result.reasons.length ? routeCheckReasonText(result.reasons[0]) : t('route_check.no_reasons');
    var km = routeCheckNumber(result.length_m / 1000);
    html += '<div class="rc-body">' +
        '<div class="rc-verdict ' + routeCheckRatingClass(result.rating) + '">' +
            '<span class="rc-light" aria-hidden="true"></span>' +
            '<div><div class="rc-verdict-label">' + routeCheckRatingLabel(result.rating) + '</div>' +
            '<div class="rc-verdict-reason">' + main + '</div>' +
            '<div class="rc-muted rc-small">' + t('route_check.summary', { start: result.start, end: result.end, km: km, speed: routeCheckNumber(result.speed_kmh) }) + '</div></div>' +
        '</div>' +
        (result.partial ? '<div class="rc-note">' + t('route_check.partial') + '</div>' : '') +
        '<div class="rc-label">' + t('route_check.start_hours') + '</div>' +
        routeCheckWindowsHtml(result) +
        '<div class="rc-chips">' + routeCheckDayChips() + '</div>' +
        '<div class="rc-chips">' +
            '<button type="button" class="rc-chip" aria-label="' + escapeHTML(t('route_check.earlier')) + '" data-on-click="routeCheckShiftStart(-1);">&minus;1 h</button>' +
            '<span class="rc-chip rc-static">' + t('route_check.start_at', { time: String(routeCheck.startHour).padStart(2, '0') + ':00' }) + '</span>' +
            '<button type="button" class="rc-chip" aria-label="' + escapeHTML(t('route_check.later')) + '" data-on-click="routeCheckShiftStart(1);">+1 h</button>' +
            '<button type="button" class="rc-chip' + (routeCheck.reverse ? ' active' : '') + '" data-on-click="routeCheckToggleReverse();"><i class="material-icons-round">swap_horiz</i>' + t('route_check.reverse') + '</button>' +
        '</div>' +
        routeCheckCurrentChipsHtml(result) +
        '<div class="rc-chips">' + ROUTE_CHECK_LEVELS.map(function (level) {
            return '<button type="button" class="rc-chip' + (level === routeCheck.level ? ' active' : '') + '" data-on-click="routeCheckSetLevel(\'' + level + '\');">' + routeCheckLevelLabel(level) + '</button>';
        }).join('') + '</div>' +
        routeCheckTextHtml(result) +
        routeCheckSectionsHtml(result) +
        routeCheckPlacesHtml(result) +
        '<div class="rc-footer rc-muted rc-small">' + t('route_check.disclaimer') +
            (result.limit ? ' ' + t('route_check.remaining', { n: Math.max(0, result.limit.per_day - result.limit.used_today) }) : '') +
        '</div>' +
    '</div>';
    sheet.innerHTML = html;
}

function routeCheckSectionsHtml(result) {
    var html = '<div class="rc-label">' + t('route_check.sections') + '</div><ul class="rc-list">';
    result.sections.forEach(function (s, index) {
        var reasons = s.reasons.map(routeCheckReasonText).join(', ') || routeCheckRatingLabel(s.rating);
        html += '<li><button type="button" class="rc-row" data-on-click="routeCheckZoomSection(' + index + ');">' +
            '<span class="rc-dot ' + routeCheckRatingClass(s.rating) + '"></span>' +
            '<span><span class="rc-row-title">' + t('route_check.section', { from: routeCheckNumber(s.from_m / 1000), to: routeCheckNumber(s.to_m / 1000), entry: s.entry, exit: s.exit }) + '</span>' +
            '<span class="rc-muted rc-small">' + reasons + '</span></span></button></li>';
    });
    return html + '</ul>';
}

function routeCheckPlacesHtml(result) {
    if (!result.pois || result.pois.length === 0) {
        return '<div class="rc-label">' + t('route_check.places') + '</div><div class="rc-muted rc-small">' + t('route_check.no_places') + '</div>';
    }
    var html = '<div class="rc-label">' + t('route_check.places') + '</div><ul class="rc-list">';
    result.pois.forEach(function (p, index) {
        html += '<li><button type="button" class="rc-row" data-on-click="routeCheckShowPlace(' + index + ');">' +
            '<i class="material-icons-round rc-place-icon">' + routeCheckRoleIcon(p.role) + '</i>' +
            '<span><span class="rc-row-title">' + escapeText(p.name) + '</span>' +
            '<span class="rc-muted rc-small">' + t('route_check.place_at', { km: routeCheckNumber(p.at_m / 1000), off: Math.round(p.distance_m / 10) * 10 }) + '</span></span></button></li>';
    });
    return html + '</ul>';
}

function routeCheckColor(rating) {
    var styles = getComputedStyle(document.documentElement);
    var name = rating === 0 ? '--rc-green' : rating === 1 ? '--rc-yellow' : rating === 2 ? '--rc-red' : '--rc-grey';
    return (styles.getPropertyValue(name) || '#888888').trim();
}

function routeCheckDrawOverlays() {
    routeCheckClearOverlays();
    var result = routeCheck.result;
    if (!result || typeof map === 'undefined' || !map) {
        return;
    }
    result.sections.forEach(function (s) {
        var line = new google.maps.Polyline({
            path: s.points.map(function (p) { return { lat: p[0], lng: p[1] }; }),
            strokeColor: routeCheckColor(s.rating),
            strokeOpacity: 0.9,
            strokeWeight: 7,
            zIndex: 1000,
            clickable: false,
            map: map,
        });
        routeCheck.overlays.push(line);
    });
}

function routeCheckClearOverlays() {
    routeCheck.overlays.forEach(function (overlay) { overlay.setMap(null); });
    routeCheck.overlays = [];
}

function routeCheckZoomSection(index) {
    var s = routeCheck.result && routeCheck.result.sections[index];
    if (!s) {
        return;
    }
    var bounds = new google.maps.LatLngBounds();
    s.points.forEach(function (p) { bounds.extend({ lat: p[0], lng: p[1] }); });
    map.fitBounds(bounds, 60);
}

function routeCheckShowPlace(index) {
    var p = routeCheck.result && routeCheck.result.pois[index];
    if (!p) {
        return;
    }
    map.panTo({ lat: p.lat, lng: p.lng });
    if (map.getZoom() < 13) {
        map.setZoom(13);
    }
}
