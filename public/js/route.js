/**
 * Route creation, editing, rendering and distance labels.
 */

// Set by track-recorder.js right before it hands a finished GPS recording
// into this file's own showRouteEditWindow()/saveRoute() flow - see
// saveRoute() below. Always null for a normal, manually-drawn route.
let pendingRouteRecordingMeta = null;

// True for the duration of an in-flight POST/PUT from saveRoute() - checked
// by validateRouteEditForm() so editing the name/description field while a
// save is still waiting on the network can't re-enable the Save button and
// let the user fire a second, duplicate request before the first one has
// even failed or succeeded.
let routeSaveInProgress = false;

// Keyed by a new route's local placeholder id (offline-sync.js's
// generateLocalId(), negative) - saveRoute() records {tourId, tourName}
// here when Tour Mode is active at the moment a brand-new route is created,
// since the route doesn't have a real server id yet to attach to a tour
// with (see addSavedRouteToMap()'s id > 0 guard). reconcileRouteLocalId()
// consumes the entry once the real id comes back from sync and performs the
// actual attach then, using the tour captured HERE rather than whatever
// Tour Mode happens to be active by the time sync completes.
let pendingNewRouteTourAttachments = {};

/**
 * "3:24 h" / "48 min" - used by showRouteInfoWindow() for a GPS-recorded
 * route's recording_duration_seconds.
 */
function formatRouteRecordingDuration(totalSeconds) {
    var hours = Math.floor(totalSeconds / 3600);
    var minutes = Math.round((totalSeconds % 3600) / 60);
    if (hours > 0) {
        return hours + ':' + (minutes < 10 ? '0' : '') + minutes + ' h';
    }
    return minutes + ' min';
}

function editRouteBtnClick(elementId) {
    var element = document.getElementById(elementId);
    var index = measureTool.index;

    if (element.classList.contains('disabled')) {
        return;
    }

    if (!element.classList.contains('active')) {
        element.classList.add('active');
        disablePoiButton();
        disableAreaButton();
        editMode(true);
        measureTool.start();
        hideRoute(index);
        showSecondToolbar(elementId);
    }
}

function showRouteEditWindow(i, latLng) {
    hideRouteLabels(i);

    log('showRouteEditWindow(' + i + ')', LOG_INFO);

    hideSecondToolbar();

    if (measureTool.segments.length < 1) {
        document.getElementById('routeButton').classList.remove('active');
        cancelEditRoute(i);
        return;
    }

    if (user.id === null) {
        cancelEditRoute(i);
        return;
    }

    var lastPoint = measureTool.points.slice(-1)[0];
    var route;

    if ((i !== null) && (typeof i !== 'undefined')) {
        route = routes[i];
    } else {
        route = {
            name: '',
            description: '',
            public: false,
            color: '#BF409F'
        }
    }

    var content =
        '<div class="infoWindowElement">' +
            '<h3>' + t('route.edit.heading') + '</h3>' +
            '<div class="leftCol">' +
                '<label for="editRouteName">' + t('route.edit.name_label') + '</label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editRouteName" type="text" oninput="validateRouteEditForm();" placeholder="' + t('route.edit.name_placeholder') + '" title="' + t('common.min_3_chars_title') + '" value="' + route.name + '">' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<label for="editRouteDescription">' + t('route.edit.description_label') + '</label><br>' +
            '<textarea id="editRouteDescription" rows="5" oninput="validateRouteEditForm();"placeholder="' + t('route.edit.description_placeholder') + '">' + route.description + '</textarea>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editRouteColor">' + t('route.edit.color_label') + '</label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<div id="editRouteColorWrapper">' +
                    '<input type="color" id="editRouteColor" name="editRouteColor" value="' + route.color + '">' +
                '</div>' +
            '</div>' +
        '</div>';

    // Photos: only for an already-existing route (needs an id first, same
    // rule tour-admin.js's photo grid follows for tours) - see poi.js's
    // initPoiEditWindow() for the identical pattern and photo-upload.js's
    // doc comment for why the grid starts empty and repaints shortly after.
    // route.id can be a negative local placeholder for a route created
    // offline and not yet synced (offline-sync.js) - it has no server-side
    // photos to fetch yet, so skip the endpoint call rather than 404ing.
    if ((i !== null) && (typeof i !== 'undefined') && route.id > 0) {
        initPhotoUpload('routes', route.id, ROUTE_PHOTO_MAX_COUNT);
        content +=
            '<div class="infoWindowElement">' +
                '<label>' + t('route.edit.photos_label') + '</label>' +
                '<div id="' + PHOTO_UPLOAD_CONTAINER_ID + '">' + photoUploadGridHtml() + '</div>' +
            '</div>';
    } else {
        // No photo section shown for a brand-new route (needs an id first) -
        // reset rather than leave a previous route's still-running
        // compression able to block this unrelated create form's Save.
        resetPhotoUpload();
    }

    if (((i !== null) && (typeof i !== 'undefined')) && ((routes[i].user_id == user.id) || (user.is_admin === true))) {
        content +=
        '<div class="infoWindowElement">' +
            '<input type="checkbox" id="editRouteInvert" name="editRouteInvert">' +
            '<label for="editRouteInvert"><span></span>' + t('route.edit.invert_label') + '</label>' +
        '</div>';
    }

    if (user.is_admin === true) {
        content +=
        '<div class="infoWindowElement">' +
            '<input type="checkbox" id="editRouteStatus" name="editRouteStatus"';
        if (route.public == 1) content += ' checked';
        content += '>' +
            '<label for="editRouteStatus"><span></span>' + t('common.public_label') + '</label>' +
        '</div>';
    }

    content +=
        '<div class="infoWindowElement">' +
            '<button id="editRouteSaveBtn" class="button" onClick="saveRoute(' + i + ')" disabled>' + t('common.save') + '</button>&nbsp;';

    if (((i !== null) && (typeof i !== 'undefined')) && (routes[i].user_id == user.id)) {
        content = content +
            '<button id="editRouteRemoveBtn" class="button" onClick="removeRoute(' + i + ')">' + t('common.remove') + '</button>&nbsp;';
    }

    content += '<button class="button" onClick="cancelEditRoute(' + i + ')">' + t('common.cancel') + '</button>' +
        '</div>';

    routeEditWindow.setContent(content);
    if (latLng === null) {
        routeEditWindow.setPosition(lastPoint);
    } else {
        routeEditWindow.setPosition(latLng);
    }

    routeEditWindow.open(map);

    routeEditWindow.addListener('domready', function() {
        validateRouteEditForm();

        var color_picker = document.getElementById("editRouteColor");
        var color_picker_wrapper = document.getElementById("editRouteColorWrapper");
        color_picker.onchange = function() {
            color_picker_wrapper.style.backgroundColor = color_picker.value;
        }
        color_picker_wrapper.style.backgroundColor = color_picker.value;
    });

    google.maps.event.addListener(routeEditWindow, 'closeclick', function(i, event) {
        cancelEditRoute(i);
    }.bind(routeEditWindow, i));
}

function validateRouteEditForm() {
    var errors = 0;

    if (document.getElementById('editRouteName').value.length < 3) {
        errors = errors + 1;
    }

    if (errors & 1) {
        document.getElementById('editRouteName').classList.add('inputError');
    } else {
        document.getElementById('editRouteName').classList.remove('inputError');
    }

    if (errors == 0) {
        document.getElementById('editRouteSaveBtn').disabled = routeSaveInProgress;
        return true;
    } else {
        document.getElementById('editRouteSaveBtn').disabled = true;
        return false;
    }
}

