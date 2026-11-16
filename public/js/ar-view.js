/**
 * AR view (todo.md "AR-Modus", 2026-11-16): the phone's camera picture with
 * the POIs around the user drawn over it, positioned by GPS + compass.
 *
 * - Camera: getUserMedia (back camera); needs a secure context (https or
 *   localhost) and, in the Android app, the CAMERA permission in the manifest
 *   (Capacitor's BridgeWebChromeClient passes the WebView's request on).
 * - Direction: the camera looks along the device's -Z axis; arCameraDirection()
 *   turns the W3C alpha/beta/gamma into heading + elevation, so portrait and
 *   landscape work alike. Android: 'deviceorientationabsolute' (alpha relative
 *   to north); iOS: 'deviceorientation' with webkitCompassHeading after
 *   DeviceOrientationEvent.requestPermission() (must run inside the tap).
 * - Which POIs: every loaded POI of a visible type within the radius is a
 *   candidate (arCandidates(), on every GPS fix and every 2 s); per frame only
 *   the candidates inside the camera's field of view count, the nearest N of
 *   them are drawn - the limit is for the picture, not for the whole circle
 *   (user decision). Radius and N: the user's own value (settings.arRadius /
 *   arMaxPois, sliders in Preferences and in the view) up to the admin's
 *   limit, else the admin's default (window.YTAN_AR, /admin/settings).
 * - No clutter (user requirement): arLayout() places labels from near to far;
 *   a nearer one is drawn in front (z-index), a farther one that would overlap
 *   moves up in steps until it is free (a thin stem keeps pointing at its real
 *   direction), and is left out after AR_MAX_LEVELS steps. Positions are
 *   eased between frames and a label only drops back a level when there is
 *   AR_LEVEL_HYSTERESIS px room, so nothing jumps while turning.
 * - arProject()/arLayout()/arCameraDirection() are pure functions - tested by
 *   the e2e spec.
 * - AR route mode (2026-11-16): a route picked in its context menu ("AR
 *   navigation", settings.arRouteId, kept until ended with the x on the nav
 *   bar) is drawn on the water surface (eye AR_EYE_HEIGHT_M above it) from the
 *   current position up to the range (admin default/limit + user slider,
 *   arRouteRange()), thinner with depth, into #arRouteCanvas under the POI
 *   labels; the next waypoint follows the Garmin data field's rules
 *   (arNextWaypoint() = YtanNavField.mc's initialWaypoint/arrival/skipAhead),
 *   with a numbered marker in the picture and a nav bar (arrow turned by
 *   bearing - heading, bearing, distance, n/count, rest to the finish).
 */

/* exported startArView, stopArView, arSelectPoi, arCloseInfo, arShowOnMap, arToggleSettings,
   previewArRadius, editArRadius, previewArMaxPois, editArMaxPois, arSyncSliders,
   previewArRouteRange, editArRouteRange, startArRoute, arEndRoute,
   arProject, arLayout, arCameraDirection, arBearing, arNextWaypoint, arProjectGround, arClipSegment, arToLocal */

const AR_FOV_PORTRAIT = 50; // degrees, horizontal field of view of a typical back camera held upright
const AR_FOV_LANDSCAPE = 65;
const AR_MAX_LEVELS = 4; // how often a label may move up to get out of the way
const AR_LEVEL_GAP = 4; // px between stacked labels
const AR_LEVEL_HYSTERESIS = 10; // px of free room needed before a label drops back a level
const AR_SMOOTHING = 0.2; // low-pass factor for the camera direction (per sensor event)
const AR_EASE = 0.35; // per-frame easing of label positions
const AR_CANDIDATE_INTERVAL_MS = 2000;
const AR_COMPASS_TIMEOUT_MS = 3000;
const AR_DEFAULTS = { radius_m: 2000, radius_max_m: 10000, max_pois: 10, max_pois_limit: 30, route_range_m: 3000, route_range_max_m: 10000 };
// AR route mode - the next-waypoint rules are the Garmin data field's (watch/source/YtanNavField.mc)
const AR_ARRIVAL_RADIUS_M = 50;
const AR_SKIP_MARGIN_M = 50;
const AR_LOOKAHEAD_SEGMENTS = 10;
const AR_EYE_HEIGHT_M = 1.0; // eye above the water in a kayak - the route is drawn on the water surface
const AR_NEAR_M = 2; // ground points closer than this along the view axis are clipped
const AR_ROUTE_PIECE_M = 25; // pieces the route is cut into so the line can taper smoothly
const AR_ROUTE_BEHIND_M = 50; // faint bit of route behind the position
const AR_LINE_K = 120; // line width px = AR_LINE_K / depth in m, clamped to AR_LINE_MIN..AR_LINE_MAX
const AR_LINE_MIN = 2;
const AR_LINE_MAX = 18;
const AR_ROUTE_DEFAULT_COLOR = '#BF409F';

const ar = {
    active: false,
    panelOpen: false,
    stream: null,
    watchId: null,
    timer: null,
    compassTimer: null,
    raf: null,
    direction: null, // smoothed unit vector [east, north, up] of the camera axis
    absolute: false, // true once a north-referenced reading arrived
    relativeOnly: false, // orientation events came, but none relative to north
    position: null, // {lat, lng, accuracy}
    candidates: [],
    labels: new Map(), // poi id -> {el, x, top, level, w, h}
    selectedId: null,
    orientationEvents: [],
    route: null, // AR route mode: {id, name, color, points [{lat, lng}], toEnd [m from point i to the end], state {next}}
    navText: {} // last texts written into the nav bar (only changed ones are written)
};

