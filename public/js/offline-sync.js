/**
 * Offline create/edit/delete for POIs, Routes and Areas - Phase 2 of
 * todo.md's "Offline-Funktionalität für die Android-App" (Phase 1, the
 * read-only cache, is public/js/offline-cache.js). Tours are deliberately
 * NOT covered here - their CRUD shape (chained photo/route-membership
 * requests, whole-list-refetch on delete instead of a local array update)
 * is different enough that it was scoped out; see todo.md.
 *
 * Architecture: every save/delete writes to a local IndexedDB queue FIRST -
 * always, not just when a network request actually fails - then
 * immediately attempts a background sync. This is the same pattern
 * track-recorder.js already uses for GPS recordings, generalized from one
 * entity type/one operation (create-only) to three entity types and all of
 * create/update/delete. The record appears/updates/disappears on the map
 * immediately either way (optimistic UI) - online, the queued sync usually
 * completes within a moment; offline, it just waits until connectivity
 * returns. This is a deliberate behavior change even for an always-online
 * user (a save no longer waits for server confirmation before the map
 * reflects it), agreed with the user as the whole point of consistent
 * offline support rather than two different code paths.
 *
 * Not gated behind CapacitorBridge.isAvailable() - works the same in the
 * native Android shell and a plain browser tab, same as offline-cache.js.
 *
 * Shares offline-cache.js's IndexedDB connection (openOfflineCacheDb(),
 * same 'ytan-offline-cache' database) rather than opening a second one -
 * see that file's own doc comment. Only reads/writes the OFFLINE_SYNC_STORE
 * object store defined there.
 */

// --- Pending-changes queue (IndexedDB CRUD) ---------------------------

function putPendingChange(entry) {
    return openOfflineCacheDb().then(function (database) {
        return new Promise(function (resolve, reject) {
            const tx = database.transaction(OFFLINE_SYNC_STORE, 'readwrite');
            tx.objectStore(OFFLINE_SYNC_STORE).put(entry);
            tx.oncomplete = resolve;
            tx.onerror = function () { reject(tx.error); };
        });
    });
}

function getAllPendingChanges() {
    return openOfflineCacheDb().then(function (database) {
        return new Promise(function (resolve, reject) {
            const tx = database.transaction(OFFLINE_SYNC_STORE, 'readonly');
            const request = tx.objectStore(OFFLINE_SYNC_STORE).getAll();
            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error); };
        });
    });
}

function deletePendingChange(entityType, clientId) {
    return openOfflineCacheDb().then(function (database) {
        return new Promise(function (resolve, reject) {
            const tx = database.transaction(OFFLINE_SYNC_STORE, 'readwrite');
            tx.objectStore(OFFLINE_SYNC_STORE).delete(entityType + ':' + clientId);
            tx.oncomplete = resolve;
            tx.onerror = function () { reject(tx.error); };
        });
    });
}

/**
 * A negative, timestamp-based placeholder id for a brand-new record that
 * hasn't been synced yet - guaranteed to never collide with a real
 * (always positive, auto-increment) server id, so it can be used
 * everywhere a real id would be (the pois[]/routes[]/areas[] array entry,
 * the map marker/polyline's own id, ...) until the sync swaps it for the
 * real one.
 */
function generateLocalId() {
    return -Date.now();
}

/**
 * Writes (or merges into) this record's single queue slot - keyed by
 * entityType+clientId, so a second save/delete of the SAME record before
 * the first one has synced overwrites the earlier intent instead of
 * queueing a second, redundant operation:
 * - editing a not-yet-synced record (clientId < 0) just updates its still-
 *   queued 'create' payload - there's nothing to PUT against yet.
 * - deleting a not-yet-synced record removes the queued 'create' entirely
 *   - it was never sent, so there's nothing to DELETE on the server either.
 * - anything else (edit-then-edit, edit-then-delete of an EXISTING record)
 *   simply overwrites the same slot with the latest intent; an 'update'
 *   overwritten by a 'delete' is exactly right, since the update is moot
 *   once the record is about to be removed anyway.
 */
function enqueueChange(entityType, operation, clientId, payload) {
    if (operation === 'delete' && clientId < 0) {
        return deletePendingChange(entityType, clientId);
    }
    const effectiveOperation = (operation === 'update' && clientId < 0) ? 'create' : operation;
    return putPendingChange({
        id: entityType + ':' + clientId,
        entityType: entityType,
        operation: effectiveOperation,
        clientId: clientId,
        payload: payload,
        queuedAt: Date.now(),
    });
}

// --- Sync engine --------------------------------------------------------