function cancelEditRoute(i) {
    closeRouteEditWindow();

    measureTool.index = null;
    measureTool.end();
    document.getElementById('routeButton').classList.remove('active');
    // Not just via closeRouteEditWindow() - that only re-enables the other
    // buttons if the naming/save window was actually open, but cancelling
    // with too few points (or while logged out) never gets that far.
    enableAreaButton();
    enablePoiButton();
    enableRouteButton();
    editMode(false);
    if ((i !== null) && (typeof i !== 'undefined')) {
        showRoute(i);
    }
}

function closeRouteEditWindow() {
    if (routeEditWindow.isOpen) {
        enableAreaButton();
        enablePoiButton();
        enableRouteButton();
        editMode(false);
        routeEditWindow.close();
    }
}

/**
 * Inserts a brand-new (never-before-saved) route into routes[]/the map -
 * shared by three callers: saveRoute()'s own local-first path (routeData.id
 * is still a negative offline-sync.js placeholder there, not a real id
 * yet), track-recorder.js's uploadPendingTrack(), and restoreRouteFromServer()
 * (the latter two already have the real server-assigned id at this point,
 * reached via a background sync rather than a live saveRoute() call).
 *
 * The `routeData.id > 0` guard below is what makes this safe to share: only
 * a real id can be attached to a tour server-side (POST /tours/{id}/routes
 * 400s on anything else), so saveRoute()'s not-yet-synced placeholder-id
 * case is deliberately skipped here rather than firing early with a
 * doomed-to-fail id - reconcileRouteLocalId() performs the attach for that
 * case instead, once sync actually hands back the real id (see
 * pendingNewRouteTourAttachments above).
 *
 * @param {object} routeData Full route payload, points already a plain
 *   array (not the JSON string saveRoute()/uploadPendingTrack() POST).
 * @returns {number} the new index in routes[]
 */
function addSavedRouteToMap(routeData) {
    var index = routes.push(routeData) - 1;
    log('new index: ' + index, LOG_INFO);

    if (activeTourModeId !== null && routeData.id > 0) {
        addNewRouteToActiveTour(routeData.id);
    }

    createRoute(index);

    return index;
}

/**
 * Speichert eine Route in der Datenbank
 */
function saveRoute(i) {
    if (!(validateRouteEditForm() == true)) {
        return false;
    }

    if (user.id === null) {
        return false;
    }
    // A photo still being compressed (see stagePhotoUpload()) isn't staged
    // for upload yet - saving now would end the edit without ever
    // uploading it.
    if (photoUploadIsProcessing()) {
        showToast(t('photo_upload.still_processing'), 'warning');
        return false;
    }

    var isNew = (i === null) || (typeof i === 'undefined');
    var realId = isNew ? null : routes[i].id;
    var publicState = isNew ? 0 : routes[i].public;
    // Captured before routes[i] gets overwritten below - see poi.js's
    // savePoi() for why (todo.md's "Offline-Funktionalität" Phase 3).
    var previousUpdatedAt = isNew ? null : routes[i].updated_at;

    hideRoute(i); // no-op if routes[i] is undefined (a brand-new route) - guarded inside hideRoute() itself

    if (document.getElementById('editRouteStatus') !== null) {
        publicState = document.getElementById('editRouteStatus').checked ? 1 : 0;
    }

    var points = measureTool.points;
    if (document.getElementById('editRouteInvert') !== null) {
        if (document.getElementById('editRouteInvert').checked) {
            points = measureTool.points.reverse();
        }
    }

    var routeData = {
        id: realId,
        user_id: user.id,
        name: escapeHTML(document.getElementById('editRouteName').value),
        description: escapeHTML(document.getElementById('editRouteDescription').value),
        public: publicState,
        length: measureTool.length,
        points: JSON.stringify(points),
        color: document.getElementById('editRouteColor').value,
        updated_at: previousUpdatedAt
    }

    // Set by track-recorder.js immediately before showRouteEditWindow(),
    // only for a just-finished GPS recording. Unaffected by Phase 2 of
    // todo.md's "Offline-Funktionalität" below - a recorded route never
    // touches the network here at all, still handed straight to track-
    // recorder.js's OWN, separate local "save now, upload later" queue
    // (saveRecordedTrackLocally(), syncs via syncPendingTracks()), not the
    // generalized pendingChanges queue offline-sync.js adds for manually-
    // drawn routes below. A multi-hour kayak day tour is very likely
    // recorded with no signal at all for its whole duration - waiting on
    // (or even attempting) a network request before the user can start
    // recording the next leg would defeat the point of a "record several
    // legs, upload them all later" workflow.
    if (pendingRouteRecordingMeta !== null) {
        routeData.recorded_at = pendingRouteRecordingMeta.recorded_at;
        routeData.recording_duration_seconds = pendingRouteRecordingMeta.recording_duration_seconds;
        pendingRouteRecordingMeta = null;

        measureTool.index = null;
        measureTool.end();
        closeRouteEditWindow();
        document.getElementById('routeButton').classList.remove('active');
        saveRecordedTrackLocally(routeData);
        return true;
    }

    log('saveRoute(' + i + ')', LOG_INFO, routeData);

    // Photo uploads/removals staged during this edit aren't offline-
    // queueable (Phase 2 of todo.md's "Offline-Funktionalität" explicitly
    // scoped photos out) - a save involving them keeps the old, online-
    // required, wait-for-the-real-response behavior instead of the
    // local-first path below. Only possible on an edit (isNew=true never
    // had photo staging to begin with - see photo-upload.js).
    var hasPhotoChanges = !isNew && (photoUploadPendingUploads.length > 0 || photoUploadPendingRemovals.length > 0);
    if (hasPhotoChanges) {
        saveRouteOnlineWithPhotos(i, realId, routeData);
        return true;
    }

    // Local-first: always write to the offline queue and update the map
    // immediately, online or not - see offline-sync.js's own doc comment
    // for why. clientId is the array/map's own id (negative placeholder
    // for a brand-new route until it syncs); the network payload queued
    // below still sends null for a create, exactly like the POST always did.
    var clientId = isNew ? generateLocalId() : realId;
    var networkPayload = Object.assign({}, routeData, {
        id: isNew ? null : clientId,
        expected_updated_at: previousUpdatedAt
    });
    var localRouteData = Object.assign({}, routeData, { id: clientId, points: points });

    // Captured now (not read again once sync actually completes) so a Tour
    // Mode change/exit in between doesn't silently redirect - or drop - the
    // attach; see reconcileRouteLocalId() and pendingNewRouteTourAttachments'
    // own doc comment above.
    if (isNew && activeTourModeId !== null) {
        pendingNewRouteTourAttachments[clientId] = { tourId: activeTourModeId, tourName: activeTourModeName };
    }

    var index;
    if (isNew) {
        index = addSavedRouteToMap(localRouteData);
    } else {
        index = i;
        routes[index] = localRouteData;
        createRoute(index);
    }

    if (settings.detailroutes === false) {
        document.getElementById('detailroutes').checked = true;
        settings.detailroutes = true;
        saveSettings();
        showRoutes();
    }

    enqueueChange('route', isNew ? 'create' : 'update', clientId, networkPayload).then(function () {
        syncPendingChanges({ manual: false });
    });

    measureTool.index = null;
    measureTool.end();
    closeRouteEditWindow();
    document.getElementById('routeButton').classList.remove('active');

    return true;
}

