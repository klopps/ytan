/**
 * Etmal reach lines (map context menu "Etmal-Reichweite"): how far the signed-in
 * user gets over water in a day from the clicked point. The server computes
 * the lines (POST /water/reach, WaterReach) for the user's etmal (Profile >
 * Etmal); this file only asks and draws them: the main line dashed in two
 * colours, the intermediate lines thinner dashes with transparent gaps, each
 * with a label - so none of them looks like a route line (user decision
 * 2026-11-27). Only the banner's x removes them (never ESC - it belongs to
 * the editors, user decision 2026-11-26); a new request replaces them.
 *
 * Two modes (user decision 2026-11-27):
 * - distance (first, as before): the shortest way over water, lines every
 *   5/10 km and at the etmal, no wind or current;
 * - timed ("Mit Wind & Strömung …" in the banner): paddling-time lines for a
 *   day and start hour chosen in #etmalSheet (bottom sheet on phones, side
 *   panel from 768 px, the Routencheck's rc-* look) - every full hour and the
 *   etmal over the profile's speed, with wind (a tailwind helps) and river /
 *   tidal current. Every change in the sheet asks again (debounced).
 */

const ETMAL_LINE_COLOR = '#e8590c';
/** White around the orange, so the lines stand out on the satellite map (user request 2026-11-26). */
const ETMAL_CONTRAST_COLOR = '#ffffff';
/** The lines while a new answer is computed. */
const ETMAL_STALE_COLOR = '#9e9e9e';
const ETMAL_LINE_WIDTH = 3;
const ETMAL_GAP_OPACITY = 0.85;   // the white between the dashes
const ETMAL_DASH_PX = 8;
const ETMAL_DASH_REPEAT_PX = 16;
const ETMAL_STEP_WIDTH = 2;
const ETMAL_STEP_DASH_PX = 6;
const ETMAL_STEP_REPEAT_PX = 12;
/** A change in the sheet waits this long for the next one before asking the server. */
const ETMAL_DEBOUNCE_MS = 600;
const ETMAL_MAX_CURRENT_KMH = 10; // = WaterReachController::MAX_CURRENT_KMH

var etmalOverlays = [];
var etmalBusy = false;
var etmalState = {
    latLng: null,
    mode: 'distance',
    date: null,
    startHour: null,
    currentKmh: null, // river current; null = the admin's default
    tidalKmh: null,   // tidal current at half tide; null = the admin's default
    result: null,
    loading: false,
    seq: 0,
    timer: null,
};

function initEtmal() {
    registerMapContextMenuItem('track_changes', 'etmal.context_menu', etmalShowAt, MAP_CONTEXT_MENU_ORDER.etmal);
}

/** The context menu: the pure distance first (the timed mode starts from the banner). */
async function etmalShowAt(latLng) {
    if (user.id === null) {
        showToast(t('etmal.login_needed'), 'info');
        return;
    }
    if (etmalBusy) {
        return;
    }
    if (!navigator.onLine) {
        showToast(t('etmal.offline'), 'warning');
        return;
    }
    etmalCloseSheet();
    etmalState.seq++;
    etmalState.latLng = latLng;
    etmalState.mode = 'distance';
    etmalState.result = null;
    etmalBusy = true;
    showToast(t('etmal.computing'), 'info');
    try {
        var answer = await Ytan.post('/water/reach', { lat: latLng.lat(), lng: latLng.lng() });
        etmalDraw(latLng, answer.data);
    } catch (err) {
        log('etmalShowAt() failed', LOG_ERROR, err);
        showToast(etmalErrorText(err), 'error');
    } finally {
        etmalBusy = false;
    }
}

function etmalErrorText(err) {
    return translateApiError(err && err.data) || (err && err.message) || t('etmal.failed');
}

/* ---- timed mode: wind and current --------------------------------------- */

/** The banner's "Mit Wind & Strömung …" / "Ändern": opens the sheet (first time: computes). */
function etmalOpenTimed() {
    if (!etmalState.latLng) {
        return;
    }
    if (etmalState.mode !== 'timed') {
        // the same default as the Routencheck: today if it is still morning, else tomorrow
        var now = new Date();
        var useToday = now.getHours() < 10;
        var day = new Date(now.getTime());
        if (!useToday) {
            day.setDate(day.getDate() + 1);
        }
        etmalState.mode = 'timed';
        etmalState.date = localIsoDate(day);
        etmalState.startHour = useToday ? Math.min(23, Math.max(6, now.getHours() + 1)) : 9;
        etmalState.currentKmh = null;
        etmalState.tidalKmh = null;
        etmalState.result = null;
        etmalLoadTimed(true);
    }
    document.getElementById('etmalSheet').hidden = false;
    document.getElementById('etmalBanner').hidden = true;
    etmalRenderSheet();
}