// entityType -> API base path + the function that reconciles a successful
// 'create' sync's real server id back into the local array/map (each lives
// in poi.js/route.js/area.js, where the array/overlay teardown-and-rebuild
// functions this needs are already private to that file). Wrapped in
// closures rather than referenced directly - this file loads before poi.js/
// route.js/area.js (see templates/app.php's script order), so
// reconcilePoiLocalId etc. don't exist yet at the point this object
// literal itself is evaluated; wrapping defers the actual lookup to call
// time, by which point every script has loaded, same forward-reference
// pattern already used elsewhere in this app (e.g. map-core.js's
// editUnit() calling route.js's renewVisibleRouteLabels()).
const OFFLINE_SYNC_ENTITY_CONFIG = {
    poi: { apiPath: '/pois', icon: 'place', reconcileCreate: function (clientId, realId, data) { reconcilePoiLocalId(clientId, realId, data); } },
    route: { apiPath: '/routes', icon: 'timeline', reconcileCreate: function (clientId, realId, data) { reconcileRouteLocalId(clientId, realId, data); } },
    area: { apiPath: '/areas', icon: 'crop_square', reconcileCreate: function (clientId, realId, data) { reconcileAreaLocalId(clientId, realId, data); } },
};

/**
 * Sends one queued entry exactly like the old synchronous savePoi()/
 * saveRoute()/saveArea()/remove*() would have, then either reconciles the
 * local placeholder id (create) or just drops the now-fulfilled queue
 * entry (update/delete - the id was already real).
 */
function uploadPendingChange(entry) {
    const config = OFFLINE_SYNC_ENTITY_CONFIG[entry.entityType];
    let request;
    if (entry.operation === 'create') {
        request = Ytan.post(config.apiPath, entry.payload);
    } else if (entry.operation === 'update') {
        request = Ytan.put(config.apiPath + '/' + entry.clientId, entry.payload);
    } else {
        request = Ytan.del(config.apiPath + '/' + entry.clientId);
    }

    return request.then(function (answer) {
        if (entry.operation === 'create') {
            config.reconcileCreate(entry.clientId, answer.data.id, answer.data);
        }
        return deletePendingChange(entry.entityType, entry.clientId);
    });
}

/**
 * Uploads every currently queued change, one at a time (not in parallel -
 * avoids hammering a connection that may have only just come back).
 * Mirrors track-recorder.js's syncPendingTracks(): silent on failure
 * unless opts.manual (a background retry failing just means still no
 * signal/still a real error, not worth nagging about every time); called
 * automatically right after enqueueChange(), on the 'online' event, and
 * when the pending-changes screen opens, plus manually from its "Alle
 * synchronisieren" button.
 *
 * A failed entry (network error, or e.g. the captcha-gated "route belongs
 * to a tour" delete - see route.js's removeRoute(), which never queues
 * that specific case in the first place, so this mostly covers a route
 * that gained tour membership AFTER being queued) simply stays in the
 * queue for the next attempt; there's no special-cased recovery UI for it
 * in this first version, matching the scope agreed for this step.
 */
function syncPendingChanges(opts) {
    opts = opts || {};
    if (typeof user === 'undefined' || user.id === null) {
        return Promise.resolve();
    }
    return getAllPendingChanges().then(function (entries) {
        if (entries.length === 0) {
            return;
        }
        let succeeded = 0;
        let failed = 0;
        let chain = Promise.resolve();
        entries.forEach(function (entry) {
            chain = chain.then(function () {
                return uploadPendingChange(entry).then(function () {
                    succeeded++;
                }).catch(function (err) {
                    log('syncPendingChanges() failed for ' + entry.id, LOG_WARN, err);
                    failed++;
                });
            });
        });
        return chain.then(function () {
            if (succeeded > 0) {
                showToast(t('offline_sync.sync_success', { count: succeeded }), 'success');
            }
            if (failed > 0 && opts.manual) {
                showToast(t('offline_sync.sync_failed', { count: failed }), 'error');
            }
            refreshPendingChanges();
        });
    });
}

// --- UI: drawer row + "Ausstehende Änderungen" screen -------------------

// In-memory mirror of the queue, kept fresh by refreshPendingChanges() -
// renderPendingChangesScreen() builds synchronously from this rather than
// reading IndexedDB on every render, same reasoning as track-recorder.js's
// pendingTracksCache.
let pendingChangesCache = [];

function refreshPendingChanges() {
    return getAllPendingChanges().then(function (entries) {
        entries.sort(function (a, b) { return b.queuedAt - a.queuedAt; }); // newest first
        pendingChangesCache = entries;
        updatePendingChangesRowSub();
        renderPendingChangesScreen();
    });
}

