/**
 * Route and tour animation (todo.md "Animierte Routen- und Tourenpräsentation").
 *
 * A route (route context menu "Zeige Routenanimation") or the routes of the
 * active tour (play button in #tourModeBadge, in tour order - routes[] holds
 * exactly them in Tour Mode, RouteRepository::findByTour()) are followed by a
 * camera from above and behind: tilted, turned in the direction of travel,
 * at the admin's zoom level, while a line in the admin's colour and width is
 * drawn over the route as it goes (window.YTAN_ROUTE_ANIMATION, /admin/settings).
 * At the end of each route a position marker is set; after the last one the
 * picture stays for the admin's pause, then the camera swings to the main
 * map's view from before (straight from above) - the animation stays open
 * until it is closed, ⏮ jumps back to the start. The speed starts at
 * the admin's default (screen px per second at that zoom); the -/+ buttons at
 * the bottom change it by a factor (ROUTE_ANIMATION_SPEED_FACTORS); the buttons
 * in front of them play it backwards, pause it or play it forwards (keys:
 * left/right arrow, space bar). Backwards undoes the line and the markers.
 *
 * Tilt and heading only exist on Google's VECTOR renderer, and the main map
 * is raster (its Map ID's setting) - switching that would change every
 * overlay drawn on it (radar/wind canvases, labels). So the animation runs on
 * its own temporary vector map in #routeAnimation over the whole screen and
 * removes it at the end: the main map underneath never moves, which is
 * exactly "back to the view from before, straight from above". The vector
 * renderer compiles WebAssembly, hence 'wasm-unsafe-eval' in the CSP
 * (ContentSecurityPolicy.php). Should a browser fall back to raster anyway
 * (no WebGL), the camera still follows the route, just from straight above.
 */

const ROUTE_ANIMATION_TILT = 60;            // degrees; vector maps allow up to 67.5 at close zoom levels
const ROUTE_ANIMATION_TILT_IN_S = 1.5;      // the tilt grows from 0 over this time at the start
const ROUTE_ANIMATION_SPEED_FACTORS = [0.25, 0.5, 1, 1.5, 2, 3, 4, 6, 8]; // the -/+ buttons step through these
const ROUTE_ANIMATION_SPEED_DEFAULT_INDEX = 2; // 1x = the admin's default speed
const ROUTE_ANIMATION_MAX_S = 120;          // at 1x a long tour is sped up to fit into this time
const ROUTE_ANIMATION_GAP_MAX_S = 2;        // camera move between two routes of a tour (not drawn)
const ROUTE_ANIMATION_LOOKAHEAD_PX = 120;   // heading = bearing to the point this far ahead
const ROUTE_ANIMATION_TURN_RATE = 2.5;      // heading smoothing (1/s): higher follows bends faster
const ROUTE_ANIMATION_OVERVIEW_S = 2;       // swing to the view from before after the end pause

let routeAnimation = null; // the running animation, null when none

function routeAnimationSettings() {
    const s = window.YTAN_ROUTE_ANIMATION || {};
    return {
        color: s.color || '#FF6A00',
        lineWidth: Number(s.line_width) || 6,
        zoom: Number(s.zoom) || 15,
        endDelayS: Number.isFinite(Number(s.end_delay_s)) ? Number(s.end_delay_s) : 2,
        speed: Number(s.speed) || 250 // px per second the route moves under the camera
    };
}

/** Route context menu: "Zeige Routenanimation". */
function routeContextMenuAnimate(i) {
    closeContextMenu();
    startRouteAnimation([routes[i]]);
}

/** Play button in #tourModeBadge: all routes of the active tour, in tour order. */
function playTourAnimation() {
    startRouteAnimation(routes.filter(r => typeof r !== 'undefined'));
}

/** The - button: one step slower. */
function routeAnimationSlower() {
    routeAnimationChangeSpeed(-1);
}

/** The + button: one step faster. */
function routeAnimationFaster() {
    routeAnimationChangeSpeed(1);
}

function routeAnimationChangeSpeed(step) {
    if (routeAnimation === null) {
        return;
    }
    routeAnimation.speedIndex = Math.max(0, Math.min(ROUTE_ANIMATION_SPEED_FACTORS.length - 1, routeAnimation.speedIndex + step));
    routeAnimationApplySpeed();
}