/** Camera, compass and a touch screen - only then the drawer row and the map context menu item show. */
function arSupported() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)
        && ('DeviceOrientationEvent' in window)
        && !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
}

function arLimits() {
    const limits = window.YTAN_AR || {};
    return {
        radius_m: Number(limits.radius_m) || AR_DEFAULTS.radius_m,
        radius_max_m: Number(limits.radius_max_m) || AR_DEFAULTS.radius_max_m,
        max_pois: Number(limits.max_pois) || AR_DEFAULTS.max_pois,
        max_pois_limit: Number(limits.max_pois_limit) || AR_DEFAULTS.max_pois_limit,
        route_range_m: Number(limits.route_range_m) || AR_DEFAULTS.route_range_m,
        route_range_max_m: Number(limits.route_range_max_m) || AR_DEFAULTS.route_range_max_m
    };
}

/** How far ahead the AR route mode draws the route (m along it): the user's value up to the admin's limit, else the default. */
function arRouteRange() {
    const limits = arLimits();
    const own = Number(settings.arRouteRange);
    return Math.round(Math.min(limits.route_range_max_m, Math.max(200, Number.isFinite(own) && own > 0 ? own : limits.route_range_m)));
}

/** The radius in meters: the user's value (if any) up to the admin's limit, else the admin's default. */
function arRadius() {
    const limits = arLimits();
    const own = Number(settings.arRadius);
    return Math.round(Math.min(limits.radius_max_m, Math.max(100, Number.isFinite(own) && own > 0 ? own : limits.radius_m)));
}

function arMaxPois() {
    const limits = arLimits();
    const own = Number(settings.arMaxPois);
    return Math.round(Math.min(limits.max_pois_limit, Math.max(1, Number.isFinite(own) && own > 0 ? own : limits.max_pois)));
}

/* ---------- pure geometry (tested, reused by the AR route mode) ---------- */

/** Initial bearing from point 1 to point 2, degrees 0..360 (0 = north). */
function arBearing(lat1, lng1, lat2, lng2) {
    const p1 = lat1 * Math.PI / 180;
    const p2 = lat2 * Math.PI / 180;
    const dl = (lng2 - lng1) * Math.PI / 180;
    const y = Math.sin(dl) * Math.cos(p2);
    const x = Math.cos(p1) * Math.sin(p2) - Math.sin(p1) * Math.cos(p2) * Math.cos(dl);
    return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
}

/**
 * The camera axis (device -Z) in earth coordinates [east, north, up] from the
 * W3C device orientation angles (degrees; alpha north-referenced). Upright
 * portrait facing north: alpha 0, beta 90, gamma 0 -> [0, 1, 0].
 */
function arCameraDirection(alpha, beta, gamma) {
    const a = alpha * Math.PI / 180;
    const b = beta * Math.PI / 180;
    const g = gamma * Math.PI / 180;
    const cA = Math.cos(a), sA = Math.sin(a), cB = Math.cos(b), sB = Math.sin(b), cG = Math.cos(g), sG = Math.sin(g);
    return [
        -cA * sG - sA * sB * cG,
        -sA * sG + cA * sB * cG,
        -cB * cG
    ];
}

/** Heading (0..360, 0 = north) and elevation (degrees, + = camera tilted up) of a direction vector. */
function arHeadingPitch(direction) {
    const [e, n, u] = direction;
    return {
        heading: (Math.atan2(e, n) * 180 / Math.PI + 360) % 360,
        pitch: Math.asin(Math.max(-1, Math.min(1, u / Math.hypot(e, n, u)))) * 180 / Math.PI
    };
}

/**
 * Where a bearing lands on the screen (pinhole camera): x of the bearing,
 * y of the horizon. diff = bearing - heading in -180..180; inView = within
 * the horizontal field of view.
 */
function arProject(bearing, heading, pitch, fovH, fovV, width, height) {
    let diff = ((bearing - heading) % 360 + 540) % 360 - 180;
    const half = fovH / 2;
    const inView = Math.abs(diff) <= half;
    const x = Math.abs(diff) < 89
        ? width / 2 + Math.tan(diff * Math.PI / 180) / Math.tan(half * Math.PI / 180) * (width / 2)
        : (diff < 0 ? -width : 2 * width);
    const horizonY = height / 2 + Math.tan(pitch * Math.PI / 180) / Math.tan(fovV / 2 * Math.PI / 180) * (height / 2);
    return { x: x, horizonY: horizonY, diff: diff, inView: inView };
}

function arOverlaps(a, b, margin) {
    return a.left < b.left + b.w + margin && b.left < a.left + a.w + margin
        && a.top < b.top + b.h + margin && b.top < a.top + a.h + margin;
}

/**
 * Places labels without overlap. items: [{id, x, anchorY, w, h}] sorted near
 * to far; previous: Map id -> level of the last frame (hysteresis). A label
 * starts with its bottom just above its anchor; when it overlaps one placed
 * before (= nearer), it moves up by its height + gap, at most AR_MAX_LEVELS
 * times, then it is left out. Returns [{id, left, top, w, h, level, rank}].
 */