function updatePendingChangesRowSub() {
    const sub = document.getElementById('pendingChangesRowSub');
    if (!sub) {
        return;
    }
    sub.textContent = pendingChangesCache.length > 0 ? '(' + pendingChangesCache.length + ')' : '';
}

function renderPendingChangesScreen() {
    const body = document.getElementById('pendingChangesScreenBody');
    if (!body) {
        return;
    }
    if (pendingChangesCache.length === 0) {
        body.innerHTML = '<p class="track-recorder-explanation">' + t('offline_sync.empty') + '</p>';
        return;
    }
    const rows = pendingChangesCache.map(function (entry) {
        const config = OFFLINE_SYNC_ENTITY_CONFIG[entry.entityType];
        const name = (entry.payload && entry.payload.name) ? entry.payload.name : t('offline_sync.deleted_item_name');
        return '' +
            '<div class="track-recorder-pending-row">' +
                '<i class="material-icons-round">' + config.icon + '</i>' +
                '<div class="track-recorder-pending-info">' +
                    '<span class="track-recorder-pending-name">' + escapeHTML(name) + '</span>' +
                    '<span class="track-recorder-pending-meta">' + t('offline_sync.operation_' + entry.operation) + '</span>' +
                '</div>' +
                '<i class="material-icons-round track-recorder-pending-upload" onclick="uploadPendingChangeClicked(\'' + entry.id + '\');" title="' + t('offline_sync.upload_button') + '">cloud_upload</i>' +
                '<i class="material-icons-round track-recorder-pending-discard" onclick="discardPendingChangeClicked(\'' + entry.id + '\');" title="' + t('common.delete') + '">delete</i>' +
            '</div>';
    }).join('');
    const bulkButton = pendingChangesCache.length > 1
        ? '<button type="button" class="nav-btn-secondary" onclick="syncPendingChanges({manual:true});">' + t('offline_sync.sync_all_button') + '</button>'
        : '';
    body.innerHTML = '<div class="track-recorder-pending-list">' + rows + '</div>' + bulkButton;
}

function uploadPendingChangeClicked(id) {
    const entry = pendingChangesCache.find(function (e) { return e.id === id; });
    if (!entry) {
        return;
    }
    uploadPendingChange(entry).then(function () {
        showToast(t('offline_sync.sync_success', { count: 1 }), 'success');
        refreshPendingChanges();
    }).catch(function (err) {
        log('uploadPendingChangeClicked() failed', LOG_ERROR, err);
        showToast(t('offline_sync.sync_failed', { count: 1 }), 'error');
    });
}

/**
 * Discarding a queued 'create' only ever removes the QUEUE entry, not the
 * already-visible optimistic record itself - a known, accepted rough edge
 * for this first version (the phantom record stays on the map until the
 * next full reload, at which point it's simply gone since it was never on
 * the server and never entered Phase 1's read cache either - no data was
 * ever at risk, just a stale marker until reload). Discarding a queued
 * 'update'/'delete' of an already-real record has the same limitation in
 * the other direction (the optimistic edit/removal isn't undone either).
 */
function discardPendingChangeClicked(id) {
    const entry = pendingChangesCache.find(function (e) { return e.id === id; });
    if (!entry) {
        return;
    }
    const name = (entry.payload && entry.payload.name) || '';
    showConfirmDialog(t('offline_sync.confirm_discard', { name: name }), { type: 'danger', confirmLabel: t('common.delete') }).then(function (confirmed) {
        if (!confirmed) {
            return;
        }
        deletePendingChange(entry.entityType, entry.clientId).then(refreshPendingChanges);
    });
}

// Called both from the drawer row (drawer already open) and could be
// called with the drawer closed too - openMenu() is idempotent either way,
// same reasoning as track-recorder.js's openTrackRecorderScreen().
function openPendingChangesScreen() {
    openMenu();
    navMenuGoTo('pending-changes');
    renderPendingChangesScreen();
    syncPendingChanges({ manual: false });
}

function initOfflineSync() {
    refreshPendingChanges();
    // Also attempt a sync right at startup, not just on the 'online' event -
    // a page load while already online (e.g. the app was closed while
    // offline with changes still queued, then reopened later with a
    // connection already present) never fires 'online' itself, so without
    // this a queued change could otherwise sit untouched until the next
    // save or a manual visit to the pending-changes screen.
    syncPendingChanges({ manual: false });
    window.addEventListener('online', function () {
        syncPendingChanges({ manual: false });
    });
}
