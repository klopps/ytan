/**
 * Live GPS route recording, native Android shell only (Capacitor
 * background-geolocation via public/js/capacitor-bridge.js). Records
 * points to a local IndexedDB buffer while recording (so a multi-hour
 * recording survives the app process being killed in the background),
 * then hands the finished track off into the EXISTING manual route-editing
 * flow (measureTool.start(points) + showRouteEditWindow(), both from
 * route.js) to save it via the normal POST /routes API - no parallel save
 * path, no backend duplication.
 *
 * A complete no-op outside the native shell: guarded on
 * CapacitorBridge.isAvailable(), same convention as capacitor-bridge.js
 * itself.
 */
(function () {
    if (typeof CapacitorBridge === 'undefined' || !CapacitorBridge.isAvailable()) {
        return;
    }

    // The only tunable lever this plugin's API exposes for battery/update
    // frequency (see capacitor-bridge.js/todo.md) - three user-chosen
    // presets rather than one fixed value, persisted like any other
    // preference (settings.js). The meter values themselves are now
    // admin-configurable (/admin/settings, SettingsController/
    // SettingsRepository) rather than hardcoded here - templates/app.php
    // injects the current values as window.YTAN_TRACK_DISTANCE_FILTER_PRESETS,
    // same pattern as window.YTAN_GOOGLE_SEARCH_REQUIRES_LOGIN. The literal
    // object here is only a fallback for the (should-never-happen) case of
    // that global being missing.
    const TRACK_DISTANCE_FILTER_PRESETS = window.YTAN_TRACK_DISTANCE_FILTER_PRESETS || { precise: 20, balanced: 50, battery: 100 };
    // Always applied to a finished recording (see simplifyTrackPoints()) -
    // starts with a few meters of tolerance to drop redundant
    // near-collinear points, then escalates until the point count fits
    // under TRACK_SIMPLIFY_MAX_POINTS - for a real multi-hour kayak day
    // tour, that escalation is normally what actually determines the
    // final point count, not the starting epsilon (a whole day's paddle
    // needs at most ~100 points to look right on the map; the 3m starting
    // value only matters for very short recordings).
    const TRACK_SIMPLIFY_EPSILON_METERS = 3;
    const TRACK_SIMPLIFY_MAX_POINTS = 100;
    const RECORDING_DB_NAME = 'ytan-track-recorder';
    // v2 added the 'pending' store (local-first "save now, upload later"
    // queue for finished recordings - see saveRecordedTrackLocally()).
    const RECORDING_DB_VERSION = 2;
    const CURRENT_META_ID = 'current'; // this app only ever has one recording in progress at a time
    // #trackRecordingBadge sits directly on the map, right where a user's
    // thumb naturally lands while panning/zooming near it - a plain tap
    // would reopen the recording screen far too easily. 3s press-and-hold
    // (see initTrackRecordingBadgePressHold()) makes that deliberate.
    const TRACK_BADGE_HOLD_MS = 3000;
    const TRACK_BADGE_HOLD_MOVE_TOLERANCE_PX = 10;

    let db = null;
    let watcherId = null;
    let liveMeta = null; // {recordingId, startedAt, status: 'recording'|'paused'|'stopped', distanceFilter}
    let livePointCount = 0;
    let liveDistanceMeters = 0;
    let lastPoint = null;
    // In-memory mirror of the 'pending' IndexedDB store, kept fresh by
    // refreshPendingTracks() - renderIdleState() builds its "pending
    // tracks" section synchronously from this array rather than reading
    // IndexedDB (inherently async) on every render.
    let pendingTracksCache = [];
    let badgeTickTimer = null;
    let badgeHoldTimer = null;
    let badgeHoldStartPos = null;
    // Last result of CapacitorBridge.checkLocationPermissionStatus(), used
    // only to render the idle-screen warning banner proactively - see
    // refreshPermissionStatus(). The authoritative check that actually
    // gates startRecording() re-queries live in trackRecorderStartClicked()
    // rather than trusting this cache, since it can be stale (e.g. the user
    // granted a permission in Settings and came straight back).
    let lastPermissionStatus = null;

    // --- IndexedDB buffer -----------------------------------------------

    function openRecordingDb() {
        if (db) {
            return Promise.resolve(db);
        }
        return new Promise(function (resolve, reject) {
            const request = indexedDB.open(RECORDING_DB_NAME, RECORDING_DB_VERSION);
            request.onupgradeneeded = function () {
                const upgradeDb = request.result;
                // Guarded on objectStoreNames.contains() rather than
                // branching on event.oldVersion - works the same for a
                // fresh v0->v2 install and an existing v1->v2 upgrade
                // without needing two code paths.
                if (!upgradeDb.objectStoreNames.contains('points')) {
                    const points = upgradeDb.createObjectStore('points', { keyPath: 'seq', autoIncrement: true });
                    points.createIndex('recordingId', 'recordingId');
                }
                if (!upgradeDb.objectStoreNames.contains('meta')) {
                    upgradeDb.createObjectStore('meta', { keyPath: 'id' });
                }
                if (!upgradeDb.objectStoreNames.contains('pending')) {
                    upgradeDb.createObjectStore('pending', { keyPath: 'id' });
                }
            };
            request.onsuccess = function () {
                db = request.result;
                resolve(db);
            };
            request.onerror = function () {
                reject(request.error);
            };
        });
    }

    function putPoint(recordingId, point) {
        return openRecordingDb().then(function (database) {
            return new Promise(function (resolve, reject) {
                const tx = database.transaction('points', 'readwrite');
                tx.objectStore('points').add(Object.assign({ recordingId: recordingId }, point));
                tx.oncomplete = resolve;
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function getAllPoints(recordingId) {
        return openRecordingDb().then(function (database) {
            return new Promise(function (resolve, reject) {
                const tx = database.transaction('points', 'readonly');
                const index = tx.objectStore('points').index('recordingId');
                const request = index.getAll(IDBKeyRange.only(recordingId));
                request.onsuccess = function () { resolve(request.result); };
                request.onerror = function () { reject(request.error); };
            });
        });
    }

    function clearRecording(recordingId) {
        return openRecordingDb().then(function (database) {
            return new Promise(function (resolve, reject) {
                const tx = database.transaction(['points', 'meta'], 'readwrite');
                const index = tx.objectStore('points').index('recordingId');
                const cursorRequest = index.openCursor(IDBKeyRange.only(recordingId));
                cursorRequest.onsuccess = function () {
                    const cursor = cursorRequest.result;
                    if (cursor) {
                        cursor.delete();
                        cursor.continue();
                    }
                };
                // Only clears the 'current' slot if it still belongs to
                // THIS recording, rather than deleting it unconditionally -
                // saveRecordedTrackLocally() frees the slot in the
                // background (after the user is already back at the map,
                // edit window already closed), so a user starting the next
                // leg's recording quickly enough could otherwise have their
                // brand-new 'current' meta wiped out by this call landing a
                // moment later for the PREVIOUS leg.
                const metaStore = tx.objectStore('meta');
                const metaRequest = metaStore.get(CURRENT_META_ID);
                metaRequest.onsuccess = function () {
                    const currentMeta = metaRequest.result;
                    if (currentMeta && currentMeta.recordingId === recordingId) {
                        metaStore.delete(CURRENT_META_ID);
                    }
                };
                tx.oncomplete = resolve;
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function putMeta(meta) {
        return openRecordingDb().then(function (database) {
            return new Promise(function (resolve, reject) {
                const tx = database.transaction('meta', 'readwrite');
                tx.objectStore('meta').put(Object.assign({ id: CURRENT_META_ID }, meta));
                tx.oncomplete = resolve;
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function getMeta() {
        return openRecordingDb().then(function (database) {
            return new Promise(function (resolve, reject) {
                const tx = database.transaction('meta', 'readonly');
                const request = tx.objectStore('meta').get(CURRENT_META_ID);
                request.onsuccess = function () { resolve(request.result || null); };
                request.onerror = function () { reject(request.error); };
            });
        });
    }

    // --- Pending-upload queue ("save locally now, upload once online") --

    function putPending(entry) {
        return openRecordingDb().then(function (database) {
            return new Promise(function (resolve, reject) {
                const tx = database.transaction('pending', 'readwrite');
                tx.objectStore('pending').put(entry);
                tx.oncomplete = resolve;
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function getAllPending() {
        return openRecordingDb().then(function (database) {
            return new Promise(function (resolve, reject) {
                const tx = database.transaction('pending', 'readonly');
                const request = tx.objectStore('pending').getAll();
                request.onsuccess = function () { resolve(request.result); };
                request.onerror = function () { reject(request.error); };
            });
        });
    }

    function deletePending(id) {
        return openRecordingDb().then(function (database) {
            return new Promise(function (resolve, reject) {
                const tx = database.transaction('pending', 'readwrite');
                tx.objectStore('pending').delete(id);
                tx.oncomplete = resolve;
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    /**
     * Called once at startup - if the app process was killed mid-recording
     * (the scenario the original background-GPS proof-of-concept never
     * exercised), this detects it and offers to resume rather than
     * silently losing hours of buffered points.
     */
    function loadInProgressRecording() {
        getMeta().then(function (meta) {
            if (!meta || meta.status === 'stopped') {
                return;
            }
            getAllPoints(meta.recordingId).then(function (points) {
                liveMeta = meta;
                livePointCount = points.length;
                liveDistanceMeters = computeTotalDistance(points);
                lastPoint = points.length > 0 ? points[points.length - 1] : null;
                showToast(t('trackrecorder.resumed_notice', { count: livePointCount }), 'info');
                if (meta.status === 'recording') {
                    // Re-register the native watcher - it does not survive a process kill on its own.
                    registerWatcher();
                }
                updateRecordingBadge();
            });
        }).catch(function (err) {
            log('loadInProgressRecording() failed', LOG_ERROR, err);
        });
    }

    // --- Distance/duration helpers ---------------------------------------

    function computeTotalDistance(points) {
        let total = 0;
        for (let i = 1; i < points.length; i++) {
            total += getDistance(points[i - 1], points[i]);
        }
        return total;
    }

    function formatDuration(totalSeconds) {
        const h = Math.floor(totalSeconds / 3600);
        const m = Math.floor((totalSeconds % 3600) / 60);
        const s = Math.floor(totalSeconds % 60);
        return pad2(h) + ':' + pad2(m) + ':' + pad2(s);
    }

    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    /**
     * ISO 8601 ("2026-09-14T18:13:56.158Z") -> MySQL DATETIME format
     * ("2026-09-14 18:13:56"), UTC in both cases. See reviewRecordedTrack().
     */
    function toMysqlDatetime(isoString) {
        return isoString.slice(0, 19).replace('T', ' ');
    }

    // --- Permission check --------------------------------------------------

    /**
     * Re-queries the native permission/settings state and caches it in
     * lastPermissionStatus for renderPermissionWarning(), then re-renders
     * so an already-open idle screen picks up the result. Called when the
     * screen is opened and again whenever the app comes back to the
     * foreground while it's still showing (see the visibilitychange
     * listener in initTrackRecorder()) - covers the "tapped 'Open
     * Settings', granted the permission, came straight back" round trip
     * without needing to re-open the screen.
     */
    function refreshPermissionStatus() {
        if (!CapacitorBridge.checkLocationPermissionStatus) {
            return;
        }
        CapacitorBridge.checkLocationPermissionStatus().then(function (status) {
            lastPermissionStatus = status;
            renderTrackRecorderScreen();
        });
    }

    function renderPermissionWarning() {
        if (!lastPermissionStatus || lastPermissionStatus.allGranted) {
            return '';
        }
        const missing = [];
        if (!lastPermissionStatus.foregroundLocation) {
            missing.push(t('trackrecorder.permission_missing_foreground'));
        }
        // Only worth mentioning "always allow" once foreground itself is
        // granted - otherwise the foreground message above already covers it.
        if (lastPermissionStatus.foregroundLocation && !lastPermissionStatus.backgroundLocation) {
            missing.push(t('trackrecorder.permission_missing_background'));
        }
        if (!lastPermissionStatus.notifications) {
            missing.push(t('trackrecorder.permission_missing_notifications'));
        }
        if (!lastPermissionStatus.locationServicesEnabled) {
            missing.push(t('trackrecorder.permission_missing_location_services'));
        }
        return '' +
            '<div class="track-recorder-permission-warning">' +
                '<p class="track-recorder-permission-warning-title"><i class="material-icons-round">warning</i>' + t('trackrecorder.permission_warning_title') + '</p>' +
                '<ul>' + missing.map(function (m) { return '<li>' + m + '</li>'; }).join('') + '</ul>' +
                '<p class="track-recorder-permission-warning-hint">' + t('trackrecorder.permission_open_settings_hint') + '</p>' +
                '<button type="button" class="nav-btn-secondary" onclick="openTrackRecorderSettings();"><i class="material-icons-round">settings</i>&nbsp;' + t('trackrecorder.permission_open_settings_button') + '</button>' +
            '</div>';
    }

    window.openTrackRecorderSettings = function () {
        if (!CapacitorBridge.openAppSettings) {
            return;
        }
        CapacitorBridge.openAppSettings();
    };

    // --- Recording state machine -----------------------------------------

    function registerWatcher() {
        CapacitorBridge.startLocationWatch(
            {
                distanceFilter: liveMeta.distanceFilter,
                backgroundTitle: t('trackrecorder.notification_title'),
                backgroundMessage: t('trackrecorder.notification_message'),
            },
            onLocationUpdate,
            function (id) {
                watcherId = id;
            }
        );
    }

    function onLocationUpdate(location, error) {
        if (error) {
            log('track-recorder watcher error', LOG_ERROR, error);
            showToast(t('trackrecorder.location_error', { error: error.message }), 'error');
            return;
        }
        if (!liveMeta || liveMeta.status !== 'recording') {
            return; // a stray callback after pause/stop - ignore
        }
        const point = {
            lat: location.latitude,
            lng: location.longitude,
            accuracy: location.accuracy,
            altitude: location.altitude,
            speed: location.speed,
            time: location.time,
        };
        putPoint(liveMeta.recordingId, point);
        if (lastPoint) {
            liveDistanceMeters += getDistance(lastPoint, point);
        }
        lastPoint = point;
        livePointCount++;
        updateRecordingBadge();
        renderTrackRecorderScreen();
    }

    function startRecording(distanceFilterPreset) {
        const recordingId = String(Date.now());
        liveMeta = {
            recordingId: recordingId,
            startedAt: new Date().toISOString(),
            status: 'recording',
            distanceFilter: TRACK_DISTANCE_FILTER_PRESETS[distanceFilterPreset] || TRACK_DISTANCE_FILTER_PRESETS.balanced,
        };
        livePointCount = 0;
        liveDistanceMeters = 0;
        lastPoint = null;
        putMeta(liveMeta);
        registerWatcher();
        updateRecordingBadge();
        renderTrackRecorderScreen();
    }

    function pauseRecording() {
        if (!liveMeta || watcherId === null) {
            return;
        }
        CapacitorBridge.stopLocationWatch(watcherId).then(function () {
            watcherId = null;
        });
        liveMeta.status = 'paused';
        putMeta(liveMeta);
        updateRecordingBadge();
        renderTrackRecorderScreen();
    }

    function resumeRecording() {
        if (!liveMeta) {
            return;
        }
        liveMeta.status = 'recording';
        putMeta(liveMeta);
        registerWatcher();
        updateRecordingBadge();
        renderTrackRecorderScreen();
    }

    function stopRecording() {
        if (!liveMeta) {
            return;
        }
        const finish = function () {
            liveMeta.status = 'stopped';
            liveMeta.stoppedAt = new Date().toISOString();
            putMeta(liveMeta);
            hideRecordingBadge();
            getAllPoints(liveMeta.recordingId).then(function (points) {
                reviewRecordedTrack(points, liveMeta);
            });
        };
        if (watcherId !== null) {
            CapacitorBridge.stopLocationWatch(watcherId).then(function () {
                watcherId = null;
                finish();
            });
        } else {
            finish();
        }
    }

    function discardRecording() {
        showConfirmDialog(t('trackrecorder.confirm_discard'), { type: 'danger', confirmLabel: t('common.delete') }).then(function (confirmed) {
            if (!confirmed) {
                return;
            }
            const recordingId = liveMeta ? liveMeta.recordingId : null;
            if (watcherId !== null) {
                CapacitorBridge.stopLocationWatch(watcherId);
                watcherId = null;
            }
            hideRecordingBadge();
            liveMeta = null;
            livePointCount = 0;
            liveDistanceMeters = 0;
            lastPoint = null;
            if (recordingId) {
                clearRecording(recordingId);
            }
            renderTrackRecorderScreen();
        });
    }

    /**
     * Hands the finished track off into route.js's EXISTING manual route
     * editing UI (same mechanism used to edit an already-saved route -
     * measureTool.start(points) + showRouteEditWindow()) purely for the
     * Name/Beschreibung/Farbe form and its live preview line - NOT to save
     * it over the network. saveRoute() (route.js) checks
     * pendingRouteRecordingMeta and, when set, calls
     * saveRecordedTrackLocally() below instead of POSTing: a route born
     * from a GPS recording is always saved into the local pending-upload
     * queue first, network or not, and synced later (see
     * syncPendingTracks()) - a multi-hour kayak day tour is very likely
     * recorded with no signal at all for its whole duration, so waiting on
     * a network attempt before the user can start recording the next leg
     * would defeat the point.
     *
     * Login is checked HERE, before touching measureTool at all:
     * showRouteEditWindow() itself calls cancelEditRoute() -> measureTool.end()
     * when logged out, which would silently discard whatever was just fed
     * into measureTool - verified in route.js while planning this feature.
     * The recording stays intact in IndexedDB either way; only a
     * successful local save (or, for the standard manually-drawn route
     * this module never touches, a successful network save) clears it.
     */
    function reviewRecordedTrack(points, meta) {
        if (points.length < 2) {
            showToast(t('trackrecorder.too_few_points'), 'error');
            return;
        }
        if (user.id === null) {
            showToast(t('trackrecorder.login_required'), 'error');
            return;
        }

        const simplified = simplifyTrackPoints(points, TRACK_SIMPLIFY_EPSILON_METERS, TRACK_SIMPLIFY_MAX_POINTS);
        const latLngPoints = simplified.map(function (p) { return { lat: p.lat, lng: p.lng }; });

        const startedAtMs = new Date(meta.startedAt).getTime();
        const stoppedAtMs = new Date(meta.stoppedAt).getTime();
        pendingRouteRecordingMeta = {
            // MySQL's DATETIME column rejects a plain ISO 8601 string
            // (e.g. "2026-09-14T18:13:56.158Z") - confirmed live via a
            // real save attempt: "Incorrect datetime value" from
            // RouteRepository::create(). Needs "YYYY-MM-DD HH:MM:SS".
            recorded_at: toMysqlDatetime(meta.startedAt),
            recording_duration_seconds: Math.round((stoppedAtMs - startedAtMs) / 1000),
        };

        closeMenu();
        // Mirrors editRoute() (route.js) - the existing "load a pre-built
        // points array into measureTool for editing" path, used today to
        // edit an already-saved route. index=null marks this as a NEW
        // route (showRouteEditWindow()/saveRoute() branch on it for
        // POST vs PUT), matching cancelEditRoute()'s own convention.
        measureTool.index = null;
        measureTool.start(latLngPoints);
        document.getElementById('routeButton').classList.add('active');
        showSecondToolbar('routeButton');
        showRouteEditWindow(null, latLngPoints[latLngPoints.length - 1]);
    }

    /**
     * Called by route.js's saveRoute() instead of POSTing, whenever
     * pendingRouteRecordingMeta is set (i.e. the route being saved came
     * from reviewRecordedTrack() above, not a manually-drawn one). routeData
     * is already fully built and validated by saveRoute() (name/description/
     * color/public/length/points, points already JSON.stringify()'d) - this
     * just re-homes it from "about to be POSTed" to "written into the local
     * pending-upload queue", frees the 'current' recording slot for the next
     * leg, and kicks off a background sync attempt (silent on failure - the
     * whole point is that this is expected to fail often).
     */
    function saveRecordedTrackLocally(routeData) {
        getMeta().then(function (meta) {
            const recordingId = meta ? meta.recordingId : String(Date.now());
            const pendingEntry = {
                id: recordingId,
                name: routeData.name,
                description: routeData.description,
                public: routeData.public,
                length: routeData.length,
                points: routeData.points, // already a JSON string, see above
                color: routeData.color,
                recorded_at: routeData.recorded_at,
                recording_duration_seconds: routeData.recording_duration_seconds,
                savedLocallyAt: new Date().toISOString(),
            };
            putPending(pendingEntry).then(function () {
                if (meta) {
                    clearRecording(meta.recordingId);
                }
                showToast(t('trackrecorder.saved_locally_toast'), 'success');
                refreshPendingTracks();
                syncPendingTracks({ manual: false });
            });
        });
    }
    window.saveRecordedTrackLocally = saveRecordedTrackLocally;

    /**
     * POSTs one pending entry exactly like route.js's saveRoute() would
     * have, then hands the result to route.js's addSavedRouteToMap() - the
     * same "just got a server id back for a brand-new route" step
     * saveRoute()'s own success handler uses, just reached from a
     * background sync instead of a live save click.
     */
    function uploadPendingTrack(entry) {
        const routeData = {
            id: null,
            user_id: user.id,
            name: entry.name,
            description: entry.description,
            public: entry.public,
            length: entry.length,
            points: entry.points,
            color: entry.color,
            recorded_at: entry.recorded_at,
            recording_duration_seconds: entry.recording_duration_seconds,
        };
        return Ytan.post('/routes', routeData).then(function (answer) {
            routeData.id = answer.data.id;
            routeData.points = JSON.parse(routeData.points);
            addSavedRouteToMap(routeData);
            if (settings.detailroutes === false) {
                document.getElementById('detailroutes').checked = true;
                settings.detailroutes = true;
                saveSettings();
                showRoutes();
            }
            return deletePending(entry.id);
        });
    }

    /**
     * Uploads every currently pending track, one at a time (not in
     * parallel - avoids hammering a connection that may have only just
     * barely come back). Called automatically (opts.manual=false, no
     * failure toast - a background attempt failing just means still no
     * signal, not worth nagging about) from saveRecordedTrackLocally(),
     * openTrackRecorderScreen() and the 'online' event listener below, and
     * manually (opts.manual=true, failures DO get a toast) from the
     * "Alle hochladen" button.
     */
    function syncPendingTracks(opts) {
        opts = opts || {};
        if (user.id === null) {
            return Promise.resolve();
        }
        return getAllPending().then(function (entries) {
            if (entries.length === 0) {
                return;
            }
            let uploadedCount = 0;
            let failedCount = 0;
            let chain = Promise.resolve();
            entries.forEach(function (entry) {
                chain = chain.then(function () {
                    return uploadPendingTrack(entry).then(function () {
                        uploadedCount++;
                    }).catch(function (err) {
                        log('syncPendingTracks() failed for ' + entry.id, LOG_WARN, err);
                        failedCount++;
                    });
                });
            });
            return chain.then(function () {
                if (uploadedCount > 0) {
                    showToast(t('trackrecorder.pending_upload_success', { count: uploadedCount }), 'success');
                }
                if (failedCount > 0 && opts.manual) {
                    showToast(t('trackrecorder.pending_upload_failed', { count: failedCount }), 'error');
                }
                refreshPendingTracks();
            });
        });
    }
    window.syncPendingTracks = syncPendingTracks;

    function refreshPendingTracks() {
        return getAllPending().then(function (entries) {
            // Newest first - the leg just finished belongs at the top.
            entries.sort(function (a, b) { return b.id.localeCompare(a.id); });
            pendingTracksCache = entries;
            renderTrackRecorderScreen();
        });
    }

    window.uploadPendingTrackClicked = function (id) {
        const entry = pendingTracksCache.find(function (e) { return e.id === id; });
        if (!entry) {
            return;
        }
        uploadPendingTrack(entry).then(function () {
            showToast(t('trackrecorder.pending_upload_success', { count: 1 }), 'success');
            refreshPendingTracks();
        }).catch(function (err) {
            log('uploadPendingTrackClicked() failed', LOG_ERROR, err);
            showToast(t('trackrecorder.pending_upload_failed', { count: 1 }), 'error');
        });
    };

    window.discardPendingTrackClicked = function (id) {
        const entry = pendingTracksCache.find(function (e) { return e.id === id; });
        if (!entry) {
            return;
        }
        showConfirmDialog(t('trackrecorder.confirm_discard_pending', { name: entry.name }), { type: 'danger', confirmLabel: t('common.delete') }).then(function (confirmed) {
            if (!confirmed) {
                return;
            }
            deletePending(id).then(refreshPendingTracks);
        });
    };

    /**
     * Douglas-Peucker downsampling, always applied to a finished recording
     * (not just as a safety valve) - GPS noise and near-straight stretches
     * produce far more points than a route actually needs, so a small
     * epsilonMeters tolerance is run first to drop redundant points
     * without a noticeable accuracy loss. maxPoints is a hard cap on top
     * of that - a full kayak day tour only needs on the order of 100
     * points to look right on the map, so for any real multi-hour
     * recording it's normally THIS escalation loop (not the starting
     * epsilon) that ends up determining the final point count. Perpendicular
     * distance is computed via a flat equirectangular approximation (fine
     * at kayak-touring scale, no projection library needed).
     */
    function simplifyTrackPoints(points, epsilonMeters, maxPoints) {
        if (points.length < 3) {
            return points;
        }
        let epsilon = epsilonMeters;
        let simplified = douglasPeucker(points, epsilon);
        for (let i = 0; i < 10 && simplified.length > maxPoints; i++) {
            epsilon *= 2;
            simplified = douglasPeucker(points, epsilon);
        }
        return simplified;
    }

    function douglasPeucker(points, epsilonMeters) {
        if (points.length < 3) {
            return points.slice();
        }
        let maxDist = 0;
        let maxIndex = 0;
        for (let i = 1; i < points.length - 1; i++) {
            const dist = perpendicularDistanceMeters(points[i], points[0], points[points.length - 1]);
            if (dist > maxDist) {
                maxDist = dist;
                maxIndex = i;
            }
        }
        if (maxDist > epsilonMeters) {
            const left = douglasPeucker(points.slice(0, maxIndex + 1), epsilonMeters);
            const right = douglasPeucker(points.slice(maxIndex), epsilonMeters);
            return left.slice(0, -1).concat(right);
        }
        return [points[0], points[points.length - 1]];
    }

    function perpendicularDistanceMeters(point, lineStart, lineEnd) {
        const mPerDegLat = 111320;
        const mPerDegLng = 111320 * Math.cos(lineStart.lat * Math.PI / 180);
        const x = (point.lng - lineStart.lng) * mPerDegLng;
        const y = (point.lat - lineStart.lat) * mPerDegLat;
        const dx = (lineEnd.lng - lineStart.lng) * mPerDegLng;
        const dy = (lineEnd.lat - lineStart.lat) * mPerDegLat;
        const lengthSq = dx * dx + dy * dy;
        if (lengthSq === 0) {
            return Math.sqrt(x * x + y * y);
        }
        const t = Math.max(0, Math.min(1, (x * dx + y * dy) / lengthSq));
        const projX = t * dx;
        const projY = t * dy;
        return Math.sqrt((x - projX) * (x - projX) + (y - projY) * (y - projY));
    }

    // --- UI: badge ---------------------------------------------------------

    function updateRecordingBadge() {
        const badge = document.getElementById('trackRecordingBadge');
        if (!liveMeta || liveMeta.status === 'stopped') {
            badge.style.display = 'none';
            if (badgeTickTimer) {
                clearInterval(badgeTickTimer);
                badgeTickTimer = null;
            }
            return;
        }
        badge.style.display = 'flex';
        document.getElementById('trackRecordingBadgeStatus').textContent =
            liveMeta.status === 'paused' ? t('trackrecorder.badge_paused') : t('trackrecorder.badge_recording');
        badge.classList.toggle('track-recording-badge-paused', liveMeta.status === 'paused');
        tickBadge();
        if (!badgeTickTimer) {
            badgeTickTimer = setInterval(tickBadge, 1000);
        }
    }

    function tickBadge() {
        if (!liveMeta) {
            return;
        }
        const elapsedSeconds = Math.round((Date.now() - new Date(liveMeta.startedAt).getTime()) / 1000);
        document.getElementById('trackRecordingBadgeDuration').textContent = formatDuration(elapsedSeconds);
        document.getElementById('trackRecordingBadgeDistance').textContent = (liveDistanceMeters / 1000).toFixed(1) + ' km';
        positionRecordingBadge();
    }

    // #trackRecordingBadge normally sits at the same top offset as
    // #tourModeBadge (its default `top` in style.css) - the two are just
    // alternative "status pill under the search bar" badges and usually
    // only one shows at a time. Tour Mode is just a route filter though
    // (see tour.js), not an overlay, so a recording can still be running
    // while it's active - if #tourModeBadge is ALSO visible right now, this
    // one is pushed below its actual rendered height instead, the same
    // live-measurement approach showSecondToolbar() (ui.js) uses for
    // #secondToolbar's offset from #editToolbar, rather than a second
    // hardcoded top value that would silently drift if #tourModeBadge's own
    // height/padding/font-size ever changes. Called every tick (every 1s
    // while the badge is shown) so a Tour Mode toggle mid-recording is
    // picked up within a second, not just at the moment the badge first
    // appears.
    function positionRecordingBadge() {
        const badge = document.getElementById('trackRecordingBadge');
        const tourBadge = document.getElementById('tourModeBadge');
        if (!badge.offsetParent) {
            return;
        }
        if (tourBadge && getComputedStyle(tourBadge).display !== 'none') {
            const parentRect = badge.offsetParent.getBoundingClientRect();
            const tourBadgeRect = tourBadge.getBoundingClientRect();
            badge.style.top = (tourBadgeRect.bottom - parentRect.top + 8) + 'px';
        } else {
            badge.style.top = ''; // falls back to the default top in style.css
        }
    }

    function hideRecordingBadge() {
        document.getElementById('trackRecordingBadge').style.display = 'none';
        if (badgeTickTimer) {
            clearInterval(badgeTickTimer);
            badgeTickTimer = null;
        }
    }

    // One-time setup (called from initTrackRecorder()) for the badge's
    // press-and-hold: pointerdown arms a plain TRACK_BADGE_HOLD_MS timer
    // that fires openTrackRecorderScreen() itself, while the finger/pointer
    // may still be down - not on release, per the "lifting the finger
    // shouldn't be necessary" requirement. pointermove past a small
    // tolerance (the gesture turned into a drag, e.g. a map pan/zoom that
    // happened to start on the badge) cancels the pending timer silently.
    // A pointerup that still finds the timer pending, on the other hand, is
    // a plain tap - too short to have been a deliberate hold - so it shows
    // a one-off toast hinting at the hold instead of just doing nothing;
    // pointercancel/-leave stay silent (finger dragged off/gesture aborted,
    // not a "did nothing happen?" tap). Pointer Events (not separate
    // touch/mouse listeners) already used the same way for tour-admin.js's
    // route drag-reorder - unifies touch/mouse without map-core.js's
    // Maps-specific long-press fallbacks, which this plain DOM badge
    // (outside Maps' own event capture) doesn't need.
    function initTrackRecordingBadgePressHold() {
        const badge = document.getElementById('trackRecordingBadge');
        if (!badge) {
            return;
        }

        function clearHoldTimer() {
            if (badgeHoldTimer !== null) {
                clearTimeout(badgeHoldTimer);
                badgeHoldTimer = null;
            }
            badgeHoldStartPos = null;
        }

        badge.addEventListener('pointerdown', function (event) {
            clearHoldTimer();
            badgeHoldStartPos = { x: event.clientX, y: event.clientY };
            badgeHoldTimer = setTimeout(function () {
                badgeHoldTimer = null;
                badgeHoldStartPos = null;
                openTrackRecorderScreen();
            }, TRACK_BADGE_HOLD_MS);
        });

        badge.addEventListener('pointermove', function (event) {
            if (!badgeHoldStartPos) {
                return;
            }
            if (Math.abs(event.clientX - badgeHoldStartPos.x) > TRACK_BADGE_HOLD_MOVE_TOLERANCE_PX ||
                Math.abs(event.clientY - badgeHoldStartPos.y) > TRACK_BADGE_HOLD_MOVE_TOLERANCE_PX) {
                clearHoldTimer();
            }
        });

        badge.addEventListener('pointerup', function () {
            // badgeHoldTimer is still pending here exactly for a plain tap -
            // released before the hold fired, and never dragged past
            // TRACK_BADGE_HOLD_MOVE_TOLERANCE_PX (a drag/pan starting on the
            // badge already nulled it out via pointermove above, so that
            // case falls through without the hint).
            if (badgeHoldTimer !== null) {
                showToast(t('trackrecorder.badge_hold_hint'), 'info');
            }
            clearHoldTimer();
        });
        badge.addEventListener('pointercancel', clearHoldTimer);
        badge.addEventListener('pointerleave', clearHoldTimer);
    }

    // Called both from the "Track aufzeichnen" drawer row (drawer already
    // open at that point, same as the plain navMenuGoTo() calls app.php uses
    // for POIs/Preferences) and from #trackRecordingBadge on the main map
    // (drawer closed). openMenu() - not toggleMenu() - is correct for both:
    // it's idempotent if the drawer is already open, and actually opens it
    // otherwise. toggleMenu() used to be here and broke the drawer-row case
    // specifically: it saw the already-open drawer and called closeMenu()
    // instead, which is what surfaced as "menu opens and immediately closes
    // again" (todo.md).
    window.openTrackRecorderScreen = function () {
        openMenu();
        navMenuGoTo('record');
        renderTrackRecorderScreen();
        refreshPermissionStatus();
        // Covers "opened the app back in cell signal range" - the 'online'
        // event listener (initTrackRecorder()) already covers the
        // app-stays-open case, this one catches a cold start/resume
        // instead. Silent on failure, same reasoning as everywhere else
        // this is called with manual:false.
        syncPendingTracks({ manual: false });
    };

    // --- UI: drawer screen ---------------------------------------------

    function renderTrackRecorderScreen() {
        const body = document.getElementById('trackRecorderScreenBody');
        if (!body) {
            return;
        }
        if (!liveMeta || liveMeta.status === 'stopped') {
            body.innerHTML = renderIdleState();
        } else {
            body.innerHTML = renderActiveState();
        }
    }

    function renderIdleState() {
        // settings.trackDistanceFilter is persisted via the normal
        // settings-cookie mechanism (settings.js) - trackRecorderStartClicked()
        // writes it, here it's just read back to pre-select the right option.
        var preset = settings.trackDistanceFilter || 'battery';
        return '' +
            renderPermissionWarning() +
            '<p class="nav-field-label">' + t('trackrecorder.distance_filter_label') + '</p>' +
            '<form name="trackfilter">' +
                '<div class="nav-segmented nav-segmented-track-filter">' +
                    '<input type="radio" id="trackfilter1" name="trackfilterselector" value="precise"' + (preset === 'precise' ? ' checked' : '') + '><label for="trackfilter1">' + t('trackrecorder.filter_precise') + '<span class="nav-segmented-sublabel">' + TRACK_DISTANCE_FILTER_PRESETS.precise + ' m</span></label>' +
                    '<input type="radio" id="trackfilter2" name="trackfilterselector" value="balanced"' + (preset === 'balanced' ? ' checked' : '') + '><label for="trackfilter2">' + t('trackrecorder.filter_balanced') + '<span class="nav-segmented-sublabel">' + TRACK_DISTANCE_FILTER_PRESETS.balanced + ' m</span></label>' +
                    '<input type="radio" id="trackfilter3" name="trackfilterselector" value="battery"' + (preset === 'battery' ? ' checked' : '') + '><label for="trackfilter3">' + t('trackrecorder.filter_battery') + '<span class="nav-segmented-sublabel">' + TRACK_DISTANCE_FILTER_PRESETS.battery + ' m</span></label>' +
                '</div>' +
            '</form>' +
            '<p class="track-recorder-explanation">' + t('trackrecorder.idle_explanation') + '</p>' +
            // Same nav-btn-primary treatment as "Tour-Modus aktivieren"
            // (tour-admin.js) - just a different icon (fiber_manual_record,
            // matching the recording badge's own icon) instead of explore.
            '<button type="button" class="nav-btn-primary" onclick="trackRecorderStartClicked();"><i class="material-icons-round">fiber_manual_record</i>&nbsp;' + t('trackrecorder.start_button') + '</button>' +
            renderPendingTracksSection();
    }

    /**
     * Tracks already stopped+named (saveRecordedTrackLocally()) but not yet
     * confirmed on the server - shown right below the Start button so a
     * multi-leg trip (record leg 1, save locally, record leg 2, ...) always
     * shows what's still waiting, without leaving the recording screen.
     * Renders from pendingTracksCache (kept fresh by refreshPendingTracks())
     * rather than reading IndexedDB here, since this function itself must
     * stay synchronous - it's called from inside renderTrackRecorderScreen().
     */
    function renderPendingTracksSection() {
        if (pendingTracksCache.length === 0) {
            return '';
        }
        const rows = pendingTracksCache.map(function (entry) {
            const distanceKm = (entry.length / 1000).toFixed(2);
            const duration = formatDuration(entry.recording_duration_seconds || 0);
            return '' +
                '<div class="track-recorder-pending-row">' +
                    '<div class="track-recorder-pending-info">' +
                        '<span class="track-recorder-pending-name">' + escapeHTML(entry.name) + '</span>' +
                        '<span class="track-recorder-pending-meta">' + distanceKm + ' km &middot; ' + duration + '</span>' +
                    '</div>' +
                    '<i class="material-icons-round track-recorder-pending-upload" onclick="uploadPendingTrackClicked(\'' + entry.id + '\');" title="' + t('trackrecorder.pending_upload_button') + '">cloud_upload</i>' +
                    '<i class="material-icons-round track-recorder-pending-discard" onclick="discardPendingTrackClicked(\'' + entry.id + '\');" title="' + t('common.delete') + '">delete</i>' +
                '</div>';
        }).join('');
        const bulkButton = pendingTracksCache.length > 1
            ? '<button type="button" class="nav-btn-secondary" onclick="syncPendingTracks({manual:true});">' + t('trackrecorder.pending_upload_all_button') + '</button>'
            : '';
        return '' +
            '<p class="nav-field-label">' + t('trackrecorder.pending_section_title', { count: pendingTracksCache.length }) + '</p>' +
            '<div class="track-recorder-pending-list">' + rows + '</div>' +
            bulkButton;
    }

    function renderActiveState() {
        const elapsedSeconds = Math.round((Date.now() - new Date(liveMeta.startedAt).getTime()) / 1000);
        const isPaused = liveMeta.status === 'paused';
        return '' +
            '<div class="track-recorder-live-stats">' +
                '<div><span class="track-recorder-live-value">' + formatDuration(elapsedSeconds) + '</span><span class="track-recorder-live-label">' + t('trackrecorder.stat_duration') + '</span></div>' +
                '<div><span class="track-recorder-live-value">' + (liveDistanceMeters / 1000).toFixed(2) + ' km</span><span class="track-recorder-live-label">' + t('trackrecorder.stat_distance') + '</span></div>' +
                '<div><span class="track-recorder-live-value">' + livePointCount + '</span><span class="track-recorder-live-label">' + t('trackrecorder.stat_points') + '</span></div>' +
            '</div>' +
            (isPaused
                ? '<button type="button" class="button" onclick="resumeRecording();"><i class="material-icons-round">play_arrow</i>&nbsp;' + t('trackrecorder.resume_button') + '</button>'
                : '<button type="button" class="button" onclick="pauseRecording();"><i class="material-icons-round">pause</i>&nbsp;' + t('trackrecorder.pause_button') + '</button>') +
            // A single-point track has no distance/duration between points to
            // review - stopRecording() would hand saveRecordedTrackLocally() a
            // 1-point track it can't sensibly draw as a line, so this stays
            // disabled until there's at least a two-point segment. Pause and
            // Verwerfen have no such requirement, they stay enabled from the
            // first point onward.
            '<button type="button" class="button" onclick="stopRecording();"' + (livePointCount < 2 ? ' disabled' : '') + '><i class="material-icons-round">stop_circle</i>&nbsp;' + t('trackrecorder.stop_button') + '</button>' +
            '<button type="button" class="button button-danger" onclick="discardRecording();"><i class="material-icons-round">delete</i>&nbsp;' + t('trackrecorder.discard_button') + '</button>';
    }

    window.trackRecorderStartClicked = function () {
        const checked = document.querySelector('input[name="trackfilterselector"]:checked');
        const preset = checked ? checked.value : 'battery';
        settings.trackDistanceFilter = preset;
        saveSettings();

        if (!CapacitorBridge.checkLocationPermissionStatus) {
            startRecording(preset);
            return;
        }
        // Re-checked live rather than trusting lastPermissionStatus - it may
        // be stale (e.g. the idle screen was left open while the user went
        // to Settings and came back without triggering a visibilitychange
        // the listener caught in time).
        CapacitorBridge.checkLocationPermissionStatus().then(function (status) {
            lastPermissionStatus = status;
            if (status && !status.allGranted) {
                showToast(t('trackrecorder.permission_blocked_toast'), 'warning');
                renderTrackRecorderScreen();
                return;
            }
            startRecording(preset);
        });
    };

    // Exposed for the onclick="" handlers in renderActiveState() above.
    window.pauseRecording = pauseRecording;
    window.resumeRecording = resumeRecording;
    window.stopRecording = stopRecording;
    window.discardRecording = discardRecording;

    // Covers "tapped Open Settings, granted the permission, pressed back" -
    // the WebView isn't destroyed by that round trip, so the idle screen
    // (if still the active nav screen) can just be silently refreshed
    // rather than making the user close and reopen it to see it update.
    function initPermissionStatusRefreshOnResume() {
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible') {
                return;
            }
            const recordScreen = navMenuScreenEl('record');
            if (recordScreen && recordScreen.classList.contains('nav-screen-active')) {
                refreshPermissionStatus();
            }
        });
    }

    function initTrackRecorder() {
        const row = document.getElementById('trackRecorderMenuRow');
        if (row) {
            row.style.display = '';
        }
        initTrackRecordingBadgePressHold();
        initPermissionStatusRefreshOnResume();
        loadInProgressRecording();
        refreshPendingTracks();
        // Covers the "still has the app open, connectivity comes back on
        // its own" case (e.g. paddled back into range of a cell tower) -
        // openTrackRecorderScreen() and saveRecordedTrackLocally() already
        // cover the other two triggers (opening/reopening the screen,
        // finishing a new recording). Silent on failure, same as those.
        window.addEventListener('online', function () {
            syncPendingTracks({ manual: false });
        });
    }

    window.initTrackRecorder = initTrackRecorder;
})();