/**
 * The old, pre-Phase-2 synchronous save path - kept for the one case that
 * still needs it: an edit with staged photo uploads/removals, which can't
 * be deferred to the offline queue (see the hasPhotoChanges branch in
 * saveRoute() above). Identical to saveRoute()'s own pre-Phase-2 body,
 * including the network-failure-resilience behavior added earlier (window/
 * measureTool stay untouched on failure so Save can just be clicked again).
 */
function saveRouteOnlineWithPhotos(i, id, routeData) {
    routeSaveInProgress = true;
    var saveBtn = document.getElementById('editRouteSaveBtn');
    if (saveBtn !== null) {
        saveBtn.disabled = true;
    }

    var request = (id === null)
        ? Ytan.post('/routes', routeData)
        : Ytan.put('/routes/' + id, routeData);

    request.then(answer => {
        log('saveRouteOnlineWithPhotos() success', LOG_INFO, answer);
        routeSaveInProgress = false;

        if (id !== null) {
            applyPendingPhotoUploadChanges('routes', id);
        }

        if (routeEditWindow.isOpen) {
            measureTool.index = null;
            measureTool.end();
            closeRouteEditWindow();
            document.getElementById('routeButton').classList.remove('active');
        }

        routeData.points = JSON.parse(routeData.points);
        var index = i;

        if (index == null) {
            routeData.id = answer.data.id;
            index = addSavedRouteToMap(routeData);
        } else {
            routes[index] = routeData;
            createRoute(index);
        }

        if (settings.detailroutes === false) {
            document.getElementById('detailroutes').checked = true;
            settings.detailroutes = true;
            saveSettings();
            showRoutes();
        }
    }).catch(err => {
        log('saveRouteOnlineWithPhotos() failed', LOG_ERROR, err);
        showToast(t('route.save_failed', { error: err.message }), 'error');
        routeSaveInProgress = false;

        var btn = document.getElementById('editRouteSaveBtn');
        if (btn !== null) {
            btn.disabled = false;
        }
    });
}

/**
 * Tears down the polyline overlay(s) at routePaths[i] - shared by
 * removeRoute() and reconcileRouteLocalId(), both of which need to remove
 * an existing overlay before either deleting the route for good or
 * rebuilding it under its now-real server id.
 */
function removeRouteOverlayAt(i) {
    if (typeof routePaths[i] === 'undefined') {
        return;
    }
    google.maps.event.clearInstanceListeners(routePaths[i].routePathLine);
    google.maps.event.clearInstanceListeners(routePaths[i].routePathBackground);
    routePaths[i].routePathLine.setMap(null);
    routePaths[i].routePathBackground.setMap(null);
    detachLongPressCandidate(routePaths[i].routeHitTestLine);
    routePaths[i].routeHitTestLine.setMap(null);
    delete routePaths[i];
}

/**
 * Called by offline-sync.js's uploadPendingChange() right after a
 * successful 'create' sync - swaps the local placeholder id for the real
 * server id and rebuilds the polyline via createRoute(), same as a normal
 * create already uses. serverData is the full re-fetched row the API
 * always responds with (points still JSON-encoded, like any other route
 * fetch - parsed here same as everywhere else in this file).
 *
 * Also called for a plain edit's sync (clientId already the real id there -
 * see uploadPendingChange()), which is why the pendingNewRouteTourAttachments
 * lookup below is safe unconditionally: only saveRoute()'s new-route path
 * ever populates it, always keyed by a negative placeholder id, so an
 * edit's real, positive clientId can never match an entry.
 */
function reconcileRouteLocalId(clientId, realId, serverData) {
    var index = routes.findIndex(function (r) { return r && r.id === clientId; });
    if (index === -1) {
        return;
    }
    removeRouteOverlayAt(index);
    serverData.points = JSON.parse(serverData.points);
    routes[index] = serverData;
    createRoute(index);

    var pendingTourAttachment = pendingNewRouteTourAttachments[clientId];
    if (pendingTourAttachment) {
        delete pendingNewRouteTourAttachments[clientId];
        attachRouteToTour(realId, pendingTourAttachment.tourId, pendingTourAttachment.tourName);
    }
}

/**
 * Called by offline-sync.js's resolveConflictKeepServer() when a locally
 * queued delete conflicted with a change made on the server (todo.md's
 * "Offline-Funktionalität" Phase 3) - the local array entry/polyline are
 * already gone (removeRoute() removes them immediately, local-first), so
 * this just re-adds the server's current version via the same helper a
 * freshly-created route uses.
 */
function restoreRouteFromServer(serverData) {
    serverData.points = JSON.parse(serverData.points);
    addSavedRouteToMap(serverData);
}

/**
 * Entfernt die Route <i> aus der Datenbank
 *
 * @param {int} i Index der Route im Array routes[]
 */
async function removeRoute(i) {
    if (typeof routes[i] === null || typeof routes[i] === 'undefined') {
        log('removeRoute(' + i + '): index not found.', LOG_INFO);
        return false;
    }

    if (!(await showConfirmDialog(t('route.confirm_delete'), { type: 'danger', confirmLabel: t('common.delete') }))) {
        return false;
    }

    var id = routes[i].id;

    // A route that was never synced (id < 0, still just a queued 'create')
    // can't possibly belong to a tour yet - always safe to cancel it
    // locally, no network involved at all (see enqueueChange()).
    if (id < 0) {
        hideRouteLabels(i);
        measureTool.index = null;
        measureTool.end();
        removeRouteOverlayAt(i);
        delete routes[i];
        enqueueChange('route', 'delete', id, null);
        document.getElementById('routeButton').classList.remove('active');
        routeEditWindow.close();
        return true;
    }

    hideRouteLabels(i);

    log('removeRoute(' + i + ')', LOG_INFO, id);

    var path = '/routes/' + id;

    try {
        await Ytan.del(path);
    } catch (err) {
        // A route that's part of >=1 tours 422s with a captcha challenge
        // instead of deleting immediately (Touren.md's "warn + simple
        // captcha before deleting a tour route" flow) - needs an
        // interactive, online-only round trip, so this stays the old,
        // immediate, online-required flow rather than deferring to the
        // offline queue: solve it and retry the same DELETE with the
        // answer attached.
        if (err.status === 422 && err.data && err.data.captcha) {
            var answer = await showCaptchaDialog(err.data.captcha.question);
            if (answer === null) {
                return false;
            }
            try {
                await Ytan.del(path + '?captcha_token=' + encodeURIComponent(err.data.captcha.token) + '&captcha_answer=' + encodeURIComponent(answer));
            } catch (err2) {
                log('removeRoute() failed (after captcha)', LOG_ERROR, err2);
                showToast(t('route.remove_failed', { error: err2.message }), 'error');
                return false;
            }
        } else if (err.status === undefined) {
            // A genuine network failure (offline/unreachable - api-
            // client.js's networkError() never sets .status, unlike a real
            // HTTP error response) - fall back to the local-first queue
            // instead of just failing, same as POI/Gebiet deletion already
            // does unconditionally. Whether THIS route turns out to need
            // the captcha step above will only be discovered once the
            // queued delete actually reaches the server.
            log('removeRoute() network failure, queueing for later', LOG_WARN, err);
            measureTool.index = null;
            measureTool.end();
            removeRouteOverlayAt(i);
            var expectedUpdatedAt = routes[i].updated_at;
            delete routes[i];
            enqueueChange('route', 'delete', id, { expected_updated_at: expectedUpdatedAt }).then(function () {
                syncPendingChanges({ manual: false });
            });
            document.getElementById('routeButton').classList.remove('active');
            routeEditWindow.close();
            return true;
        } else {
            log('removeRoute() failed', LOG_ERROR, err);
            showToast(t('route.remove_failed', { error: err.message }), 'error');
            return false;
        }
    }

    log('removeRoute() success', LOG_INFO);
    measureTool.index = null;
    measureTool.end();

    // delete routes[i]/routePaths[i] rather than .splice() them out - a
    // splice() shifts every later route down by one array index, but
    // createRoute()'s click/dblclick/contextmenu listeners on each route's
    // polylines are closed over the index it had at creation time, so a
    // shift leaves them stale (pointing at the wrong route, or past the end
    // of the now-shorter array - "Cannot read properties of undefined
    // (reading 'user_id')" in showRouteContextMenu()). delete leaves a hole
    // instead of shifting anything, so every other route's index - and its
    // listeners - stays valid. Same approach poi.js's removeMarkerById()
    // already uses; the rest of this file already guards every routes[]/
    // routePaths[] access with typeof !== 'undefined' for exactly this
    // reason (createRoute(), createRoutes(), hideRoute(), ...), so no new
    // guards are needed here - just this deletion itself.
    removeRouteOverlayAt(i);
    delete routes[i];

    document.getElementById('routeButton').classList.remove('active');
    routeEditWindow.close();
}