function arLayout(items, previous) {
    const placed = [];
    const rectAt = (item, level) => ({
        left: item.x - item.w / 2,
        top: item.anchorY - item.h - 8 - level * (item.h + AR_LEVEL_GAP),
        w: item.w,
        h: item.h
    });
    const free = (rect, margin) => placed.every((other) => !arOverlaps(rect, other, margin));
    items.forEach((item) => {
        let lowest = -1;
        for (let level = 0; level <= AR_MAX_LEVELS; level++) {
            if (free(rectAt(item, level), 0)) {
                lowest = level;
                break;
            }
        }
        if (lowest < 0) {
            return;
        }
        let level = lowest;
        const before = previous ? previous.get(item.id) : undefined;
        // drop back to a lower level only with some room to spare, else keep the old one while it is free
        if (before !== undefined && before > lowest && before <= AR_MAX_LEVELS
            && !free(rectAt(item, lowest), AR_LEVEL_HYSTERESIS) && free(rectAt(item, before), 0)) {
            level = before;
        }
        const rect = rectAt(item, level);
        placed.push(Object.assign(rect, { id: item.id, level: level, rank: placed.length }));
    });
    return placed;
}

/** Position relative to an origin in meters [east, north] (flat - fine within the route range). */
function arToLocal(lat, lng, origin) {
    const meters = 6371000 * Math.PI / 180;
    return [(lng - origin.lng) * meters * Math.cos(origin.lat * Math.PI / 180), (lat - origin.lat) * meters];
}

/**
 * Distance from p to the segment a-b (all [east, north] in m), the foot
 * point and t (0..1 along the segment); before = p lies before a. Mirrors the
 * watch's segmentDistance().
 */
function arSegmentDistance(p, a, b) {
    const dx = b[0] - a[0];
    const dy = b[1] - a[1];
    const lengthSq = dx * dx + dy * dy;
    let t = lengthSq > 0 ? ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / lengthSq : 0;
    const before = t <= 0;
    t = Math.max(0, Math.min(1, t));
    const foot = [a[0] + t * dx, a[1] + t * dy];
    return { distance: Math.hypot(p[0] - foot[0], p[1] - foot[1]), foot: foot, t: t, before: before };
}

/**
 * The next waypoint, by the Garmin data field's rules: at the first fix the
 * waypoint ahead of the closest segment (the first of several within
 * AR_SKIP_MARGIN_M, so an out-and-back on one line starts on the way out);
 * then every waypoint within AR_ARRIVAL_RADIUS_M counts as reached; and a
 * clearly (AR_SKIP_MARGIN_M) closer segment within AR_LOOKAHEAD_SEGMENTS is
 * a shortcut. state = {next: -1} at the start, kept between fixes.
 * Returns {next, done, reached} (reached = waypoints passed by this fix).
 */
function arNextWaypoint(state, lat, lng, points) {
    const count = points.length;
    const here = { lat: lat, lng: lng };
    const local = points.map((p) => arToLocal(p.lat, p.lng, here));
    const at = [0, 0];
    const before = state.next;
    if (state.next < 0) {
        if (count === 1) {
            state.next = 0;
        } else {
            let closest = Infinity;
            for (let i = 0; i < count - 1; i++) {
                closest = Math.min(closest, arSegmentDistance(at, local[i], local[i + 1]).distance);
            }
            for (let i = 0; i < count - 1; i++) {
                const segment = arSegmentDistance(at, local[i], local[i + 1]);
                if (segment.distance <= closest + AR_SKIP_MARGIN_M) {
                    state.next = (i === 0 && segment.before) ? 0 : i + 1;
                    break;
                }
            }
        }
    }
    while (state.next < count && Math.hypot(local[state.next][0], local[state.next][1]) <= AR_ARRIVAL_RADIUS_M) {
        state.next++;
    }
    if (state.next < count && count >= 2) {
        const current = state.next > 0
            ? arSegmentDistance(at, local[state.next - 1], local[state.next]).distance
            : Math.hypot(local[0][0], local[0][1]);
        const last = Math.min(state.next + AR_LOOKAHEAD_SEGMENTS - 1, count - 2);
        let best = -1;
        let bestDistance = 0;
        for (let i = state.next; i <= last; i++) {
            const d = arSegmentDistance(at, local[i], local[i + 1]).distance;
            if (best < 0 || d < bestDistance) {
                best = i;
                bestDistance = d;
            }
        }
        if (best >= 0 && bestDistance < current - AR_SKIP_MARGIN_M) {
            state.next = best + 1;
        }
    }
    return { next: state.next, done: state.next >= count, reached: before < 0 ? 0 : Math.max(0, state.next - before) };
}

/** Camera basis from the view direction: forward, right, up (horizon kept level). null when looking straight up/down. */
function arCameraBasis(direction) {
    const f = direction;
    const rightLength = Math.hypot(f[1], f[0]);
    if (rightLength < 0.05) {
        return null;
    }
    const right = [f[1] / rightLength, -f[0] / rightLength, 0];
    const up = [
        right[1] * f[2] - right[2] * f[1],
        right[2] * f[0] - right[0] * f[2],
        right[0] * f[1] - right[1] * f[0]
    ];
    return { forward: f, right: right, up: up };
}

/** A point [east, north, up] (m, relative to the eye) in camera space: [right, up, depth]. */
function arToCamera(point, basis) {
    const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
    return [dot(point, basis.right), dot(point, basis.up), dot(point, basis.forward)];
}

/** Screen position of a camera-space point (pinhole, like arProject()); null behind the near plane. */
function arCameraToScreen(c, fovH, fovV, width, height) {
    if (c[2] < AR_NEAR_M) {
        return null;
    }
    return {
        x: width / 2 + (c[0] / c[2]) / Math.tan(fovH / 2 * Math.PI / 180) * (width / 2),
        y: height / 2 - (c[1] / c[2]) / Math.tan(fovV / 2 * Math.PI / 180) * (height / 2),
        depth: c[2]
    };
}