/** Speed from the chosen factor, plus the label and the buttons' disabled state. */
function routeAnimationApplySpeed() {
    const anim = routeAnimation;
    const factor = ROUTE_ANIMATION_SPEED_FACTORS[anim.speedIndex];
    anim.speed = anim.baseSpeed * factor;
    document.getElementById('routeAnimationSpeedLabel').textContent = factor.toLocaleString(document.documentElement.lang || undefined) + '\u00D7';
    document.getElementById('routeAnimationSlower').disabled = anim.speedIndex === 0;
    document.getElementById('routeAnimationFaster').disabled = anim.speedIndex === ROUTE_ANIMATION_SPEED_FACTORS.length - 1;
}

/** The ◀ button (and the left arrow key): play backwards. */
function routeAnimationPlayBackward() {
    routeAnimationSetState(false, -1);
}

/** The ▶ button (and the right arrow key): play forwards. */
function routeAnimationPlayForward() {
    routeAnimationSetState(false, 1);
}

/** The ⏸ button. */
function routeAnimationPause() {
    routeAnimationSetState(true, routeAnimation === null ? 1 : routeAnimation.direction);
}

/** The space bar: pause, or go on in the direction it last ran. */
function routeAnimationTogglePause() {
    if (routeAnimation !== null) {
        routeAnimationSetState(!routeAnimation.paused, routeAnimation.direction);
    }
}

/**
 * The ⏮ button (and the Home key): back to the start - line, markers and
 * camera as at the beginning. Plays on forwards unless it was paused
 * mid-route (at the end it always plays again).
 */
function routeAnimationRestart() {
    const anim = routeAnimation;
    if (anim === null) {
        return;
    }
    const keepPaused = anim.paused && !anim.done;
    routeAnimationStopTimers(anim);
    for (const leg of anim.legs) {
        leg.drawnIndex = 0;
        leg.line.setPath([leg.points[0]]);
    }
    anim.legIndex = 0;
    anim.distance = 0;
    anim.gap = null;
    anim.done = false;
    anim.overview = null;
    anim.overviewDone = false;
    anim.elapsed = 0; // the tilt grows in again
    routeAnimationSyncMarkers(anim);
    anim.heading = routeAnimationStartHeading(anim);
    anim.map.moveCamera({ center: anim.legs[0].points[0], zoom: anim.cfg.zoom, tilt: 0, heading: anim.heading });
    routeAnimationSetState(keepPaused, 1);
}

/**
 * Pause/direction. Paused, no frame runs and the clock stands still. Forwards
 * past the last waypoint only the end timer runs (stopped by a pause, started
 * again from the full pause) and then the camera swings to the view from
 * before (routeAnimationShowOverview()); the animation stays open until it
 * is closed. ▶ after that plays it again from the start. Backwards from the
 * end the animation runs again and undoes the line and the markers.
 * Backwards at the very start there is nothing left to undo, so it stays
 * paused there.
 */
function routeAnimationSetState(paused, direction) {
    const anim = routeAnimation;
    if (anim === null) {
        return;
    }
    if (!paused && direction > 0 && anim.overviewDone) {
        routeAnimationRestart();
        return;
    }
    routeAnimationStopTimers(anim);
    anim.direction = direction;
    anim.paused = paused || (direction < 0 && routeAnimationAtStart(anim));
    if (!anim.paused) {
        if (direction > 0 && anim.done) {
            anim.endTimer = setTimeout(routeAnimationShowOverview, anim.cfg.endDelayS * 1000);
        } else {
            if (anim.done) {
                // Backwards out of the end (or the overview): follow the route again.
                anim.done = false;
                anim.overview = null;
                anim.overviewDone = false;
                anim.elapsed = 0;
            }
            anim.lastTime = null; // no jump by the time spent paused
            if (anim.started) {
                anim.frame = requestAnimationFrame(routeAnimationStep);
            }
        }
    }
    routeAnimationApplyTransport();
}