/**
 * Entfernt alle vorhandenen Routen-Polylinien von der Karte und löscht ihre
 * Click-Handler, bevor routes[]/routePaths[] neu befüllt werden. Ohne das
 * würden alte Polylinien (z.B. aus getPublicRoutes() vor dem Login) samt
 * ihrer auf den alten Array-Index geschlossenen Click-Handler auf der Karte
 * hängen bleiben - ein Klick darauf würde dann per Index in das neue,
 * inzwischen andere routes[]-Array greifen und die falsche Route auswählen.
 *
 * Same problem applies to a route's distance-label markers (showRouteLabels()) -
 * they're a separate set of map overlays, not children of the polyline, so
 * wiping routes[] here without also removing them first orphans whatever
 * labels happened to be visible: still on the map, but frozen at their
 * last zoom level forever, since renewVisibleRouteLabels() only walks the
 * (now different) routes[] array on future zoom changes and never finds
 * them again to update or remove.
 */
function deleteRoutes() {
    for (let i = 0; i < routePaths.length; i++) {
        if (typeof routePaths[i] !== "undefined") {
            google.maps.event.clearInstanceListeners(routePaths[i].routePathLine);
            google.maps.event.clearInstanceListeners(routePaths[i].routePathBackground);
            routePaths[i].routePathLine.setMap(null);
            routePaths[i].routePathBackground.setMap(null);
            detachLongPressCandidate(routePaths[i].routeHitTestLine);
            routePaths[i].routeHitTestLine.setMap(null);
        }
    }
    for (let i = 0; i < routes.length; i++) {
        if (typeof routes[i] !== "undefined") {
            hideRouteLabels(i);
        }
    }
    routes = [];
    routePaths = [];
}

/**
 * Hole alle Routen eines Users und speichere sie in routes[] und zeige sie an
 *
 * @param {number} userId
 */
function getRoutesByUserId(userId) {
    Ytan.get('/routes?scope=mine_public').then(answer => {
        deleteRoutes();
        routes = answer.data;
        log('getRoutesByUserId(' + userId + ')', LOG_INFO, answer);
        for (let i = 0; i < routes.length; i++) {
            routes[i].points = JSON.parse(routes[i].points);
            routes[i].labels = [];
        }
        createRoutes();
        if (settings.detailroutes) {
            showRoutes();
        }
        // Cached AFTER the points/labels normalization above - one shape
        // everywhere, so the offline fallback below doesn't need its own
        // separate parsing step.
        putCachedCollection('routes', 'mine_public', routes);
    }).catch(err => {
        log('getRoutesByUserId() failed', LOG_ERROR, err);
        getCachedCollection('routes', 'mine_public').then(cached => {
            if (cached) {
                deleteRoutes();
                routes = cached.data;
                // points is already a parsed array in the cached copy;
                // labels is transient UI state, never persisted - reset
                // fresh rather than trusting a stale cached value.
                for (let i = 0; i < routes.length; i++) {
                    routes[i].labels = [];
                }
                createRoutes();
                if (settings.detailroutes) {
                    showRoutes();
                }
            }
            notifyOfflineFallback('routes', cached ? cached.cachedAt : null);
        });
    });
}

/**
 * Hole alle öffentlichen Routen speichere sie in routes[] und zeige sie an
 */
function getPublicRoutes() {
    Ytan.get('/routes?scope=public').then(answer => {
        log('getPublicRoutes()', LOG_INFO, answer);
        deleteRoutes();
        routes = answer.data;
        for (let i = 0; i < routes.length; i++) {
            routes[i].points = JSON.parse(routes[i].points);
            routes[i].labels = [];
        }
        createRoutes();
        if (settings.detailroutes) {
            showRoutes();
        }
        putCachedCollection('routes', 'public', routes);
    }).catch(err => {
        log('getPublicRoutes() failed', LOG_ERROR, err);
        getCachedCollection('routes', 'public').then(cached => {
            if (cached) {
                deleteRoutes();
                routes = cached.data;
                for (let i = 0; i < routes.length; i++) {
                    routes[i].labels = [];
                }
                createRoutes();
                if (settings.detailroutes) {
                    showRoutes();
                }
            }
            notifyOfflineFallback('routes', cached ? cached.cachedAt : null);
        });
    });
}

/**
 * Hole alle Routen einer Tour, speichere sie in routes[] und zeige sie an
 *
 * @param {number} tourId
 */
function getRoutesByTourId(tourId) {
    Ytan.get('/routes?tour_id=' + encodeURIComponent(tourId)).then(answer => {
        deleteRoutes();
        routes = answer.data;
        log('getRoutesByTourId(' + tourId + ')', LOG_INFO, answer);
        for (let i = 0; i < routes.length; i++) {
            routes[i].points = JSON.parse(routes[i].points);
            routes[i].labels = [];
        }
        createRoutes();
        if (settings.detailroutes) {
            showRoutes();
            fitToRouteBounds();
        }
    }).catch(err => log('getRoutesByTourId() failed', LOG_ERROR, err));
}

function showRoutes() {
    for (let i = 0; i < routes.length; i++) {
        if (typeof routes[i] !== "undefined") {
            showRoute(i);
        }
    }
}

function showRoute(i) {
    if (typeof routes[i] !== 'undefined') {
        routePaths[i].routePathBackground.setMap(map);
        routePaths[i].routePathLine.setMap(map);
        // Kept in sync with the two lines above - a route that isn't
        // currently shown shouldn't be long-press-context-menu-able either
        // (findLongPressTarget() skips a candidate whose overlay.getMap()
        // is falsy).
        routePaths[i].routeHitTestLine.setMap(map);
    }
}

function showRouteById(id) {
    for (let i = 0; i < routes.length; i++) {
        if (typeof routes[i] !== "undefined") {
            if (routes[i].id == id) {
                showRoute(i);
                break;
            }
        }
    }
}

/**
 * Zeigt die Entfernungen an den Segmenten einer Route
 *
 * @param {integer} i Index der Route in routes[]
 */
