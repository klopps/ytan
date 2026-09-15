/**
 * Route creation, editing, rendering and distance labels.
 */

// Set by track-recorder.js right before it hands a finished GPS recording
// into this file's own showRouteEditWindow()/saveRoute() flow - see
// saveRoute() below. Always null for a normal, manually-drawn route.
let pendingRouteRecordingMeta = null;

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
        document.getElementById('editRouteSaveBtn').disabled = false;
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
 * Speichert eine Route in der Datenbank
 */
function saveRoute(i) {
    if (!(validateRouteEditForm() == true)) {
        return false;
    }

    if (user.id === null) {
        return false;
    }

    var id = null;
    var publicState = 0;

    if ((i !== null) && (typeof i !== 'undefined')) {
        id = routes[i].id;
        publicState = routes[i].public;
        hideRoute(i);
    } else {
        hideRoute(i);
    }

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
        id: id,
        user_id: user.id,
        name: escapeHTML(document.getElementById('editRouteName').value),
        description: escapeHTML(document.getElementById('editRouteDescription').value),
        public: publicState,
        length: measureTool.length,
        points: JSON.stringify(points),
        color: document.getElementById('editRouteColor').value
    }

    // Set by track-recorder.js immediately before showRouteEditWindow(),
    // only for a just-finished GPS recording - cleared unconditionally
    // right after reading it so a later, unrelated manual edit never
    // accidentally reuses stale recording metadata. Snapshotted into
    // wasRecordedRoute first so the success handler below still knows
    // (after the variable itself is already cleared) whether to tell
    // track-recorder.js to drop its local IndexedDB copy.
    var wasRecordedRoute = pendingRouteRecordingMeta !== null;
    if (pendingRouteRecordingMeta !== null) {
        routeData.recorded_at = pendingRouteRecordingMeta.recorded_at;
        routeData.recording_duration_seconds = pendingRouteRecordingMeta.recording_duration_seconds;
        pendingRouteRecordingMeta = null;
    }

    log('saveRoute(' + i + ')', LOG_INFO, routeData);

    var request = (id === null)
        ? Ytan.post('/routes', routeData)
        : Ytan.put('/routes/' + id, routeData);

    request.then(answer => {
        log('saveRoute() success', LOG_INFO, answer);

        if (wasRecordedRoute && typeof window.onRecordedRouteSaved === 'function') {
            window.onRecordedRouteSaved();
        }

        measureTool.end();
        routeData.points = JSON.parse(routeData.points);
        var index = i;

        if (index == null) {
            log('INSERT into array routes[]', LOG_INFO);
            routeData.id = answer.data.id;
            index = routes.push(routeData) - 1;
            log('new index: ' + index, LOG_INFO);

            if (activeTourModeId !== null) {
                addNewRouteToActiveTour(routeData.id);
            }
        } else {
            routes[index] = routeData;
            log('UPDATE array routes[' + i +']', LOG_INFO, routes[index]);
        }

        createRoute(index);

        if (settings.detailroutes === false) {
            document.getElementById('detailroutes').checked = true;
            settings.detailroutes = true;
            saveSettings();
            showRoutes();
        }
    }).catch(err => {
        log('saveRoute() failed', LOG_ERROR, err);
        showToast(t('route.save_failed', { error: err.message }), 'error');
    });

    measureTool.index = null;
    closeRouteEditWindow();

    document.getElementById('routeButton').classList.remove('active');

    return true;
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

    hideRouteLabels(i);

    log('removeRoute(' + i + ')', LOG_INFO, id);

    var path = '/routes/' + id;

    try {
        await Ytan.del(path);
    } catch (err) {
        // A route that's part of >=1 tours 422s with a captcha challenge
        // instead of deleting immediately (Touren.md's "warn + simple
        // captcha before deleting a tour route" flow) - solve it and retry
        // the same DELETE with the answer attached.
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
    google.maps.event.clearInstanceListeners(routePaths[i].routePathLine);
    google.maps.event.clearInstanceListeners(routePaths[i].routePathBackground);
    routePaths[i].routePathLine.setMap(null);
    routePaths[i].routePathBackground.setMap(null);
    delete routePaths[i];
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
    }).catch(err => log('getRoutesByUserId() failed', LOG_ERROR, err));
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
    }).catch(err => log('getPublicRoutes() failed', LOG_ERROR, err));
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
        attachLongPressContextMenu(routePathLine, function(event) {
            showRouteContextMenu.call(routePathLine, event, i);
        });
        attachLongPressContextMenu(routePathBackground, function(event) {
            showRouteContextMenu.call(routePathBackground, event, i);
        });

        routePaths[i] = {
            routePathLine: routePathLine,
            routePathBackground: routePathBackground
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
 * Auto-attaches a just-created route to the active Tour Mode tour
 * (tour.js's activeTourModeId/activeTourModeName) - saveRoute() calls this
 * right after a successful POST /routes, never on an edit (PUT) of an
 * existing route.
 */
function addNewRouteToActiveTour(routeId) {
    Ytan.post('/tours/' + activeTourModeId + '/routes', { route_id: routeId }).then(() => {
        showToast(t('route.added_to_active_tour', { tour: activeTourModeName }), 'success');
    }).catch(err => showToast(t('route.active_tour_add_failed', { error: err.message }), 'error'));
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