/** The sheet's x: the lines stay, the banner comes back with "Ändern". */
function etmalCloseSheet() {
    var sheet = document.getElementById('etmalSheet');
    if (sheet && !sheet.hidden) {
        sheet.hidden = true;
        if (etmalOverlays.length) {
            etmalShowBanner();
        }
    }
}

/** "Nur Strecke": back to the pure distance from the same point. */
function etmalDistanceOnly() {
    var latLng = etmalState.latLng;
    etmalCloseSheet();
    if (latLng) {
        etmalShowAt(latLng);
    }
}

function etmalSetDate(iso) {
    etmalState.date = iso;
    etmalLoadTimed(false);
}

function etmalShiftStart(delta) {
    etmalState.startHour = Math.max(0, Math.min(23, etmalState.startHour + delta));
    etmalLoadTimed(false);
}

function etmalShiftCurrent(delta) {
    var base = etmalState.currentKmh !== null ? etmalState.currentKmh : etmalCurrentDefault('river');
    var next = Math.round(Math.max(0, Math.min(ETMAL_MAX_CURRENT_KMH, base + delta)) * 10) / 10;
    if (next !== base) {
        etmalState.currentKmh = next;
        etmalLoadTimed(false);
    }
}

function etmalShiftTidal(delta) {
    var base = etmalState.tidalKmh !== null ? etmalState.tidalKmh : etmalCurrentDefault('tidal');
    var next = Math.round(Math.max(0, Math.min(ETMAL_MAX_CURRENT_KMH, base + delta)) * 10) / 10;
    if (next !== base) {
        etmalState.tidalKmh = next;
        etmalLoadTimed(false);
    }
}

/** The current the last answer used (river / tidal). */
function etmalCurrentDefault(kind) {
    var used = etmalState.result && etmalState.result.current_kmh;
    return used ? Number(used[kind]) : 0;
}

/** The chosen day and hour in the browser's time zone, unix seconds. */
function etmalStartSeconds() {
    var parts = etmalState.date.split('-').map(Number);
    return Math.round(new Date(parts[0], parts[1] - 1, parts[2], etmalState.startHour, 0, 0).getTime() / 1000);
}

/** Asks again after ETMAL_DEBOUNCE_MS without a further change (at once with `now`); an older answer is dropped. */
function etmalLoadTimed(now) {
    clearTimeout(etmalState.timer);
    etmalState.loading = true;
    etmalDimLines(true);
    etmalRenderSheet();
    etmalState.timer = setTimeout(etmalRequestTimed, now ? 0 : ETMAL_DEBOUNCE_MS);
}

async function etmalRequestTimed() {
    var seq = ++etmalState.seq;
    var latLng = etmalState.latLng;
    var timed = { start: etmalStartSeconds() };
    if (etmalState.currentKmh !== null) {
        timed.current_kmh = etmalState.currentKmh;
    }
    if (etmalState.tidalKmh !== null) {
        timed.tidal_current_kmh = etmalState.tidalKmh;
    }
    try {
        var answer = await Ytan.post('/water/reach', { lat: latLng.lat(), lng: latLng.lng(), timed: timed });
        if (seq !== etmalState.seq) {
            return;
        }
        etmalState.result = answer.data;
        etmalState.loading = false;
        etmalDraw(latLng, answer.data);
        if (answer.data.wind && answer.data.wind.source === 'forecast') {
            log('etmal: wind from the weather forecast (no wind layer data there)', LOG_INFO);
        }
    } catch (err) {
        if (seq !== etmalState.seq) {
            return;
        }
        log('etmalRequestTimed() failed', LOG_ERROR, err);
        etmalState.loading = false;
        etmalDimLines(false);
        showToast(etmalErrorText(err), 'error');
    }
    etmalRenderSheet();
}

/** "Fr 28.11." of a moment (unix seconds). */
function etmalDayText(seconds) {
    return new Date(seconds * 1000).toLocaleDateString(language, { weekday: 'short', day: 'numeric', month: 'numeric' });
}