function showRouteLabels(i) {
    log('showRouteLabels(' + i + ')', LOG_DEBUG);

    if ((typeof routes[i].labels !== 'undefined') && (routes[i].labels != null)) {
        if (routes[i].labels.length != 0) {
            hideRouteLabels(i);
            return false;
        }
    }

    const LABEL_FONTSIZE = 12;
    const LABEL_OFFSET = -10;

    var distance = 0;
    var distanceFromStart = 0;
    var distanceToEnd = 0;
    var labelAngle = 0;
    var labelOffsetX = 0;
    var labelOffsetY = 0;
    var x;

    var zoomLevel = map.getZoom();

    routes[i].labels = [];
    for (x = 0; x < routes[i].points.length; x++) {
        if (x < routes[i].points.length - 1) {

            distanceToEnd = routes[i].length - distanceFromStart;
            distance = getDistance(routes[i].points[x], routes[i].points[x+1]);

            if ((distance > ROUTELABEL_ZOOM_VISIBILITY[zoomLevel]) || (x == 0)) {

                labelAngle = google.maps.geometry.spherical.computeHeading(routes[i].points[x], routes[i].points[x+1]) - 90;
                if (labelAngle < -90) {
                    labelAngle = labelAngle + 180;
                }
                if (labelAngle > 90) {
                    labelAngle = labelAngle - 180;
                }

                labelOffsetX = Math.sin(labelAngle * (Math.PI / 180)) * LABEL_OFFSET;
                labelOffsetY = (Math.cos(labelAngle * (Math.PI / 180)) * LABEL_OFFSET * -1) - ((LABEL_FONTSIZE * 1.1) / 2);

                var label = new markerWithLabel.MarkerWithLabel({
                    icon: {
                        path: 'M-5,0a5,5 0 1,0 10,0a5,5 0 1,0 -10,0',
                        fillColor: routes[i].color,
                        fillOpacity: 1.0,
                        anchor: new google.maps.Point(0,0),
                        strokeWeight: 2,
                        strokeColor: '#FFFFFF',
                        scale: 1
                    },
                    position: routes[i].points[x],
                    clickable: false,
                    draggable: false,
                    map: map,
                    labelContent: '<span style="color: green">' + formatDistance(distanceFromStart, settings.unit) + ' </span> | <span style="color: red">' + formatDistance(distanceToEnd, settings.unit) + '</span>',
                    labelClass: "routeLabel",
                    labelStyle: { opacity: 1.0 },
                });

                routes[i].labels.push(label);

                if (distance > ROUTELABEL_ZOOM_VISIBILITY[zoomLevel]) {
                    var segmentLabel = new RotatedLabel(getMiddleCoordinate(routes[i].points[x], routes[i].points[x+1]), formatDistance(distance, settings.unit), labelAngle, map, {
                        color: "#000000",
                        fontSize: LABEL_FONTSIZE + "px",
                        fontWeight: "bold",
                        stroke: "black",
                        textShadow: "-1px -1px rgba(255, 255, 255, 1), -1px 1px rgba(255, 255, 255, 1), 1px 1px rgba(255, 255, 255, 1), 1px -1px rgba(255, 255, 255, 1)",
                        pointerEvents: "none"
                    }, labelOffsetX, labelOffsetY);

                    routes[i].labels.push(segmentLabel);
                }
            }

            distanceFromStart += distance;
        }
    }

    if (routes[i].points.length > 0) {
        var lastLabel = new markerWithLabel.MarkerWithLabel({
            icon: {
                path: 'M-5,0a5,5 0 1,0 10,0a5,5 0 1,0 -10,0',
                fillColor: routes[i].color,
                fillOpacity: 1.0,
                anchor: new google.maps.Point(0,0),
                strokeWeight: 2,
                strokeColor: '#FFFFFF',
                scale: 1
            },
            position: routes[i].points[x-1],
            clickable: false,
            draggable: false,
            map: map,
            labelContent: '<span style="color: green">' + formatDistance(routes[i].length, settings.unit) + ' </span> | <span style="color: red">0m</span>',
            labelClass: "routeLabel",
            labelStyle: { opacity: 1.0 },
        });

        routes[i].labels.push(lastLabel);
    }
}

/**
 * Entfernt die Entfernungen an den Segmenten einer Route
 *
 * @param {integer} i Index der Route in routes[]
 */
function hideRouteLabels(i) {
    log('hideRouteLabels(' + i +')', LOG_DEBUG);
    if (i != null) {
        if ((typeof routes[i].labels !== 'undefined') && (routes[i].labels != null)) {
            routes[i].labels.forEach((label) => {
                label.setMap();
            });
            routes[i].labels = [];
        }
    }
}

function hideRoutes() {
    for (let i = 0; i < routes.length; i++) {
        hideRoute(i);
    }
}

function renewVisibleRouteLabels() {
    for (let i = 0; i < routes.length; i++) {
        // Unlike every other loop over routes[] in this file, this one was
        // missing the "is this slot actually populated" guard - a single
        // stale/undefined entry (e.g. a route deleted mid-session) would
        // throw here and silently abort the whole zoom_changed handler,
        // freezing every route's labels from that point on, not just the
        // broken one's.
        if (typeof routes[i] === 'undefined') {
            continue;
        }
        if ((typeof routes[i].labels !== 'undefined') && (routes[i].labels != null)) {
            if (routes[i].labels.length > 0) {
                hideRouteLabels(i);
                showRouteLabels(i);
            }
        }
    }
}

function hideRoute(i) {
    if (typeof routes[i] !== 'undefined') {
        routePaths[i].routePathBackground.setMap();
        routePaths[i].routePathLine.setMap();
        routePaths[i].routeHitTestLine.setMap();
    }
}

function createRoutes() {
    for (let i = 0; i < routes.length; i++) {
        if (typeof routes[i] !== "undefined") {
            createRoute(i);
        }
    }
}

/**
 * Baut alle Routen-Polylinien neu auf (z.B. nach Änderung der "Routen
 * glätten"-Einstellung), ohne routes[]/Labels anzufassen oder neu vom
 * Server zu laden - anders als deleteRoutes(), das für einen kompletten
 * Reload gedacht ist. Alte Polylinien werden zuerst exakt wie in
 * deleteRoutes() abgeräumt (Listener + setMap(null)), damit keine
 * Duplikate auf der Karte hängen bleiben. Falls gerade eine Route
 * bearbeitet wird (measureTool.index), bleibt deren fertige Polylinie
 * weiterhin versteckt - sonst würde createRoute()'s eigenes
 * showRoute()-am-Ende sie unter/über dem aktiven Bearbeiten-Overlay wieder
 * sichtbar machen (editRoute()/routeContextMenuEditInfo() verstecken sie
 * ja bewusst per hideRoute(), bevor measureTool startet).
 */
function redrawRoutes() {
    for (let i = 0; i < routePaths.length; i++) {
        if (typeof routePaths[i] !== 'undefined') {
            google.maps.event.clearInstanceListeners(routePaths[i].routePathLine);
            google.maps.event.clearInstanceListeners(routePaths[i].routePathBackground);
            routePaths[i].routePathLine.setMap(null);
            routePaths[i].routePathBackground.setMap(null);
            detachLongPressCandidate(routePaths[i].routeHitTestLine);
            routePaths[i].routeHitTestLine.setMap(null);
        }
    }
    routePaths = [];
    createRoutes();
    if (measureTool.index !== null) {
        hideRoute(measureTool.index);
    }
}

/**
 * Erzeugt das Polygon für eine Route
 *
 * @param {integer} i Index im Array routes[]
 */