function routeAnimationStopTimers(anim) {
    if (anim.frame !== null) {
        cancelAnimationFrame(anim.frame);
        anim.frame = null;
    }
    clearTimeout(anim.endTimer);
    anim.endTimer = null;
}

/** After the end pause: the camera swings to the main map's view (straight from above), the animation stays open. */
function routeAnimationShowOverview() {
    const anim = routeAnimation;
    if (anim === null) {
        return;
    }
    anim.endTimer = null;
    const c = anim.map.getCenter();
    anim.overview = {
        from: { lat: c.lat(), lng: c.lng(), zoom: anim.map.getZoom(), tilt: anim.map.getTilt() || 0, heading: anim.map.getHeading() || 0 },
        to: { lat: map.getCenter().lat(), lng: map.getCenter().lng(), zoom: map.getZoom() },
        t: 0
    };
    anim.lastTime = null;
    anim.frame = requestAnimationFrame(routeAnimationOverviewStep);
}

function routeAnimationOverviewStep(now) {
    const anim = routeAnimation;
    if (anim === null || anim.overview === null) {
        return;
    }
    const dt = anim.lastTime === null ? 0 : Math.min(0.1, (now - anim.lastTime) / 1000);
    anim.lastTime = now;
    const o = anim.overview;
    o.t = Math.min(1, o.t + dt / ROUTE_ANIMATION_OVERVIEW_S);
    const e = o.t < 0.5 ? 2 * o.t * o.t : 1 - Math.pow(-2 * o.t + 2, 2) / 2;
    const turn = ((0 - o.from.heading + 540) % 360) - 180; // the short way round to north
    anim.map.moveCamera({
        center: { lat: o.from.lat + (o.to.lat - o.from.lat) * e, lng: o.from.lng + (o.to.lng - o.from.lng) * e },
        zoom: o.from.zoom + (o.to.zoom - o.from.zoom) * e,
        tilt: o.from.tilt * (1 - e),
        heading: (o.from.heading + turn * e + 360) % 360
    });
    if (o.t < 1) {
        anim.frame = requestAnimationFrame(routeAnimationOverviewStep);
        return;
    }
    anim.frame = null;
    anim.overviewDone = true;
    anim.paused = true; // played to the end; ▶ plays it again from the start
    routeAnimationApplyTransport();
}

/** Bearing from the first waypoint to the point the camera looks at. */
function routeAnimationStartHeading(anim) {
    return routeAnimationBearing(anim.legs[0].points[0], routeAnimationPointAt(anim.legs[0], anim.lookahead));
}

function routeAnimationAtStart(anim) {
    return anim.legIndex === 0 && anim.gap === null && anim.distance <= 0;
}

/** Marks the button of the current state (backwards / paused / forwards). */
function routeAnimationApplyTransport() {
    const anim = routeAnimation;
    const states = {
        routeAnimationBackward: !anim.paused && anim.direction < 0,
        routeAnimationPauseBtn: anim.paused,
        routeAnimationForward: !anim.paused && anim.direction > 0
    };
    for (const id in states) {
        const button = document.getElementById(id);
        button.classList.toggle('active', states[id]);
        button.setAttribute('aria-pressed', states[id] ? 'true' : 'false');
    }
}

/** The "Animation beenden" button (and Escape). */
function stopRouteAnimation() {
    finishRouteAnimation();
}

/**
 * @param {Array<object>} list routes (routes[] entries: points, color)
 */