/** A point on the water [east, north] (m from the eye) on the screen, or null when behind the camera. */
function arProjectGround(east, north, direction, fovH, fovV, width, height) {
    const basis = arCameraBasis(direction);
    return basis ? arCameraToScreen(arToCamera([east, north, -AR_EYE_HEIGHT_M], basis), fovH, fovV, width, height) : null;
}

/** Cuts a camera-space segment at the near plane; null when it lies wholly behind. */
function arClipSegment(a, b) {
    if (a[2] < AR_NEAR_M && b[2] < AR_NEAR_M) {
        return null;
    }
    if (a[2] >= AR_NEAR_M && b[2] >= AR_NEAR_M) {
        return [a, b];
    }
    const t = (AR_NEAR_M - a[2]) / (b[2] - a[2]);
    const cut = [a[0] + t * (b[0] - a[0]), a[1] + t * (b[1] - a[1]), AR_NEAR_M];
    return a[2] < AR_NEAR_M ? [cut, b] : [a, cut];
}

/* ---------- settings ---------- */

function arRadiusLabel(meters) {
    return formatDistance(meters, settings.unit);
}

/** Sets both radius sliders (Preferences and the view) and their labels; called by loadSettings() too. */
function arSyncSliders() {
    const limits = arLimits();
    const radius = arRadius();
    const pois = arMaxPois();
    ['arRadius', 'arRadiusInView'].forEach((id) => {
        const slider = document.getElementById(id);
        if (slider) {
            slider.max = String(limits.radius_max_m);
            slider.value = String(radius);
        }
    });
    ['arMaxPois', 'arMaxPoisInView'].forEach((id) => {
        const slider = document.getElementById(id);
        if (slider) {
            slider.max = String(limits.max_pois_limit);
            slider.value = String(pois);
        }
    });
    const range = arRouteRange();
    ['arRouteRange', 'arRouteRangeInView'].forEach((id) => {
        const slider = document.getElementById(id);
        if (slider) {
            slider.max = String(limits.route_range_max_m);
            slider.value = String(range);
        }
    });
    previewArRadius(radius);
    previewArMaxPois(pois);
    previewArRouteRange(range);
}

function previewArRouteRange(value) {
    document.querySelectorAll('.ar-route-range-value').forEach((label) => {
        label.textContent = arRadiusLabel(Number(value));
    });
}

function editArRouteRange(element) {
    settings.arRouteRange = Math.round(Number(element.value));
    arSyncSliders();
    saveSettings();
}

function previewArRadius(value) {
    document.querySelectorAll('.ar-radius-value').forEach((label) => {
        label.textContent = arRadiusLabel(Number(value));
    });
}

function editArRadius(element) {
    settings.arRadius = Math.round(Number(element.value));
    arSyncSliders();
    saveSettings();
    if (ar.active) {
        arCandidates();
    }
}

function previewArMaxPois(value) {
    document.querySelectorAll('.ar-max-pois-value').forEach((label) => {
        label.textContent = String(Math.round(Number(value)));
    });
}

function editArMaxPois(element) {
    settings.arMaxPois = Math.round(Number(element.value));
    arSyncSliders();
    saveSettings();
}

function arToggleSettings() {
    const panel = document.getElementById('arSettings');
    panel.hidden = !panel.hidden;
}

/* ---------- start / stop ---------- */

function startArView() {
    if (ar.active) {
        return;
    }
    if (!arSupported()) {
        showToast(t('ar.not_supported'), 'warning');
        return;
    }
    // iOS: the compass needs a permission that may only be asked inside the tap - before any await
    let orientationPermission = Promise.resolve('granted');
    if (typeof DeviceOrientationEvent !== 'undefined' && typeof DeviceOrientationEvent.requestPermission === 'function') {
        orientationPermission = DeviceOrientationEvent.requestPermission().catch(() => 'denied');
    }
    if (typeof closeMenu === 'function') {
        closeMenu();
    }
    ar.active = true;
    ar.direction = null;
    ar.absolute = false;
    ar.relativeOnly = false;
    ar.position = null;
    ar.candidates = [];
    ar.selectedId = null;

    const view = document.getElementById('arView');
    view.style.display = 'block';
    panelOpened();
    ar.panelOpen = true;
    document.getElementById('arSettings').hidden = true;
    document.getElementById('arInfoCard').hidden = true;
    arSyncSliders();
    arLoadRoute();
    arSetStatus(t('ar.waiting_position'));

    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }).then((stream) => {
        if (!ar.active) {
            stream.getTracks().forEach((track) => track.stop());
            return;
        }
        ar.stream = stream;
        const video = document.getElementById('arVideo');
        video.srcObject = stream;
        video.play().catch(() => {});
        view.classList.add('visible');
    }).catch((error) => {
        log('AR camera: ' + error.name + ' ' + error.message, LOG_WARN);
        let message = t('ar.camera_denied');
        if (error.name === 'NotFoundError' || error.name === 'OverconstrainedError') {
            message = t('ar.no_camera');
        } else if (error.name === 'NotAllowedError' && typeof CapacitorBridge !== 'undefined' && CapacitorBridge.isAvailable()) {
            // an APK built before the CAMERA permission was added to the manifest
            message = t('ar.camera_app_update');
        }
        showToast(message, 'error');
        stopArView();
    });

    orientationPermission.then((state) => {
        if (!ar.active) {
            return;
        }
        if (state !== 'granted') {
            arSetWarning(t('ar.compass_denied'));
            return;
        }
        // both: Android sends north-referenced values as 'deviceorientationabsolute', iOS (webkitCompassHeading)
        // and older Androids (absolute: true) as 'deviceorientation'; relative-only readings are ignored
        ar.orientationEvents = ['deviceorientationabsolute', 'deviceorientation'];
        ar.orientationEvents.forEach((type) => window.addEventListener(type, arOnOrientation, true));
        ar.compassTimer = window.setTimeout(() => {
            if (ar.active && !ar.absolute) {
                arSetWarning(ar.relativeOnly ? t('ar.compass_unavailable') : t('ar.compass_missing'));
            }
        }, AR_COMPASS_TIMEOUT_MS);
    });

    if (navigator.geolocation) {
        ar.watchId = navigator.geolocation.watchPosition(arOnPosition, arOnPositionError, { enableHighAccuracy: true, timeout: 20000, maximumAge: 2000 });
    } else {
        arSetWarning(t('ar.no_position'), 'position');
    }
    ar.timer = window.setInterval(arCandidates, AR_CANDIDATE_INTERVAL_MS);
    document.addEventListener('keydown', arKeydown);
    document.addEventListener('visibilitychange', arVisibilityChange);
    ar.raf = window.requestAnimationFrame(arFrame);
}