function createRoute(i) {
    if (typeof routes[i] !== 'undefined') {
        // routes[i].points itself is never touched here - smoothRoutePoints()
        // returns a new array purely for the Polyline's display path. Every
        // other consumer of routes[i].points (distance labels, bounds fit,
        // measureTool editing) keeps reading the exact original vertices.
        var path = settings.smoothRoutes ? smoothRoutePoints(routes[i].points) : routes[i].points;

        var routePathBackground = new google.maps.Polyline({
            id: routes[i].id,
            path: path,
            geodesic: true,
            strokeColor: '#FFFFFF',
            strokeOpacity: 0.6,
            strokeWeight: 6,
            zIndex: ZINDEX_ROUTE
        });

        var routePathLine = new google.maps.Polyline({
            id: routes[i].id,
            path: path,
            geodesic: true,
            strokeColor: routes[i].color,
            strokeOpacity: 1.0,
            strokeWeight: 2.5,
            zIndex: ZINDEX_ROUTE
        });

        google.maps.event.addListener(routePathLine, 'click',
            function(event) {
                if (shouldSuppressClick()) {
                    return;
                }
                showRouteLabels.call(this, i);
            }
        );

        google.maps.event.addListener(routePathBackground, 'click',
            function(event) {
                if (shouldSuppressClick()) {
                    return;
                }
                showRouteLabels.call(this, i);
            }
        );

        google.maps.event.addListener(routePathLine, 'dblclick',
            function(event) {
                showRouteInfoWindow.call(this, event, i);
            }
        );

        google.maps.event.addListener(routePathBackground, 'dblclick',
            function(event) {
                showRouteInfoWindow.call(this, event, i);
            }
        );

        google.maps.event.addListener(routePathLine, 'contextmenu',
            function(event) {
                showRouteContextMenu.call(this, event, i);
            }
        );

        google.maps.event.addListener(routePathBackground, 'contextmenu',
            function(event) {
                showRouteContextMenu.call(this, event, i);
            }
        );

        // Fallback for touch devices where the browser doesn't translate a
        // long-press into the 'contextmenu' event above (map-core.js).
        //
        // Registers a separate, invisible Polyline built from the RAW
        // (never smoothed) points instead of routePathLine/routePathBackground
        // themselves - findLongPressTarget() runs google.maps.geometry.poly.
        // isLocationOnEdge() against every registered candidate on EVERY
        // 'touchstart' anywhere on the map (i.e. the start of every pan/
        // scroll gesture, not just a deliberate long-press), so registering
        // the smoothed display path here would multiply that per-touch cost
        // by segmentsPerPoint for every route on screen - confirmed as the
        // cause of noticeable scroll stutter in the native Capacitor shell
        // once "Routen glätten" was turned on (todo.md). isLocationOnEdge()'s
        // ~20px tolerance is already far coarser than any curve-vs-straight-
        // line difference, so hit-testing against the raw points is exactly
        // as accurate for this purpose while staying at the original (small)
        // point count regardless of the smoothing setting. visible:false
        // keeps it out of rendering entirely (no GPU cost) while still
        // satisfying isLocationOnEdge()'s own overlay.getMap() check;
        // clickable:false keeps it out of Maps' native click/dblclick/
        // contextmenu handling too - it only exists for this registry.
        // Not attached to the map here (no `map:` option) - showRoute()/
        // hideRoute() attach/detach it together with the two visible
        // polylines below, so a route that's currently hidden (mid-edit, or
        // settings.detailroutes off) is correctly excluded from
        // findLongPressTarget()'s overlay.getMap() check too, same as
        // before this hit-test line existed at all.
        var routeHitTestLine = new google.maps.Polyline({
            path: routes[i].points,
            visible: false,
            clickable: false
        });
        attachLongPressContextMenu(routeHitTestLine, function(event) {
            showRouteContextMenu(event, i);
        });

        routePaths[i] = {
            routePathLine: routePathLine,
            routePathBackground: routePathBackground,
            routeHitTestLine: routeHitTestLine
        };

        if (settings.detailroutes) {
            showRoute(i);
        }
    }
}

function showRouteContextMenu(event, i) {
    log('showRouteContextMenu(event, ' + i +')', LOG_DEBUG);

    if (user.id !== null) {
        if ((routes[i].user_id == user.id) || (user.is_admin === true)) {
            log('Show contextMenu', LOG_DEBUG);

            contextMenuLastLatLng = event.latLng; // routeContextMenuAddToTour() reuses this to reposition routeInfoWindow

            var content =
                '<div class="contextMenuItem" onClick="routeContextMenuEditRoute(' + i + ', null, null);"><i class="material-icons-round">edit</i>' + t('route.context.edit_route') + '</div>' +
                '<div class="contextMenuItem" onClick="routeContextMenuEditInfo(' + i + ');"><i class="material-icons-round">description</i>' + t('route.context.edit_info') + '</div>';

            if (routes[i].user_id == user.id) {
                content += '<div class="contextMenuItem" onClick="routeContextMenuAddToTour(' + i + ');"><i class="material-icons-round">playlist_add</i>' + t('route.context.add_to_tour') + '</div>';
            }

            // Same gating as tour-admin.js's "Share" button on the tour
            // detail screen (tour.public == 1) - a private route's data is
            // technically still fetchable by a direct GET /routes/{id} (no
            // assertCanView() there, same pre-existing inconsistency the
            // Share Tour feature already left alone), but offering a share
            // link for something not meant to be public would be misleading.
            if (routes[i].public == 1) {
                content += '<div class="contextMenuItem" onClick="shareRoute(' + i + ');"><i class="material-icons-round">share</i>' + t('route.context.share') + '</div>';
            }

            content +=
                '<div class="contextMenuItem" onClick="routeContextMenuRemoveRoute(' + i + ');"><i class="material-icons-round">delete</i>' + t('route.context.delete_route') + '</div>' +
                '<div class="contextMenuItem" onClick="closeContextMenu();"><i class="material-icons-round">close</i>' + t('common.cancel') + '</div>'
                ;

            contextMenu.setPosition(event.latLng);
            contextMenu.setContent(content);
            contextMenu.open(map);
        }
    }
}

function closeContextMenu() {
    contextMenu.close();
}

function routeContextMenuEditInfo(i) {
    closeContextMenu();

    measureTool.index = i;
    measureTool.start(routes[i].points);

    showRouteEditWindow(i, null);
}

function routeContextMenuEditRoute(i) {
    closeContextMenu();
    editRoute(i, null, null);
}

/**
 * Right-click equivalent of double-clicking a route then tapping its
 * "Add to tour" icon - opens the same routeInfoWindow (reusing the latLng
 * showRouteContextMenu() captured, since a context-menu click carries a
 * position too) and immediately expands its "Add to tour" popup.
 *
 * showRouteInfoWindow()'s open() call only queues the InfoWindow's content
 * for the DOM - #addToTourMenu doesn't actually exist yet on the next line,
 * so toggleAddToTourMenu(i) would find nothing and silently no-op. Google's
 * own 'domready' event (already used the same way by e.g. routeEditWindow
 * above) fires once the content is actually attached; addListenerOnce keeps
 * it from stacking up across repeated opens.
 */
function routeContextMenuAddToTour(i) {
    closeContextMenu();
    showRouteInfoWindow({ latLng: contextMenuLastLatLng }, i);
    google.maps.event.addListenerOnce(routeInfoWindow, 'domready', function() {
        toggleAddToTourMenu(i);
    });
}