function startRouteAnimation(list) {
    const container = document.getElementById('routeAnimation');
    // Still running, or the last one is still fading out.
    if (routeAnimation !== null || container.style.display === 'block') {
        return;
    }
    // The same points the map shows (route.js's createRoutes()).
    const legs = list
        .map(r => ({ route: r, points: (settings.smoothRoutes ? smoothRoutePoints(r.points) : r.points) || [] }))
        .filter(leg => leg.points.length >= 2)
        .map(leg => routeAnimationLeg(leg.route, leg.points));
    if (legs.length === 0) {
        showToast(t('animation.no_route'), 'error');
        return;
    }

    const cfg = routeAnimationSettings();
    let animMap;
    try {
        container.style.display = 'block';
        animMap = new google.maps.Map(document.getElementById('routeAnimationMap'), {
            mapId: window.YTAN_MAPS_ID || 'DEMO_MAP_ID',
            renderingType: google.maps.RenderingType.VECTOR,
            mapTypeId: map.getMapTypeId(),
            center: legs[0].points[0],
            zoom: cfg.zoom,
            tilt: 0,
            heading: 0,
            disableDefaultUI: true,
            gestureHandling: 'none',
            keyboardShortcuts: false,
            clickableIcons: false
        });
    } catch (err) {
        log('startRouteAnimation() failed', LOG_ERROR, err);
        container.style.display = 'none';
        showToast(t('animation.failed'), 'error');
        return;
    }
    panelOpened();

    // The whole route in its own colour, the animated line drawn over it.
    const overlays = [];
    for (const leg of legs) {
        overlays.push(new google.maps.Polyline({
            map: animMap, path: leg.points, geodesic: false, clickable: false,
            strokeColor: leg.route.color || '#BF409F', strokeOpacity: 0.8, strokeWeight: 3, zIndex: 1
        }));
        leg.line = new google.maps.Polyline({
            map: animMap, path: [leg.points[0]], geodesic: false, clickable: false,
            strokeColor: cfg.color, strokeOpacity: 1, strokeWeight: cfg.lineWidth, zIndex: 2
        });
        overlays.push(leg.line);
    }

    const lat = legs[0].points[0].lat;
    const metersPerPixel = 156543.03392 * Math.cos(lat * Math.PI / 180) / Math.pow(2, cfg.zoom);
    const total = legs.reduce((sum, leg) => sum + leg.length, 0);
    routeAnimation = {
        map: animMap,
        cfg: cfg,
        legs: legs,
        overlays: overlays,
        markers: [],
        baseSpeed: Math.max(cfg.speed * metersPerPixel, total / ROUTE_ANIMATION_MAX_S), // m/s at 1x
        speedIndex: ROUTE_ANIMATION_SPEED_DEFAULT_INDEX,
        speed: 0,          // m/s, baseSpeed times the chosen factor (routeAnimationApplySpeed())
        lookahead: ROUTE_ANIMATION_LOOKAHEAD_PX * metersPerPixel,
        legIndex: 0,
        distance: 0,       // metres along the current leg
        gap: null,         // {from, to, t, duration} while moving to the next route
        heading: routeAnimationBearing(legs[0].points[0], routeAnimationPointAt(legs[0], ROUTE_ANIMATION_LOOKAHEAD_PX * metersPerPixel)),
        elapsed: 0,
        lastTime: null,
        frame: null,
        endTimer: null,
        overview: null,    // {from, to, t} while the camera swings to the view from before
        overviewDone: false,
        paused: false,
        direction: 1,      // 1 forwards, -1 backwards
        started: false,    // first tiles there, frames may run
        done: false
    };
    routeAnimationApplySpeed();
    routeAnimationApplyTransport();
    animMap.moveCamera({ center: legs[0].points[0], zoom: cfg.zoom, tilt: 0, heading: routeAnimation.heading });
    document.addEventListener('keydown', routeAnimationKeydown);

    // Start once the first tiles are there (or after 4 s at the latest).
    const anim = routeAnimation;
    const begin = () => {
        if (anim.started || routeAnimation !== anim) {
            return;
        }
        anim.started = true;
        container.classList.add('visible');
        if (!anim.paused) {
            anim.frame = requestAnimationFrame(routeAnimationStep);
        }
    };
    google.maps.event.addListenerOnce(animMap, 'tilesloaded', begin);
    setTimeout(begin, 4000);
}

/** A route prepared for the animation: points, cumulative distances, length. */
function routeAnimationLeg(route, points) {
    const cumulative = [0];
    for (let k = 1; k < points.length; k++) {
        cumulative.push(cumulative[k - 1] + routeAnimationDistance(points[k - 1], points[k]));
    }
    return { route: route, points: points, cumulative: cumulative, length: cumulative[cumulative.length - 1], drawnIndex: 0, marker: null };
}

