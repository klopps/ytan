/**
 * Live map updates (todo.md "Automatische Update bei Veränderungen"): POIs,
 * routes and areas that someone else - or the same user on another device -
 * creates, changes or deletes show up on an open map within seconds, without
 * a reload.
 *
 * Polls GET /map/changes?since=CURSOR (MapChangesController) while the page
 * is visible - every 15 s while the map is in use, less often the longer it
 * lies untouched (LIVE_SYNC_STEPS: battery), and right away when the page
 * becomes visible again, the connection comes back or the user touches it
 * after a pause. No WebSocket/SSE: the
 * shared production host has no long-running processes, and a long-lived PHP
 * request would hold a PHP-FPM worker per open map.
 *
 * Each changed row is applied on its own (marker/polyline/polygon rebuilt in
 * place, by id - never a reload of the whole collection, which rebuilds every
 * marker), unless the server says `reload` for a kind (an import changed
 * hundreds of rows at once). A row whose `changed_at` the map already has is
 * skipped - the server repeats the last 30 s on every poll (see the
 * controller). Something the user is busy with right now - a POI/route/area
 * being edited or moved, or a change still waiting in the offline queue - is
 * not touched; its server version is kept back and applied once that is over.
 */

// Poll interval by the time since the user last touched the map/page
// (pointer, key, wheel): every [idleMs, intervalMs] step that applies. Every
// poll wakes the phone's mobile radio for several seconds, so an untouched
// map polls rarely; the first touch after a long pause polls at once.
const LIVE_SYNC_STEPS = [
    [0, 15000],              // in use
    [2 * 60000, 60000],      // untouched for 2 min
    [10 * 60000, 180000],    // untouched for 10 min
];
const LIVE_SYNC_INTERVAL_MS = LIVE_SYNC_STEPS[0][1];
// A kind reloaded by `reload` is not reloaded again within this time; a further
// request in between is carried out once it has passed.
const LIVE_SYNC_RELOAD_GAP_MS = 60000;

const liveSync = {
    cursor: null,
    timer: null,
    busy: false,
    started: false,
    lastActivity: Date.now(),
    lastPoll: 0,         // Date.now() of the last poll's start
    deferred: new Map(), // 'pois:12' -> {kind, id, row|null}, kept back while the user works on it
    lastReload: {},      // kind -> Date.now() of the last full reload
    reloadTimers: {},    // kind -> pending timer of a postponed reload
};

/** Called once from initMap() (map-core.js), after the collections started loading. */
function initLiveSync() {
    if (liveSync.started) {
        return;
    }
    liveSync.started = true;
    liveSyncTick(); // fetches the first cursor
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            liveSync.lastActivity = Date.now(); // coming back to the page counts as use
            liveSyncTick();
        } else {
            clearTimeout(liveSync.timer);
        }
    });
    window.addEventListener('online', liveSyncTick);
    ['pointerdown', 'keydown', 'wheel'].forEach(function (type) {
        document.addEventListener(type, liveSyncActivity, { capture: true, passive: true });
    });
}

/** The poll interval for the current idle time (LIVE_SYNC_STEPS). Pure apart from the clock. */
function liveSyncInterval(idleMs) {
    let interval = LIVE_SYNC_STEPS[0][1];
    LIVE_SYNC_STEPS.forEach(function (step) {
        if (idleMs >= step[0]) {
            interval = step[1];
        }
    });
    return interval;
}

/**
 * The user touched the page. Back from a pause, the map may be minutes behind:
 * poll now when the last poll is older than the "in use" interval, otherwise
 * just bring the next poll forward to that interval.
 */
function liveSyncActivity() {
    const wasIdleInterval = liveSyncInterval(Date.now() - liveSync.lastActivity);
    liveSync.lastActivity = Date.now();
    if (wasIdleInterval === LIVE_SYNC_INTERVAL_MS || !liveSync.started) {
        return; // was in use already - nothing to change (cheap: runs on every tap)
    }
    if (Date.now() - liveSync.lastPoll >= LIVE_SYNC_INTERVAL_MS) {
        liveSyncTick();
    } else {
        liveSyncSchedule();
    }
}