function routeContextMenuRemoveRoute(i) {
    closeContextMenu();
    removeRoute(i);
}

/**
 * "Share" item in a route's right-click context menu (public routes only,
 * see showRouteContextMenu()) - builds a link that, when opened, zooms/pans
 * the map to fit this one route (see the "?route=" param handling in
 * map-core.js's initMap()/fitMapToRoutePoints()). Mirrors tour-admin.js's
 * shareTour() (Web Share API / clipboard-copy fallback, CapacitorBridge
 * first inside the native Android shell) rather than introducing a shared
 * helper for what's currently three near-identical functions (shareMap(),
 * shareTour(), this one) - each already has its own slightly different
 * share text and no behavioral logic worth deduplicating. Unlike shareTour()
 * (a plain button on the Tours panel), this is itself a context-menu item,
 * so - like every other item in showRouteContextMenu() - it closes the menu
 * first rather than leaving it open behind whatever share UI comes next.
 *
 * @param {number} i index of the route in routes[]
 */
function shareRoute(i) {
    closeContextMenu();
    var routeUrl = window.location.origin + window.location.pathname + '?route=' + routes[i].id;

    if (typeof CapacitorBridge !== 'undefined' && CapacitorBridge.isAvailable()) {
        CapacitorBridge.shareLink(routeUrl, 'YTAN', t('route.context.share_text', { name: routes[i].name }))
            .then(() => log('shareRoute(' + i + ') - successfull', LOG_INFO))
            .catch((error) => log('shareRoute(' + i + ') - error', LOG_ERROR, error));
    } else if (navigator.share) {
        navigator.share({
            title: 'YTAN',
            text: t('route.context.share_text', { name: routes[i].name }),
            url: routeUrl,
        })
            .then(() => log('shareRoute(' + i + ') - successfull', LOG_INFO))
            .catch((error) => log('shareRoute(' + i + ') - error', LOG_ERROR, error));
    } else {
        copyTextToClipboard(routeUrl);
        showToast(t('map.link_copied'), 'success');
    }
}

function showRouteInfoWindow(event, i) {
    log('showRouteInfoWindow(event, ' + i +')', LOG_DEBUG);

    hideRouteLabels(i);

    google.maps.event.clearListeners(routeInfoWindow, 'closeclick');

    google.maps.event.addListener(routeInfoWindow, 'closeclick', function(closeEvent) {
        closeRouteInfoWindow(i);
    });

    if (routeInfoWindow.isOpen == true) {
        log('routeInfoWindows is open.', LOG_DEBUG);
        closeRouteInfoWindow(i);
    }
    routeInfoWindow.routeIndex = i;

    showRouteLabels(i);

    var kilometers = (routes[i]['length'] / 1000).toFixed(2);
    var length = Intl.NumberFormat(language, { style: 'decimal' }).format(kilometers);

    var content =
        '<h3>' + routes[i]['name'] + '</h3>' +
        '<div class="infoWindowElement">' + t('route.info.length', { length: length }) + '</div>';

    // Recording metadata (when/by whom/how long) is only ever present on a
    // GPS-recorded route (track-recorder.js) in the first place, but is
    // ADDITIONALLY gated behind the route_view_recording right (or admin) -
    // matches the same redaction RouteController already applies
    // server-side, so this is a second, defense-in-depth check on the
    // display layer, not the only one: routes[i] here can be the client's
    // own optimistic just-saved object (see saveRoute()), which still has
    // these fields locally even for a non-privileged user until the next
    // server refresh, so the UI must not display them regardless.
    if ((user.is_admin === true || user.route_view_recording === true) && routes[i]['recorded_at']) {
        var dateTimeOpts = { dateStyle: 'medium', timeStyle: 'short' };
        var startDate = new Date(routes[i]['recorded_at'].replace(' ', 'T'));
        var endDate = new Date(startDate.getTime() + routes[i]['recording_duration_seconds'] * 1000);
        content += '<div class="infoWindowElement">' + t('route.info.recorded', {
            by: routes[i]['recorded_by_username'] || '?',
            start: startDate.toLocaleString(language, dateTimeOpts),
            end: endDate.toLocaleString(language, dateTimeOpts),
            duration: formatRouteRecordingDuration(routes[i]['recording_duration_seconds']),
        }) + '</div>';
    }

    content += '<div class="infoWindowElement">' + marked.parse(routes[i]['description']) + '<div>';

    if (user.id !== null) {
        if (routes[i].user_id == user.id) {
            content +=
            '<div class="infoWindowBottom">' +
                '<div class="lefthalf"><i class="material-icons-round" title="' + t('route.context.add_to_tour') + '" onClick="toggleAddToTourMenu(' + i + ');">playlist_add</i></div>' +
                '<div class="routeEdit"><i class="material-icons-round" onClick="editRoute(' + i + ', ' + event.latLng.lat() + ', ' + event.latLng.lng() +');">edit</i></div>' +
            '</div>' +
            '<div id="addToTourMenu" class="addToTourMenu" style="display:none;"></div>';
        }
    }

    routeInfoWindow.setPosition(event.latLng);
    routeInfoWindow.setContent(content);

    routeInfoWindow.open(map);
}

function closeRouteInfoWindow(i) {
    routeInfoWindow.close();
    hideRouteLabels(i);
}

/**
 * "Option C" from the Touren feature design: a contextual shortcut for
 * adding the currently-open route straight into one of the user's own
 * tours, without leaving the map or opening the full Tours panel. Building
 * up a tour from scratch route-by-route is still better done through the
 * Tours panel's "Edit Routes" checklist ("Option B") - this is a
 * complement to that, not a replacement for it.
 *
 * Also reachable via the route's right-click menu (routeContextMenuAddToTour()).
 *
 * @param {number} i index of the route in routes[]
 */
function toggleAddToTourMenu(i) {
    var menu = document.getElementById('addToTourMenu');
    if (!menu) {
        return;
    }

    if (menu.style.display === 'block') {
        menu.style.display = 'none';
        return;
    }

    addToTourMenuRouteIndex = i;
    addToTourMenuSearchQuery = '';
    addToTourMenuLengthFilter = { min: '', max: '' };
    renderAddToTourMenu();
    menu.style.display = 'block';
}

/**
 * Builds the popup's static shell (search bar + length filter, mirroring
 * tour-admin.js's list view) once, then delegates the actual tour list to
 * renderFilteredAddToTourMenuList() - the same split renderTourList()/
 * renderFilteredTourList() uses, so typing in the search box only re-renders
 * the results, not the input itself.
 */
function renderAddToTourMenu() {
    var menu = document.getElementById('addToTourMenu');
    if (!menu) {
        return;
    }

    var html = '<div class="search-bar"><i class="material-icons-round">search</i>' +
            '<input type="text" id="addToTourMenuSearchInput" placeholder="' + t('route.add_to_tour.search_placeholder') + '" oninput="onAddToTourMenuSearchInput();"></div>' +
        '<div class="tour-length-filter">' +
            '<span class="nav-field-label" style="margin:0;">' + (settings.unit === 'nautical' ? t('common.length_nm') : t('common.length_km')) + '</span>' +
            '<input type="number" min="0" id="addToTourMenuLengthMin" placeholder="' + t('common.from') + '" oninput="onAddToTourMenuLengthFilterChange();">' +
            '<span>&ndash;</span>' +
            '<input type="number" min="0" id="addToTourMenuLengthMax" placeholder="' + t('common.to') + '" oninput="onAddToTourMenuLengthFilterChange();">' +
        '</div>' +
        '<div id="addToTourMenuResults"></div>';

    menu.innerHTML = html;
    renderFilteredAddToTourMenuList();
}