function routeAnimationStep(now) {
    const anim = routeAnimation;
    if (anim === null || anim.done || anim.paused) {
        return;
    }
    // Capped so a hidden tab or a slow frame doesn't jump ahead.
    const dt = anim.lastTime === null ? 0 : Math.min(0.1, (now - anim.lastTime) / 1000);
    anim.lastTime = now;
    anim.elapsed += dt;
    const back = anim.direction < 0;

    let center;
    let target;
    if (anim.gap !== null) {
        // Between two routes of a tour; legIndex is already the next route's.
        const gap = anim.gap;
        gap.t = Math.max(0, Math.min(1, gap.t + anim.direction * dt / gap.duration));
        const e = gap.t < 0.5 ? 2 * gap.t * gap.t : 1 - Math.pow(-2 * gap.t + 2, 2) / 2;
        center = routeAnimationLerp(gap.from, gap.to, e);
        target = gap.to;
        if (!back && gap.t >= 1) {
            anim.gap = null;
        } else if (back && gap.t <= 0) {
            anim.gap = null;
            anim.legIndex--;
            anim.distance = anim.legs[anim.legIndex].length;
        }
    } else {
        const leg = anim.legs[anim.legIndex];
        anim.distance = Math.max(0, Math.min(leg.length, anim.distance + anim.direction * anim.speed * dt));
        center = routeAnimationPointAt(leg, anim.distance);
        routeAnimationDrawTo(leg, anim.distance, center);
        // The camera always looks the way the route goes, also backwards (like
        // rewinding a film: the view stays, the route runs back under it).
        target = routeAnimationPointAt(leg, anim.distance + anim.lookahead);
        if (anim.distance + anim.lookahead > leg.length && anim.legIndex + 1 < anim.legs.length) {
            target = anim.legs[anim.legIndex + 1].points[0];
        }

        if (!back && anim.distance >= leg.length) {
            if (anim.legIndex + 1 < anim.legs.length) {
                anim.legIndex++;
                anim.distance = 0;
                anim.gap = routeAnimationGap(anim, leg.points[leg.points.length - 1], anim.legs[anim.legIndex].points[0], 0);
            } else {
                anim.done = true;
                anim.endTimer = setTimeout(routeAnimationShowOverview, anim.cfg.endDelayS * 1000);
            }
        } else if (back && anim.distance <= 0) {
            if (anim.legIndex > 0) {
                const previous = anim.legs[anim.legIndex - 1].points;
                anim.gap = routeAnimationGap(anim, previous[previous.length - 1], leg.points[0], 1);
            } else {
                // Back at the very start: nothing left to undo.
                anim.paused = true;
                routeAnimationApplyTransport();
            }
        }
    }
    routeAnimationSyncMarkers(anim);

    if (routeAnimationDistance(center, target) > 1) {
        const wanted = routeAnimationBearing(center, target);
        const delta = ((wanted - anim.heading + 540) % 360) - 180;
        anim.heading = (anim.heading + delta * Math.min(1, dt * ROUTE_ANIMATION_TURN_RATE) + 360) % 360;
    }
    const tilt = ROUTE_ANIMATION_TILT * Math.min(1, anim.elapsed / ROUTE_ANIMATION_TILT_IN_S);
    anim.map.moveCamera({ center: center, zoom: anim.cfg.zoom, tilt: tilt, heading: anim.heading });

    if (!anim.done && !anim.paused) {
        anim.frame = requestAnimationFrame(routeAnimationStep);
    }
}

/** The camera move between two routes of a tour, starting at t (0 = at `from`, 1 = at `to`). */
function routeAnimationGap(anim, from, to, t) {
    return { from: from, to: to, t: t, duration: Math.min(ROUTE_ANIMATION_GAP_MAX_S, Math.max(0.01, routeAnimationDistance(from, to) / anim.speed)) };
}

/**
 * Moves the animated line's head to `distance`: vertices passed going
 * forwards are appended once, vertices passed again going backwards removed.
 * The path is always the drawn vertices 0..drawnIndex plus the moving head.
 */