/** "09:00" of a moment (unix seconds). */
function etmalClockText(seconds) {
    return new Date(seconds * 1000).toLocaleTimeString(language, { hour: '2-digit', minute: '2-digit' });
}

/** "4:10 h" of minutes. */
function etmalDurationText(minutes) {
    return Math.floor(minutes / 60) + ':' + String(minutes % 60).padStart(2, '0') + ' h';
}

function etmalNumber(value) {
    return Intl.NumberFormat(language, { maximumFractionDigits: 1 }).format(value);
}

/** "W 5 m/s, Böen 9 m/s" in the user's wind unit, empty without wind. */
function etmalWindText(wind) {
    if (!wind) {
        return '';
    }
    var speed = formatWindSpeed(wind.speed_ms * 3.6, settings.windUnit);
    var text = wind.direction === null ? t('etmal.wind_calm', { speed: speed })
        : t('etmal.wind_value', { direction: compassAbbreviation(wind.direction), speed: speed });
    if (wind.gust_ms !== null && wind.gust_ms !== undefined) {
        text += ', ' + t('etmal.wind_gusts', { speed: formatWindSpeed(wind.gust_ms * 3.6, settings.windUnit) });
    }
    return text;
}

function etmalRenderSheet() {
    var sheet = document.getElementById('etmalSheet');
    if (!sheet || sheet.hidden) {
        return;
    }
    var result = etmalState.result;
    var html = '<div class="rc-head">' +
        '<div class="rc-title"><i class="material-icons-round">track_changes</i><span>' + t('etmal.sheet_title') + '</span></div>' +
        '<button type="button" class="rc-close material-icons-round" aria-label="' + escapeHTML(t('etmal.sheet_close')) + '" data-on-click="etmalCloseSheet();">close</button>' +
        '</div><div class="rc-body">';

    if (result && result.mode === 'timed') {
        html += '<div class="etmal-summary">' + t('etmal.summary', {
            start: etmalClockText(result.start),
            end: etmalClockText(result.end),
            duration: etmalDurationText(result.duration_min),
            km: etmalDistanceText(result.etmal_km),
            speed: etmalNumber(result.speed_kmh),
        }) + '</div>';
    }
    if (etmalState.loading) {
        html += '<div class="rc-muted"><span class="rc-spinner"></span>' + t('etmal.computing') + '</div>';
    }

    html += '<div class="rc-label">' + t('etmal.day') + '</div>' +
        '<div class="rc-chips">' + dayChipsHtml(etmalState.date, 'etmalSetDate') + '</div>' +
        '<div class="rc-chips">' +
            '<button type="button" class="rc-chip" aria-label="' + escapeHTML(t('route_check.earlier')) + '" data-on-click="etmalShiftStart(-1);">&minus;1 h</button>' +
            '<span class="rc-chip rc-static">' + t('route_check.start_at', { time: String(etmalState.startHour).padStart(2, '0') + ':00' }) + '</span>' +
            '<button type="button" class="rc-chip" aria-label="' + escapeHTML(t('route_check.later')) + '" data-on-click="etmalShiftStart(1);">+1 h</button>' +
        '</div>';

    if (result && result.mode === 'timed') {
        html += '<div class="rc-label">' + t('etmal.wind') + '</div>';
        if (result.wind) {
            html += '<div>' + escapeHTML(etmalWindText(result.wind)) + '</div>' +
                '<div class="rc-muted rc-small">' + escapeHTML(result.wind.source === 'wind'
                    ? t('etmal.wind_from_layer', { model: result.wind.model || '' })
                    : t('etmal.wind_from_forecast', { source: result.wind.model || 'Open-Meteo' })) + '</div>';
        } else {
            html += '<div class="rc-muted">' + t('etmal.wind_none') + '</div>';
        }
        var current = result.current || {};
        if (current.river) {
            html += '<div class="rc-label">' + t('etmal.river') + '</div><div class="rc-chips">' +
                '<button type="button" class="rc-chip" aria-label="' + escapeHTML(t('route_check.current_less')) + '" data-on-click="etmalShiftCurrent(-0.5);">&minus;</button>' +
                '<span class="rc-chip rc-static"><i class="material-icons-round">waves</i>' + t('route_check.current_at', { value: etmalNumber(result.current_kmh.river) }) + '</span>' +
                '<button type="button" class="rc-chip" aria-label="' + escapeHTML(t('route_check.current_more')) + '" data-on-click="etmalShiftCurrent(0.5);">+</button>' +
                '</div>';
        }
        if (current.tidal) {
            var gauges = (current.tides || []).map(function (tide) {
                return escapeHTML(tide.station) + (tide.attribution ? ' (' + escapeHTML(tide.attribution) + ')' : '');
            }).join(', ');
            html += '<div class="rc-label">' + t('etmal.tidal') + '</div><div class="rc-chips">' +
                '<button type="button" class="rc-chip" aria-label="' + escapeHTML(t('route_check.tidal_less')) + '" data-on-click="etmalShiftTidal(-0.5);">&minus;</button>' +
                '<span class="rc-chip rc-static"><i class="material-icons-round">swap_vert</i>' + t('route_check.tidal_at', { value: etmalNumber(result.current_kmh.tidal) }) + '</span>' +
                '<button type="button" class="rc-chip" aria-label="' + escapeHTML(t('route_check.tidal_more')) + '" data-on-click="etmalShiftTidal(0.5);">+</button>' +
                '</div>' + (gauges ? '<div class="rc-muted rc-small">' + t('etmal.tidal_gauges', { gauges: gauges }) + '</div>' : '');
        } else if (current.tidal_without_times) {
            html += '<div class="rc-note">' + t('etmal.tidal_no_times') + '</div>';
        }
    }

    html += '<div class="rc-chips"><button type="button" class="rc-chip" data-on-click="etmalDistanceOnly();"><i class="material-icons-round">straighten</i>' + t('etmal.distance_only') + '</button></div>' +
        '<div class="rc-footer rc-muted rc-small">' + t('etmal.timed_note') + '</div>' +
        '</div>';
    sheet.innerHTML = html;
}