function onAddToTourMenuSearchInput() {
    addToTourMenuSearchQuery = document.getElementById('addToTourMenuSearchInput').value;
    renderFilteredAddToTourMenuList();
}

function onAddToTourMenuLengthFilterChange() {
    addToTourMenuLengthFilter.min = document.getElementById('addToTourMenuLengthMin').value;
    addToTourMenuLengthFilter.max = document.getElementById('addToTourMenuLengthMax').value;
    renderFilteredAddToTourMenuList();
}

function renderFilteredAddToTourMenuList() {
    var i = addToTourMenuRouteIndex;
    var query = foldSearchText(addToTourMenuSearchQuery);
    var minLength = parseFloat(addToTourMenuLengthFilter.min);
    var maxLength = parseFloat(addToTourMenuLengthFilter.max);
    var lengthDivisor = settings.unit === 'nautical' ? 1852 : 1000; // matches the "Length (nm)"/"Length (km)" label above

    var allOwnTours = tours.filter(t => user.id !== null && t.user_id == user.id).filter(t => {
        if (query !== '' && !foldSearchText(t.name).includes(query)) {
            return false;
        }
        var length = (t.total_length || 0) / lengthDivisor;
        if (!isNaN(minLength) && length < minLength) return false;
        if (!isNaN(maxLength) && length > maxLength) return false;
        return true;
    });
    var ownTours = allOwnTours.slice(0, ADD_TO_TOUR_MENU_RESULT_CAP);

    var html = '';
    if (ownTours.length === 0) {
        html += '<div class="addToTourMenuItem addToTourMenuEmpty">' + t('route.add_to_tour.no_tours_found') + '</div>';
    } else {
        for (let x = 0; x < ownTours.length; x++) {
            html += '<div class="addToTourMenuItem" onclick="addRouteToTourFromPopup(' + i + ', ' + ownTours[x].id + ');">' + escapeHTML(ownTours[x].name) + '</div>';
        }
        if (allOwnTours.length > ownTours.length) {
            html += '<div class="addToTourMenuItem addToTourMenuEmpty">' + t('route.add_to_tour.more_refine', { count: allOwnTours.length - ownTours.length }) + '</div>';
        }
    }
    if (canCreateTours()) {
        html += '<div class="addToTourMenuItem addToTourMenuNew" onclick="addRouteToTourFromPopupAsNewTour(' + i + ');">' + t('route.add_to_tour.new_tour') + '</div>';
    }

    document.getElementById('addToTourMenuResults').innerHTML = html;
}

function addRouteToTourFromPopup(i, tourId) {
    Ytan.post('/tours/' + tourId + '/routes', { route_id: routes[i].id }).then(() => {
        document.getElementById('addToTourMenu').style.display = 'none';
        showToast(t('route.add_to_tour.success'), 'success');
    }).catch(err => showToast(t('route.add_to_tour.failed', { error: err.message }), 'error'));
}

function addRouteToTourFromPopupAsNewTour(i) {
    var routeId = routes[i].id;
    closeRouteInfoWindow(i);
    openTourAdminMenu();
    showTourCreateForm(routeId);
}

/**
 * Auto-attaches a just-created route (real server id required - see
 * addSavedRouteToMap()'s id > 0 guard) to a tour, with the same success/
 * failure toasts either way. Factored out of addNewRouteToActiveTour() so
 * reconcileRouteLocalId() can reach it with a tour captured earlier (not
 * necessarily still the active one) via pendingNewRouteTourAttachments.
 *
 * Named attachRouteToTour(), not addRouteToTour() - tour-admin.js already
 * defines a global addRouteToTour(routeId) of its own (the Tours panel's
 * route-membership manager, a completely different, purely local buffer
 * edit with no network call) - since nothing in this app is module-scoped,
 * a same-named function here would have silently replaced it once
 * tour-admin.js loads after route.js (see templates/app.php's script
 * order), breaking that feature.
 */
function attachRouteToTour(routeId, tourId, tourName) {
    Ytan.post('/tours/' + tourId + '/routes', { route_id: routeId }).then(() => {
        showToast(t('route.added_to_active_tour', { tour: tourName }), 'success');
    }).catch(err => showToast(t('route.active_tour_add_failed', { error: err.message }), 'error'));
}

/**
 * Auto-attaches a just-created route to the active Tour Mode tour
 * (tour.js's activeTourModeId/activeTourModeName) - called by
 * addSavedRouteToMap() when routeData already carries a real server id
 * (a finished GPS-track upload, or a conflict-resolution restore), never
 * on an edit (PUT) of an existing route.
 */
function addNewRouteToActiveTour(routeId) {
    attachRouteToTour(routeId, activeTourModeId, activeTourModeName);
}

function editRoute(i, lat, lng) {
    if (routeInfoWindow.isOpen) {
        closeRouteInfoWindow(i);
    }
    hideRoute(i);
    log('editRoute(' + i + ')', LOG_INFO, routes[i]);

    measureTool.index = i;
    measureTool.start(routes[i].points);
    document.getElementById('routeButton').classList.add('active');
    showSecondToolbar('routeButton');
}

/**
 * Zoome die Karte, so dass alle Routen sichtbar sind
 */
function fitToRouteBounds() {
    var minLat = 90;
    var maxLat = -90;
    var minLng = 180;
    var maxLng = -180;

    var lat, lng, r, p, hasPoints = false;

    for (r = 0; r < routes.length; r++) {
        if (typeof routes[r] !== "undefined") {
            for (p = 0; p < routes[r].points.length; p++) {
                hasPoints = true;
                lat = routes[r].points[p].lat;
                lng = routes[r].points[p].lng;

                if (lat < minLat) minLat = lat;
                if (lng < minLng) minLng = lng;
                if (lat > maxLat) maxLat = lat;
                if (lng > maxLng) maxLng = lng;
            }
        }
    }

    if (!hasPoints) {
        // No routes (or only empty ones) - min/max stay at their inverted
        // sentinel values, which would otherwise produce a degenerate
        // LatLngBounds that Google Maps interprets as spanning the whole
        // world instead of leaving the current view alone.
        return;
    }

    log('SW:' + minLat + ', ' + minLng + '    NE:' + maxLat + ', ' + maxLng, LOG_DEBUG);

    var bounds = new google.maps.LatLngBounds(
        new google.maps.LatLng(minLat, minLng),
        new google.maps.LatLng(maxLat, maxLng)
    );
    map.fitBounds(bounds);
}

/**
 * Zooms/pans the map to fit one specific route's points, without touching
 * whatever's currently in routes[] - used for a shared-route link landing
 * (map-core.js's initMap(), "?route=" param, mirrors the "?tour=" handling
 * right above it there). Deliberately takes a plain points array rather
 * than a routes[] index: the linked route may not even be part of routes[]
 * yet (still loading async via getPublicRoutes()/getRoutesByUserId()), same
 * reasoning as shareTour()'s landing fetching the tour directly by id
 * instead of waiting on tours[].
 *
 * @param {Array<{lat: number, lng: number}>} points
 */
function fitMapToRoutePoints(points) {
    if (!points || points.length === 0) {
        return;
    }
    var bounds = new google.maps.LatLngBounds();
    for (var p = 0; p < points.length; p++) {
        bounds.extend(new google.maps.LatLng(points[p].lat, points[p].lng));
    }
    map.fitBounds(bounds);
}