function liveSyncSchedule() {
    clearTimeout(liveSync.timer);
    if (document.visibilityState === 'hidden') {
        return; // visibilitychange restarts it
    }
    const interval = liveSyncInterval(Date.now() - liveSync.lastActivity);
    const wait = Math.max(0, liveSync.lastPoll + interval - Date.now());
    liveSync.timer = setTimeout(liveSyncTick, wait);
}

function liveSyncTick() {
    if (liveSync.busy) {
        return;
    }
    clearTimeout(liveSync.timer);
    liveSync.busy = true;
    liveSync.lastPoll = Date.now();
    const since = liveSync.cursor;
    const path = '/map/changes' + (since !== null ? '?since=' + encodeURIComponent(since) : '');
    Ytan.get(path).then(function (answer) {
        const data = answer.data;
        if (since === null) {
            liveSync.cursor = data.cursor;
            return null;
        }
        return liveSyncPendingKeys().then(function (pending) {
            liveSyncApply(data, pending);
            liveSync.cursor = data.cursor;
        });
    }).catch(function (err) {
        log('liveSyncTick() failed', LOG_WARN, err); // offline etc. - the cursor stays, the next tick catches up
    }).finally(function () {
        liveSync.busy = false;
        liveSyncSchedule();
    });
}

/** 'pois:12'-style keys of everything waiting in the offline queue (offline-sync.js). */
function liveSyncPendingKeys() {
    const kinds = { poi: 'pois', route: 'routes', area: 'areas' };
    return getAllPendingChanges().then(function (entries) {
        return new Set(entries.map(function (e) { return kinds[e.entityType] + ':' + e.clientId; }));
    }).catch(function () {
        return new Set();
    });
}

function liveSyncApply(data, pending) {
    (data.reload || []).forEach(liveSyncReload);

    const offers = [];
    liveSync.deferred.forEach(function (entry) { offers.push(entry); });
    ['pois', 'routes', 'areas'].forEach(function (kind) {
        if ((data.reload || []).indexOf(kind) !== -1) {
            return;
        }
        (data[kind] || []).forEach(function (row) { offers.push({ kind: kind, id: row.id, row: row }); });
        ((data.removed || {})[kind] || []).forEach(function (id) { offers.push({ kind: kind, id: id, row: null }); });
    });
    if (offers.length === 0) {
        return;
    }

    let poisTouched = false;
    settingAllPois = true; // poi.js: setPoi() leaves the clustering to us, once at the end
    try {
        offers.forEach(function (offer) {
            const key = offer.kind + ':' + offer.id;
            if (pending.has(key) || liveSyncBusyWith(offer.kind, offer.id)) {
                liveSync.deferred.set(key, offer);
                return;
            }
            liveSync.deferred.delete(key);
            try {
                if (offer.kind === 'pois') {
                    poisTouched = liveSyncApplyPoi(offer.id, offer.row) || poisTouched;
                } else if (offer.kind === 'routes') {
                    liveSyncApplyRoute(offer.id, offer.row);
                } else {
                    liveSyncApplyArea(offer.id, offer.row);
                }
            } catch (err) {
                log('liveSyncApply(' + key + ') failed', LOG_ERROR, err);
            }
        });
    } finally {
        settingAllPois = false;
    }
    if (poisTouched) {
        updatePoiClustering();
    }
}

/** True while the user edits/moves exactly this item (its server version must wait). */
function liveSyncBusyWith(kind, id) {
    if (kind === 'pois') {
        return poiHiddenForEditId === id;
    }
    // measureTool.index is the index of the route OR area being drawn/edited -
    // which of the two isn't recorded, so both are held back at that index.
    if (typeof measureTool === 'undefined' || measureTool.index === null || measureTool.index === undefined) {
        return false;
    }
    const list = kind === 'routes' ? routes : areas;
    const item = list[measureTool.index];
    return !!item && item.id === id;
}