/* ---- drawing ------------------------------------------------------------ */

/** "25 km" (whole kilometres - the levels are), nautical "13.5 nm" like formatDistance(). */
function etmalDistanceText(km) {
    return settings.unit === 'nautical' ? formatDistance(km * 1000, 'nautical') : Math.round(km) + ' km';
}

/** The label of a line: its distance, or in the timed mode "2 h" / the arrival time of the main line. */
function etmalLineLabel(line, data) {
    if (data.mode !== 'timed') {
        return etmalDistanceText(line.km);
    }
    return line.main ? etmalClockText(data.end) : line.hours + ' h';
}

function etmalDraw(latLng, data) {
    etmalRemoveOverlays();
    if (data.mode !== 'timed') {
        etmalLastKm = data.etmal_km;
    }
    var drawn = 0;
    data.lines.forEach(function (line) {
        var longest = null;
        line.paths.forEach(function (rawPath) {
            var path = etmalSmoothPath(rawPath);
            var polyline = new google.maps.Polyline(Object.assign({
                map: map,
                path: path,
                clickable: false,
            }, line.main ? etmalMainStyle(false) : etmalStepStyle(false)));
            polyline.etmalMain = line.main;
            etmalOverlays.push(polyline);
            drawn++;
            if (longest === null || path.length > longest.length) {
                longest = path;
            }
        });
        if (longest !== null) {
            etmalOverlays.push(new LabelMarker({
                map: map,
                position: longest[Math.floor(longest.length / 2)],
                labelContent: escapeHTML(etmalLineLabel(line, data)),
                labelClass: 'etmal-label' + (line.main ? ' etmal-label-main' : ''),
                labelAnchor: { x: 0, y: 0 },
                clickable: false,
            }));
        }
    });
    etmalOverlays.push(etmalStartMarker(latLng));

    if (document.getElementById('etmalSheet').hidden) {
        etmalShowBanner();
    }
    if (drawn === 0) {
        showToast(t('etmal.no_lines'), 'info');
    }
}

/** The banner: distance mode with "Mit Wind & Strömung …", timed mode with its summary and "Ändern". */
function etmalShowBanner() {
    var data = etmalState.result;
    var text;
    var button;
    if (etmalState.mode === 'timed' && data && data.mode === 'timed') {
        var wind = etmalWindText(data.wind);
        text = etmalDayText(data.start) + ' · ' + etmalClockText(data.start) + '–' + etmalClockText(data.end) + (wind ? ' · ' + wind : '');
        button = t('etmal.change');
    } else {
        text = t('etmal.banner', { distance: etmalDistanceText(etmalBannerKm()) });
        button = t('etmal.with_wind');
    }
    document.getElementById('etmalBannerText').textContent = text;
    document.getElementById('etmalBannerTimed').textContent = button;
    document.getElementById('etmalBanner').hidden = false;
}