function stopArView() {
    if (!ar.active && !ar.panelOpen) {
        return;
    }
    ar.active = false;
    if (ar.stream) {
        ar.stream.getTracks().forEach((track) => track.stop());
        ar.stream = null;
    }
    const video = document.getElementById('arVideo');
    if (video) {
        video.srcObject = null;
    }
    if (ar.watchId !== null && navigator.geolocation) {
        navigator.geolocation.clearWatch(ar.watchId);
    }
    ar.watchId = null;
    ar.orientationEvents.forEach((type) => window.removeEventListener(type, arOnOrientation, true));
    ar.orientationEvents = [];
    window.clearInterval(ar.timer);
    window.clearTimeout(ar.compassTimer);
    window.cancelAnimationFrame(ar.raf);
    document.removeEventListener('keydown', arKeydown);
    document.removeEventListener('visibilitychange', arVisibilityChange);
    ar.labels.clear();
    document.getElementById('arLabels').innerHTML = '';
    ar.route = null;
    arShowRoute(false);
    document.getElementById('arInfoCard').hidden = true;
    const view = document.getElementById('arView');
    view.classList.remove('visible');
    view.style.display = 'none';
    arSetWarning('');
    if (ar.panelOpen) {
        ar.panelOpen = false;
        panelClosed();
    }
}

function arKeydown(event) {
    if (event.key === 'Escape') {
        if (!document.getElementById('arInfoCard').hidden) {
            arCloseInfo();
        } else {
            stopArView();
        }
    }
}

/** The camera must not keep running behind a locked screen or another app. */
function arVisibilityChange() {
    if (document.hidden) {
        stopArView();
    }
}

/* ---------- sensors ---------- */

function arOnOrientation(event) {
    let alpha = null;
    if (typeof event.webkitCompassHeading === 'number' && !Number.isNaN(event.webkitCompassHeading)) {
        alpha = 360 - event.webkitCompassHeading; // iOS
    } else if (event.type === 'deviceorientationabsolute' || event.absolute === true) {
        alpha = event.alpha;
    }
    if (alpha === null || event.beta === null || event.gamma === null) {
        ar.relativeOnly = true;
        return;
    }
    if (!ar.absolute) {
        ar.absolute = true;
        if (ar.warningSource !== 'position') {
            arSetWarning('');
        }
    }
    const fresh = arCameraDirection(alpha, event.beta, event.gamma);
    if (!ar.direction) {
        ar.direction = fresh;
        return;
    }
    // low-pass on the vector: no jump at 359 -> 0 degrees
    const mixed = ar.direction.map((value, i) => value + (fresh[i] - value) * AR_SMOOTHING);
    const length = Math.hypot(mixed[0], mixed[1], mixed[2]) || 1;
    ar.direction = mixed.map((value) => value / length);
}

function arOnPosition(position) {
    ar.position = { lat: position.coords.latitude, lng: position.coords.longitude, accuracy: position.coords.accuracy };
    // a fix ends a position warning (not a compass one)
    if (ar.warningSource === 'position') {
        arSetWarning('');
    }
    arCandidates();
    arUpdateRoute();
}

function arOnPositionError(error) {
    log('AR position: ' + error.code + ' ' + error.message, LOG_WARN);
    // a timeout while fixes keep coming (an earlier one is still fresh) is no reason to warn
    if (error.code !== 1 && ar.position) {
        return;
    }
    arSetWarning(error.code === 1 ? t('ar.position_denied') : t('ar.no_position'), 'position');
}

/* ---------- candidates and drawing ---------- */

/** Every loaded POI of a visible type within the radius, with distance and bearing. */
function arCandidates() {
    if (!ar.position) {
        return;
    }
    const radius = arRadius();
    const here = ar.position;
    const list = [];
    pois.forEach((poi, index) => {
        if (!poi || settings['detail' + poi.poitype_id] === false) {
            return;
        }
        const lat = Number(poi.latitude);
        const lng = Number(poi.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return;
        }
        const distance = getDistance(here, { lat: lat, lng: lng });
        if (distance > radius) {
            return;
        }
        list.push({
            id: poi.id, index: index, type: Number(poi.poitype_id), name: poi.name,
            lat: lat, lng: lng, distance: distance, bearing: arBearing(here.lat, here.lng, lat, lng)
        });
    });
    list.sort((a, b) => a.distance - b.distance);
    ar.candidates = list;
    const status = t('ar.status', {
        accuracy: Math.round(here.accuracy || 0),
        count: list.length,
        radius: arRadiusLabel(radius)
    });
    arSetStatus(status);
    if (ar.selectedId !== null) {
        arFillInfo(list.find((c) => c.id === ar.selectedId));
    }
}