function routeAnimationDrawTo(leg, distance, head) {
    const path = leg.line.getPath();
    while (leg.drawnIndex + 1 < leg.points.length && leg.cumulative[leg.drawnIndex + 1] <= distance) {
        leg.drawnIndex++;
        // The moving head sits at the end: replace it by the vertex, then add a new head.
        path.setAt(path.getLength() - 1, new google.maps.LatLng(leg.points[leg.drawnIndex]));
        path.push(new google.maps.LatLng(leg.points[leg.drawnIndex]));
    }
    while (leg.drawnIndex > 0 && leg.cumulative[leg.drawnIndex] > distance) {
        // Drops the head; the vertex before it becomes the head (moved below).
        path.pop();
        leg.drawnIndex--;
    }
    if (path.getLength() === 1) {
        path.push(new google.maps.LatLng(head));
    } else {
        path.setAt(path.getLength() - 1, new google.maps.LatLng(head));
    }
}

/** A position marker on the last waypoint of every finished route - and only those (backwards takes them away again). */
function routeAnimationSyncMarkers(anim) {
    anim.legs.forEach((leg, i) => {
        const finished = i < anim.legIndex || (i === anim.legIndex && anim.distance >= leg.length);
        if (finished && !leg.marker) {
            const pin = new google.maps.marker.PinElement({ background: anim.cfg.color, borderColor: '#FFFFFF', glyphColor: '#FFFFFF' });
            leg.marker = new google.maps.marker.AdvancedMarkerElement({ map: anim.map, position: leg.points[leg.points.length - 1], content: pin });
            anim.markers.push(leg.marker);
        } else if (!finished && leg.marker) {
            leg.marker.map = null;
            anim.markers.splice(anim.markers.indexOf(leg.marker), 1);
            leg.marker = null;
        }
    });
}

/** Ends the animation (also early, by the stop button): fades out and removes its map. */
function finishRouteAnimation() {
    const anim = routeAnimation;
    if (anim === null) {
        return;
    }
    routeAnimation = null;
    anim.done = true;
    if (anim.frame !== null) {
        cancelAnimationFrame(anim.frame);
    }
    clearTimeout(anim.endTimer);
    document.removeEventListener('keydown', routeAnimationKeydown);

    const container = document.getElementById('routeAnimation');
    container.classList.remove('visible');
    setTimeout(() => {
        anim.overlays.forEach(o => o.setMap(null));
        anim.markers.forEach(m => { m.map = null; });
        container.style.display = 'none';
        // A fresh map per animation; the old one's DOM goes with it.
        document.getElementById('routeAnimationMap').replaceChildren();
        panelClosed();
    }, 400);
}

function routeAnimationKeydown(event) {
    if (event.key === 'Escape') {
        stopRouteAnimation();
    } else if (event.key === ' ') {
        event.preventDefault();
        routeAnimationTogglePause();
    } else if (event.key === 'Home') {
        routeAnimationRestart();
    } else if (event.key === 'ArrowLeft') {
        routeAnimationPlayBackward();
    } else if (event.key === 'ArrowRight') {
        routeAnimationPlayForward();
    } else if (event.key === '+') {
        routeAnimationFaster();
    } else if (event.key === '-') {
        routeAnimationSlower();
    }
}

/** The point `distance` metres along a leg (clamped to its ends). */
function routeAnimationPointAt(leg, distance) {
    const points = leg.points;
    if (distance <= 0) {
        return points[0];
    }
    if (distance >= leg.length) {
        return points[points.length - 1];
    }
    let lo = 0;
    let hi = leg.cumulative.length - 1;
    while (hi - lo > 1) {
        const mid = (lo + hi) >> 1;
        if (leg.cumulative[mid] <= distance) {
            lo = mid;
        } else {
            hi = mid;
        }
    }
    const span = leg.cumulative[hi] - leg.cumulative[lo];
    return routeAnimationLerp(points[lo], points[hi], span > 0 ? (distance - leg.cumulative[lo]) / span : 0);
}

function routeAnimationLerp(a, b, f) {
    return { lat: a.lat + (b.lat - a.lat) * f, lng: a.lng + (b.lng - a.lng) * f };
}

function routeAnimationDistance(a, b) {
    return google.maps.geometry.spherical.computeDistanceBetween(a, b);
}

/** Compass bearing from a to b, 0..360. */
function routeAnimationBearing(a, b) {
    return (google.maps.geometry.spherical.computeHeading(a, b) + 360) % 360;
}
