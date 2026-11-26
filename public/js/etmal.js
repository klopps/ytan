/**
 * Etmal reach lines (map context menu "Etmal-Reichweite"): how far the signed-in
 * user gets over water in a day from the clicked point. The server computes
 * the lines (POST /water/reach, WaterReach: shortest way over water, no wind or
 * current) for the user's etmal (Profile > Etmal); this file only asks and
 * draws them: the etmal line bold, the intermediate distances thin, each with a
 * distance label. Only the banner's x removes them (never ESC - it belongs to
 * the editors, user decision 2026-11-26); a new request replaces them.
 */

const ETMAL_LINE_COLOR = '#e8590c';
const ETMAL_LINE_WIDTH = 2;
const ETMAL_STEP_WIDTH = 1.5;
const ETMAL_STEP_OPACITY = 1; // on the white halo a fainter orange looked pink
/** A white line under each one, this much wider, so they stand out on the satellite map (user request 2026-11-26). */
const ETMAL_HALO_EXTRA = 2;
const ETMAL_HALO_OPACITY = 0.5;

var etmalOverlays = [];
var etmalBusy = false;

function initEtmal() {
    registerMapContextMenuItem('track_changes', 'etmal.context_menu', etmalShowAt, MAP_CONTEXT_MENU_ORDER.etmal);
}

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
    etmalBusy = true;
    showToast(t('etmal.computing'), 'info');
    try {
        var answer = await Ytan.post('/water/reach', { lat: latLng.lat(), lng: latLng.lng() });
        etmalDraw(latLng, answer.data);
    } catch (err) {
        log('etmalShowAt() failed', LOG_ERROR, err);
        showToast(translateApiError(err && err.data) || (err && err.message) || t('etmal.failed'), 'error');
    } finally {
        etmalBusy = false;
    }
}

/** "25 km" (whole kilometres - the levels are), nautical "13.5 nm" like formatDistance(). */
function etmalDistanceText(km) {
    return settings.unit === 'nautical' ? formatDistance(km * 1000, 'nautical') : Math.round(km) + ' km';
}

function etmalDraw(latLng, data) {
    etmalClear();
    var drawn = 0;
    data.lines.forEach(function (line) {
        var longest = null;
        line.paths.forEach(function (rawPath) {
            var path = etmalSmoothPath(rawPath);
            etmalOverlays.push(new google.maps.Polyline({
                map: map,
                path: path,
                clickable: false,
                strokeColor: '#ffffff',
                strokeOpacity: ETMAL_HALO_OPACITY,
                strokeWeight: (line.main ? ETMAL_LINE_WIDTH : ETMAL_STEP_WIDTH) + ETMAL_HALO_EXTRA,
                zIndex: 1,
            }));
            etmalOverlays.push(new google.maps.Polyline({
                map: map,
                path: path,
                clickable: false,
                strokeColor: ETMAL_LINE_COLOR,
                strokeOpacity: line.main ? 1 : ETMAL_STEP_OPACITY,
                strokeWeight: line.main ? ETMAL_LINE_WIDTH : ETMAL_STEP_WIDTH,
                zIndex: line.main ? 3 : 2,
            }));
            drawn++;
            if (longest === null || path.length > longest.length) {
                longest = path;
            }
        });
        if (longest !== null) {
            etmalOverlays.push(new LabelMarker({
                map: map,
                position: longest[Math.floor(longest.length / 2)],
                labelContent: escapeHTML(etmalDistanceText(line.km)),
                labelClass: 'etmal-label' + (line.main ? ' etmal-label-main' : ''),
                labelAnchor: { x: 0, y: 0 },
                clickable: false,
            }));
        }
    });
    etmalOverlays.push(etmalStartMarker(latLng));

    var banner = document.getElementById('etmalBanner');
    document.getElementById('etmalBannerText').textContent = t('etmal.banner', { distance: etmalDistanceText(data.etmal_km) });
    banner.hidden = false;
    if (drawn === 0) {
        showToast(t('etmal.no_lines'), 'info');
    }
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

/** Removes the lines and the banner (the banner's x). */
function etmalClear() {
    etmalOverlays.forEach(function (overlay) {
        if (overlay) {
            overlay.setMap(null);
        }
    });
    etmalOverlays = [];
    var banner = document.getElementById('etmalBanner');
    if (banner) {
        banner.hidden = true;
    }
}