function arBadgeSize(distance, radius) {
    return Math.round(44 - 16 * Math.min(1, distance / Math.max(1, radius)));
}

function arLabelElement(candidate, radius) {
    let entry = ar.labels.get(candidate.id);
    const size = arBadgeSize(candidate.distance, radius);
    const distanceText = arRadiusLabel(candidate.distance);
    if (!entry) {
        const el = document.createElement('button');
        el.type = 'button';
        el.className = 'ar-label';
        el.setAttribute('data-on-click', 'arSelectPoi(' + Number(candidate.id) + ')');
        entry = { el: el, x: null, top: null, size: null, text: null };
        ar.labels.set(candidate.id, entry);
        document.getElementById('arLabels').appendChild(el);
    }
    if (entry.size !== size || entry.text !== distanceText) {
        entry.el.innerHTML = '<span class="ar-stem"></span>' + arBadgeSvg(candidate.type, size)
            + '<span class="ar-chip"><b>' + escapeText(candidate.name) + '</b><i>' + escapeHTML(distanceText) + '</i></span>';
        entry.size = size;
        entry.text = distanceText;
    }
    return entry;
}

function arFrame() {
    if (!ar.active) {
        return;
    }
    ar.raf = window.requestAnimationFrame(arFrame);
    if (document.hidden || !ar.direction || !ar.position) {
        return;
    }
    const container = document.getElementById('arLabels');
    const width = container.clientWidth;
    const height = container.clientHeight;
    if (!width || !height) {
        return;
    }
    const { heading, pitch } = arHeadingPitch(ar.direction);
    const fovH = width > height ? AR_FOV_LANDSCAPE : AR_FOV_PORTRAIT;
    const fovV = 2 * Math.atan(Math.tan(fovH / 2 * Math.PI / 180) * height / width) * 180 / Math.PI;
    if (ar.route) {
        arDrawRoute(arCameraBasis(ar.direction), fovH, fovV, width, height);
        arUpdateNavBar(heading);
    }
    const radius = arRadius();
    let left = 0;
    let right = 0;
    const visible = [];
    ar.candidates.forEach((candidate) => {
        const p = arProject(candidate.bearing, heading, pitch, fovH, fovV, width, height);
        if (!p.inView) {
            if (p.diff < 0) {
                left++;
            } else {
                right++;
            }
            return;
        }
        visible.push({ candidate: candidate, x: p.x, horizonY: p.horizonY });
    });
    // the limit is for the picture: the nearest N in view
    const shown = visible.slice(0, arMaxPois());
    const items = shown.map((v) => {
        const entry = arLabelElement(v.candidate, radius);
        // nearer POIs sit a little lower, like things closer to the viewer
        const anchorY = v.horizonY + 40 * (1 - Math.min(1, v.candidate.distance / radius));
        return { id: v.candidate.id, x: v.x, anchorY: anchorY, w: entry.el.offsetWidth, h: entry.el.offsetHeight, entry: entry, distance: v.candidate.distance };
    });
    const previous = new Map();
    ar.labels.forEach((entry, id) => {
        if (entry.level !== undefined) {
            previous.set(id, entry.level);
        }
    });
    const placed = arLayout(items, previous);
    const keep = new Set();
    placed.forEach((rect) => {
        const item = items.find((i) => i.id === rect.id);
        const entry = item.entry;
        keep.add(rect.id);
        entry.level = rect.level;
        entry.x = entry.x === null ? rect.left : entry.x + (rect.left - entry.x) * AR_EASE;
        entry.top = entry.top === null ? rect.top : entry.top + (rect.top - entry.top) * AR_EASE;
        entry.el.style.transform = 'translate(' + entry.x.toFixed(1) + 'px,' + entry.top.toFixed(1) + 'px)';
        entry.el.style.zIndex = String(1000 - rect.rank);
        entry.el.style.opacity = String(1 - 0.3 * Math.min(1, item.distance / radius));
        // the stem reaches down to the label's real direction on the horizon line
        const stem = entry.el.querySelector('.ar-stem');
        if (stem) {
            stem.style.height = Math.max(6, item.anchorY - (entry.top + rect.h)).toFixed(0) + 'px';
        }
        entry.el.dataset.level = String(rect.level);
        entry.el.hidden = false;
    });
    ar.labels.forEach((entry, id) => {
        if (!keep.has(id)) {
            entry.el.remove();
            ar.labels.delete(id);
        }
    });
    arSetEdge('arEdgeLeft', left);
    arSetEdge('arEdgeRight', right);
}

function arSetEdge(id, count) {
    const edge = document.getElementById(id);
    edge.hidden = count === 0;
    edge.querySelector('.ar-edge-count').textContent = String(count);
}

function arSetStatus(text) {
    document.getElementById('arStatus').textContent = text;
}

/** source: 'position' for GPS warnings (cleared by the next fix), else a compass/other one. */
function arSetWarning(text, source) {
    const warning = document.getElementById('arWarning');
    warning.textContent = text;
    warning.hidden = text === '';
    ar.warningSource = text === '' ? null : (source || 'other');
}

/* ---------- AR route mode ---------- */

/** Starts the AR view with this route (route context menu "AR navigation"); kept until ended in the view. */
function startArRoute(routeId) {
    settings.arRouteId = routeId;
    saveSettings();
    if (ar.active) {
        arLoadRoute();
    } else {
        startArView();
    }
}

/** Ends the route mode (x on the nav bar); the POIs stay. */
function arEndRoute() {
    settings.arRouteId = null;
    saveSettings();
    ar.route = null;
    arShowRoute(false);
}