/** The etmal of the lines shown (the distance answer is not kept in etmalState.result). */
var etmalLastKm = null;
function etmalBannerKm() {
    return etmalLastKm !== null ? etmalLastKm : (user.etmal_km || 25);
}

/** Lines grey while a new answer is computed (timed mode), orange again otherwise. */
function etmalDimLines(dim) {
    etmalOverlays.forEach(function (overlay) {
        if (overlay instanceof google.maps.Polyline) {
            overlay.setOptions(overlay.etmalMain ? etmalMainStyle(dim) : etmalStepStyle(dim));
        }
    });
}

/** The repeated dash of a line, `length` pixels long every `repeat` pixels. */
function etmalDashes(length, repeat, width, color) {
    return [{
        // the symbol is 2 units long, scale = pixels per unit
        icon: { path: 'M 0,-1 0,1', scale: length / 2, strokeColor: color, strokeOpacity: 1, strokeWeight: width },
        offset: '0',
        repeat: repeat + 'px',
    }];
}

/** The main line: orange dashes on a white line. */
function etmalMainStyle(dim) {
    return {
        strokeColor: ETMAL_CONTRAST_COLOR,
        strokeOpacity: ETMAL_GAP_OPACITY,
        strokeWeight: ETMAL_LINE_WIDTH,
        zIndex: 3,
        icons: etmalDashes(ETMAL_DASH_PX, ETMAL_DASH_REPEAT_PX, ETMAL_LINE_WIDTH, dim ? ETMAL_STALE_COLOR : ETMAL_LINE_COLOR),
    };
}

/** An intermediate line: orange dashes with transparent gaps, thinner than the main line. */
function etmalStepStyle(dim) {
    return {
        strokeOpacity: 0,
        zIndex: 2,
        icons: etmalDashes(ETMAL_STEP_DASH_PX, ETMAL_STEP_REPEAT_PX, ETMAL_STEP_WIDTH, dim ? ETMAL_STALE_COLOR : ETMAL_LINE_COLOR),
    };
}

/**
 * Rounds the corners like a route line (smoothRoutePoints(), helper.js - the
 * server's lines are marching-squares chains simplified by Douglas-Peucker and
 * looked angular, user request 2026-11-26). A closed ring (first = last point)
 * is smoothed with its neighbours across the seam - the previous and next
 * point put in front and behind - and cut back at the seam, so it has no kink
 * where it starts.
 */
function etmalSmoothPath(path) {
    var n = path.length;
    var closed = n > 3 && path[0].lat === path[n - 1].lat && path[0].lng === path[n - 1].lng;
    if (!closed) {
        return smoothRoutePoints(path);
    }
    var smoothed = smoothRoutePoints([path[n - 2]].concat(path, [path[1]]));
    // the seam point is a real waypoint of the curve; find it in the first and
    // the last stretch (one curve segment each)
    var metric = flatMetric(path);
    var nearest = function (from, to) {
        var best = from;
        for (var i = from; i < to; i++) {
            if (metric(smoothed[i], path[0]) < metric(smoothed[best], path[0])) {
                best = i;
            }
        }
        return best;
    };
    var span = ROUTE_SMOOTHING_SEGMENTS_PER_POINT + 2;
    var start = nearest(0, Math.min(span, smoothed.length));
    var end = nearest(Math.max(0, smoothed.length - span), smoothed.length);
    if (end <= start) {
        return smoothRoutePoints(path);
    }
    var ring = smoothed.slice(start, end);
    ring.push(ring[0]);
    return ring;
}

/** A small dot at the start point (a LabelMarker with only its dot). */
function etmalStartMarker(latLng) {
    return new LabelMarker({ map: map, position: latLng, dotColor: ETMAL_LINE_COLOR, labelContent: '', clickable: false });
}

function etmalRemoveOverlays() {
    etmalOverlays.forEach(function (overlay) {
        if (overlay) {
            overlay.setMap(null);
        }
    });
    etmalOverlays = [];
}

/** Removes the lines, the banner and the sheet (the banner's x). */
function etmalClear() {
    clearTimeout(etmalState.timer);
    etmalState.seq++;
    etmalState.mode = 'distance';
    etmalState.result = null;
    etmalState.loading = false;
    etmalRemoveOverlays();
    var banner = document.getElementById('etmalBanner');
    if (banner) {
        banner.hidden = true;
    }
    var sheet = document.getElementById('etmalSheet');
    if (sheet) {
        sheet.hidden = true;
    }
}
