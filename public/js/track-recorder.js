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
    // preference (settings.js).
    const TRACK_DISTANCE_FILTER_PRESETS = { precise: 20, balanced: 50, battery: 100 };
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
    const RECORDING_DB_VERSION = 1;
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
    let badgeTickTimer = null;
    let badgeHoldTimer = null;
    let badgeHoldStartPos = null;

    // --- IndexedDB buffer -----------------------------------------------

    function openRecordingDb() {
        if (db) {
            return Promise.resolve(db);
        }
        return new Promise(function (resolve, reject) {
            const request = indexedDB.open(RECORDING_DB_NAME, RECORDING_DB_VERSION);
            request.onupgradeneeded = function () {
                const upgradeDb = request.result;
                const points = upgradeDb.createObjectStore('points', { keyPath: 'seq', autoIncrement: true });
                points.createIndex('recordingId', 'recordingId');
                upgradeDb.createObjectStore('meta', { keyPath: 'id' });
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
                tx.objectStore('meta').delete(CURRENT_META_ID);
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
     * measureTool.start(points) + showRouteEditWindow()) rather than
     * duplicating the save logic.
     *
     * Login is checked HERE, before touching measureTool at all:
     * showRouteEditWindow() itself calls cancelEditRoute() -> measureTool.end()
     * when logged out, which would silently discard whatever was just fed
     * into measureTool - verified in route.js while planning this feature.
     * The recording stays intact in IndexedDB either way; only a
     * successful save clears it.
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
        document.getElementById('trackRecordingBadgeStats').textContent =
            formatDuration(elapsedSeconds) + ' · ' + (liveDistanceMeters / 1000).toFixed(1) + ' km';
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
    // shouldn't be necessary" requirement. pointerup/-cancel/-leave (finger
    // lifted or dragged off the badge before the timer fired - e.g. a plain
    // tap, or the start of a map pan/zoom that began on the badge) and
    // pointermove past a small tolerance (the gesture turned into a drag,
    // not a hold) both cancel the pending timer so it can't fire late.
    // Pointer Events (not separate touch/mouse listeners) already used the
    // same way for tour-admin.js's route drag-reorder - unifies touch/mouse
    // without map-core.js's Maps-specific long-press fallbacks, which this
    // plain DOM badge (outside Maps' own event capture) doesn't need.
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

        badge.addEventListener('pointerup', clearHoldTimer);
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
            '<p class="nav-field-label">' + t('trackrecorder.distance_filter_label') + '</p>' +
            '<form name="trackfilter">' +
                '<div class="nav-segmented nav-segmented-track-filter">' +
                    '<input type="radio" id="trackfilter1" name="trackfilterselector" value="precise"' + (preset === 'precise' ? ' checked' : '') + '><label for="trackfilter1">' + t('trackrecorder.filter_precise') + '<span class="nav-segmented-sublabel">' + TRACK_DISTANCE_FILTER_PRESETS.precise + ' m</span></label>' +
                    '<input type="radio" id="trackfilter2" name="trackfilterselector" value="balanced"' + (preset === 'balanced' ? ' checked' : '') + '><label for="trackfilter2">' + t('trackrecorder.filter_balanced') + '<span class="nav-segmented-sublabel">' + TRACK_DISTANCE_FILTER_PRESETS.balanced + ' m</span></label>' +
                    '<input type="radio" id="trackfilter3" name="trackfilterselector" value="battery"' + (preset === 'battery' ? ' checked' : '') + '><label for="trackfilter3">' + t('trackrecorder.filter_battery') + '<span class="nav-segmented-sublabel">' + TRACK_DISTANCE_FILTER_PRESETS.battery + ' m</span></label>' +
                '</div>' +
            '</form>' +
            '<p class="track-recorder-explanation">' + t('trackrecorder.idle_explanation') + '</p>' +
            '<button type="button" class="button track-recorder-start-btn" onclick="trackRecorderStartClicked();">' + t('trackrecorder.start_button') + '</button>';
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
                ? '<button type="button" class="button" onclick="resumeRecording();">' + t('trackrecorder.resume_button') + '</button>'
                : '<button type="button" class="button" onclick="pauseRecording();">' + t('trackrecorder.pause_button') + '</button>') +
            '<button type="button" class="button" onclick="stopRecording();">' + t('trackrecorder.stop_button') + '</button>' +
            '<button type="button" class="button button-danger" onclick="discardRecording();">' + t('trackrecorder.discard_button') + '</button>';
    }

    window.trackRecorderStartClicked = function () {
        const checked = document.querySelector('input[name="trackfilterselector"]:checked');
        const preset = checked ? checked.value : 'battery';
        settings.trackDistanceFilter = preset;
        saveSettings();
        startRecording(preset);
    };

    // Exposed for the onclick="" handlers in renderActiveState() above.
    window.pauseRecording = pauseRecording;
    window.resumeRecording = resumeRecording;
    window.stopRecording = stopRecording;
    window.discardRecording = discardRecording;

    /**
     * Called by route.js's saveRoute() right after a successful POST
     * /routes that included this module's recording metadata - only now,
     * with the save actually confirmed, is it safe to drop the local
     * IndexedDB copy. Re-reads the current meta rather than relying on any
     * closure state, since this app only ever tracks one recording at a
     * time - keeps this hook a single, self-contained statement.
     */
    window.onRecordedRouteSaved = function () {
        getMeta().then(function (meta) {
            if (meta) {
                clearRecording(meta.recordingId);
            }
        });
    };

    function initTrackRecorder() {
        const row = document.getElementById('trackRecorderMenuRow');
        if (row) {
            row.style.display = '';
        }
        initTrackRecordingBadgePressHold();
        loadInProgressRecording();
    }

    window.initTrackRecorder = initTrackRecorder;
})();