/** The stored route if it is (still) loaded on the map - a deleted or no longer visible one is dropped silently. */
function arLoadRoute() {
    ar.route = null;
    if (settings.arRouteId === null || settings.arRouteId === undefined) {
        arShowRoute(false);
        return;
    }
    const route = routes.find((r) => r && String(r.id) === String(settings.arRouteId) && Array.isArray(r.points) && r.points.length >= 2);
    if (!route) {
        settings.arRouteId = null;
        saveSettings();
        arShowRoute(false);
        return;
    }
    const points = route.points.map((p) => ({ lat: Number(p.lat), lng: Number(p.lng) }));
    const toEnd = new Array(points.length).fill(0);
    for (let i = points.length - 2; i >= 0; i--) {
        toEnd[i] = toEnd[i + 1] + getDistance(points[i], points[i + 1]);
    }
    ar.route = { id: route.id, name: route.name, color: route.color || AR_ROUTE_DEFAULT_COLOR, points: points, toEnd: toEnd, state: { next: -1 }, result: null };
    ar.navText = {};
    arShowRoute(true);
    if (ar.position) {
        arUpdateRoute();
    }
}

function arShowRoute(on) {
    document.getElementById('arNavBar').hidden = !on;
    document.getElementById('arView').classList.toggle('ar-route-on', on);
    if (!on) {
        document.getElementById('arTarget').hidden = true;
        const canvas = document.getElementById('arRouteCanvas');
        canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
    }
}

/** Next waypoint on every GPS fix (a short buzz when one is reached, like the watch). */
function arUpdateRoute() {
    const route = ar.route;
    if (!route || !ar.position) {
        return;
    }
    route.result = arNextWaypoint(route.state, ar.position.lat, ar.position.lng, route.points);
    if (route.result.reached > 0 && navigator.vibrate) {
        navigator.vibrate(route.result.done ? [200, 100, 200] : 120);
    }
}

function arSetNavText(id, text) {
    if (ar.navText[id] !== text) {
        ar.navText[id] = text;
        document.getElementById(id).textContent = text;
    }
}

/** Nav bar: arrow to the next waypoint (turned by bearing - heading), bearing, distance, counter, rest. */
function arUpdateNavBar(heading) {
    const route = ar.route;
    const result = route.result;
    if (!result) {
        return;
    }
    const count = route.points.length;
    if (result.done) {
        arSetNavText('arNavMain', t('ar.route.finished'));
        arSetNavText('arNavSub', route.name ? decodeHtmlEntities(String(route.name)) : '');
        document.getElementById('arNavArrow').style.visibility = 'hidden';
        return;
    }
    const target = route.points[result.next];
    const distance = getDistance(ar.position, target);
    const bearing = arBearing(ar.position.lat, ar.position.lng, target.lat, target.lng);
    const arrow = document.getElementById('arNavArrow');
    arrow.style.visibility = '';
    arrow.style.transform = 'rotate(' + (((bearing - heading) % 360 + 360) % 360).toFixed(1) + 'deg)';
    arSetNavText('arNavMain', Math.round(bearing) + '° · ' + arRadiusLabel(distance));
    arSetNavText('arNavSub', t('ar.route.waypoint', {
        n: result.next + 1,
        count: count,
        rest: arRadiusLabel(distance + route.toEnd[result.next])
    }));
}

/**
 * Draws the route ahead into the canvas: from the foot point on the current
 * segment along the route up to the range (plus a faint bit behind), cut in
 * AR_ROUTE_PIECE_M pieces that get thinner with depth; and places the
 * next-waypoint marker.
 */