/** A full reload of one kind, at most once per LIVE_SYNC_RELOAD_GAP_MS (postponed, never dropped). */
function liveSyncReload(kind) {
    const wait = (liveSync.lastReload[kind] || 0) + LIVE_SYNC_RELOAD_GAP_MS - Date.now();
    if (wait > 0) {
        if (!liveSync.reloadTimers[kind]) {
            liveSync.reloadTimers[kind] = setTimeout(function () {
                delete liveSync.reloadTimers[kind];
                liveSyncReload(kind);
            }, wait);
        }
        return;
    }
    liveSync.lastReload[kind] = Date.now();
    const userId = (user.id !== null) ? user.id : Ytan.getStoredUserId();
    const loaders = {
        pois: [getPoisByUserId, getPublicPois],
        routes: [getRoutesByUserId, getPublicRoutes],
        areas: [getAreasByUserId, getPublicAreas],
    }[kind];
    if (userId !== null) {
        loaders[0](userId);
    } else {
        loaders[1]();
    }
}

// --- POIs ---------------------------------------------------------------

/** @returns {boolean} whether a marker was added/removed (the clustering needs a refresh) */
function liveSyncApplyPoi(id, row) {
    const index = pois.findIndex(function (p) { return p && p.id === id; });
    if (row === null) {
        if (index === -1) {
            return false;
        }
        liveSyncDropPoiOverlays(index);
        delete pois[index]; // a hole, not splice(): the markers' listeners hold their index
        return true;
    }
    if (index !== -1 && pois[index].changed_at === row.changed_at) {
        return false;
    }
    if (index === -1) {
        setPoi(pois.push(row) - 1);
        return true;
    }
    liveSyncDropPoiOverlays(index);
    pois[index] = row;
    setPoi(index);
    return true;
}

/** Takes POI pois[index]'s marker, WSI icon, portage line, sector light and InfoWindow off the map. */
function liveSyncDropPoiOverlays(index) {
    const id = pois[index].id;
    closePoiInfoWindow(index);
    hideSectorLight(index);
    const marker = markers[id];
    if (marker) {
        // Not removeMarkerById(): its poiInfoWindows.splice(id, 1) would shift
        // the InfoWindows of every later POI (that array is indexed by position).
        marker.setMap(null);
        if (marker.real) {
            detachLongPressCandidate(marker.real);
        }
        delete markers[id];
    }
    deleteWsiMarkerById(id);
    for (let i = portagePaths.length - 1; i >= 0; i--) {
        const path = portagePaths[i];
        if (path && (path.get('id') === id || path.id === id)) {
            path.setMap(null);
            portagePaths.splice(i, 1);
        }
    }
}

// --- Routes -------------------------------------------------------------

function liveSyncApplyRoute(id, row) {
    const index = routes.findIndex(function (r) { return r && r.id === id; });
    if (row === null) {
        if (index !== -1) {
            hideRouteLabels(index);
            removeRouteOverlayAt(index);
            delete routes[index];
        }
        return;
    }
    if (index !== -1 && routes[index].changed_at === row.changed_at) {
        return;
    }
    row.points = JSON.parse(row.points);
    row.labels = [];
    if (index === -1) {
        // Tour Mode shows the tour's routes only - membership changes don't come through here.
        if (activeTourModeId === null) {
            createRoute(routes.push(row) - 1);
        }
        return;
    }
    const labelsShown = Array.isArray(routes[index].labels) && routes[index].labels.length > 0;
    hideRouteLabels(index);
    removeRouteOverlayAt(index);
    routes[index] = row;
    createRoute(index);
    if (labelsShown) {
        showRouteLabels(index);
    }
}

// --- Areas --------------------------------------------------------------

function liveSyncApplyArea(id, row) {
    const index = areas.findIndex(function (a) { return a && a.id === id; });
    if (row === null) {
        if (index !== -1) {
            removeAreaOverlayAt(index);
            delete areas[index];
        }
        return;
    }
    if (index !== -1 && areas[index].changed_at === row.changed_at) {
        return;
    }
    row.points = JSON.parse(row.points);
    row.opacity = parseFloat(row.opacity);
    row.zindex = parseInt(row.zindex);
    if (index === -1) {
        createArea(areas.push(row) - 1);
        return;
    }
    removeAreaOverlayAt(index);
    areas[index] = row;
    createArea(index);
}