function arDrawRoute(basis, fovH, fovV, width, height) {
    const canvas = document.getElementById('arRouteCanvas');
    const ratio = Math.min(2, window.devicePixelRatio || 1);
    if (canvas.width !== Math.round(width * ratio) || canvas.height !== Math.round(height * ratio)) {
        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);
    }
    const ctx = canvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);
    const route = ar.route;
    const target = document.getElementById('arTarget');
    const result = route.result;
    if (!result || result.done || !basis) {
        target.hidden = true;
        return;
    }
    const here = ar.position;
    const local = route.points.map((p) => arToLocal(p.lat, p.lng, here));
    const range = arRouteRange();
    const next = result.next;
    // start: the foot point on the leg towards the next waypoint (or the first waypoint itself)
    let start = local[next];
    if (next > 0) {
        start = arSegmentDistance([0, 0], local[next - 1], local[next]).foot;
    }
    const pieces = [];
    const addLeg = (a, b, along, faint) => {
        const length = Math.hypot(b[0] - a[0], b[1] - a[1]);
        const steps = Math.max(1, Math.ceil(length / AR_ROUTE_PIECE_M));
        for (let s = 0; s < steps; s++) {
            const t0 = s / steps;
            const t1 = (s + 1) / steps;
            const from = along + length * t0;
            if (!faint && from >= range) {
                return false;
            }
            pieces.push({
                a: [a[0] + (b[0] - a[0]) * t0, a[1] + (b[1] - a[1]) * t0],
                b: [a[0] + (b[0] - a[0]) * t1, a[1] + (b[1] - a[1]) * t1],
                along: from,
                faint: faint
            });
        }
        return true;
    };
    // off the route (or before its start): a faint connection from where the user is to where the route
    // continues - without it a route seen from a kayak (eye 1 m above the water) is only a thin line at the horizon
    if (Math.hypot(start[0], start[1]) > 10) {
        addLeg([0, 0], start, 0, true);
    }
    // a short faint bit behind
    if (next > 0) {
        const back = local[next - 1];
        const length = Math.hypot(start[0] - back[0], start[1] - back[1]);
        if (length > 0) {
            const k = Math.min(1, AR_ROUTE_BEHIND_M / length);
            addLeg([start[0] + (back[0] - start[0]) * k, start[1] + (back[1] - start[1]) * k], start, 0, true);
        }
    }
    // ahead: start -> next waypoint -> ... until the range
    let along = 0;
    let previous = start;
    for (let i = next; i < local.length; i++) {
        if (!addLeg(previous, local[i], along, false)) {
            break;
        }
        along += Math.hypot(local[i][0] - previous[0], local[i][1] - previous[1]);
        previous = local[i];
        if (along >= range) {
            break;
        }
    }
    // far to near, so the near pieces lie on top; dark outline first, colour on top
    const eye = -AR_EYE_HEIGHT_M;
    const drawn = [];
    pieces.forEach((piece) => {
        const clipped = arClipSegment(arToCamera([piece.a[0], piece.a[1], eye], basis), arToCamera([piece.b[0], piece.b[1], eye], basis));
        if (!clipped) {
            return;
        }
        const p = arCameraToScreen(clipped[0], fovH, fovV, width, height);
        const q = arCameraToScreen(clipped[1], fovH, fovV, width, height);
        if (!p || !q) {
            return;
        }
        const depth = (clipped[0][2] + clipped[1][2]) / 2;
        drawn.push({
            p: p, q: q, depth: depth,
            width: Math.max(AR_LINE_MIN, Math.min(AR_LINE_MAX, AR_LINE_K / depth)),
            alpha: piece.faint ? 0.35 : 1 - 0.5 * Math.min(1, piece.along / range)
        });
    });
    drawn.sort((x, y) => y.depth - x.depth);
    ctx.lineCap = 'round';
    drawn.forEach((d) => {
        ctx.globalAlpha = d.alpha * 0.55;
        ctx.strokeStyle = '#000';
        ctx.lineWidth = d.width + 3;
        ctx.beginPath();
        ctx.moveTo(d.p.x, d.p.y);
        ctx.lineTo(d.q.x, d.q.y);
        ctx.stroke();
    });
    drawn.forEach((d) => {
        ctx.globalAlpha = d.alpha;
        ctx.strokeStyle = route.color;
        ctx.lineWidth = d.width;
        ctx.beginPath();
        ctx.moveTo(d.p.x, d.p.y);
        ctx.lineTo(d.q.x, d.q.y);
        ctx.stroke();
    });
    ctx.globalAlpha = 1;

    // next waypoint marker (above the POI labels, never pushed aside by arLayout())
    const marker = arCameraToScreen(arToCamera([local[next][0], local[next][1], eye], basis), fovH, fovV, width, height);
    if (marker && marker.x >= -20 && marker.x <= width + 20 && marker.y >= -20 && marker.y <= height + 20) {
        target.hidden = false;
        target.style.transform = 'translate(' + marker.x.toFixed(1) + 'px,' + marker.y.toFixed(1) + 'px)';
        const number = String(next + 1);
        if (target.textContent !== number) {
            target.textContent = number;
        }
    } else {
        target.hidden = true;
    }
}

/* ---------- info card ---------- */

function arCompassPoint(bearing) {
    switch (Math.round(bearing / 45) % 8) {
        case 1: return t('ar.dir.ne');
        case 2: return t('ar.dir.e');
        case 3: return t('ar.dir.se');
        case 4: return t('ar.dir.s');
        case 5: return t('ar.dir.sw');
        case 6: return t('ar.dir.w');
        case 7: return t('ar.dir.nw');
        default: return t('ar.dir.n');
    }
}

function arSelectPoi(id) {
    const candidate = ar.candidates.find((c) => c.id === id || String(c.id) === String(id));
    if (!candidate) {
        return;
    }
    ar.selectedId = candidate.id;
    arFillInfo(candidate);
    document.getElementById('arInfoCard').hidden = false;
}

function arFillInfo(candidate) {
    if (!candidate) {
        return;
    }
    document.getElementById('arInfoBadge').innerHTML = arBadgeSvg(candidate.type, 44);
    document.getElementById('arInfoName').textContent = decodeHtmlEntities(String(candidate.name ?? ''));
    document.getElementById('arInfoType').textContent = arTypeName(candidate.type);
    document.getElementById('arInfoDistance').textContent = t('ar.info_distance', {
        distance: arRadiusLabel(candidate.distance),
        bearing: Math.round(candidate.bearing),
        direction: arCompassPoint(candidate.bearing)
    });
}

function arCloseInfo() {
    ar.selectedId = null;
    document.getElementById('arInfoCard').hidden = true;
}

/** Closes the view and opens the POI's info window on the map. */
function arShowOnMap() {
    const candidate = ar.candidates.find((c) => c.id === ar.selectedId);
    stopArView();
    if (!candidate || typeof map === 'undefined' || !map) {
        return;
    }
    map.setCenter({ lat: candidate.lat, lng: candidate.lng });
    if (map.getZoom() < 15) {
        map.setZoom(15);
    }
    google.maps.event.addListenerOnce(map, 'idle', () => {
        const marker = markers[candidate.id];
        if (!marker) {
            return;
        }
        marker.setMap(map);
        if (marker.real) {
            showPoiInfoWindow(null, candidate.index, marker.real);
        }
    });
}

/* ---------- entry points ---------- */

if (arSupported()) {
    registerMapContextMenuItem('photo_camera', 'ar.context_menu', function () {
        startArView();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const row = document.getElementById('arMenuRow');
    if (row && arSupported()) {
        row.style.display = '';
    }
    arSyncSliders();
});
