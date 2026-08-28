/**
 * Route creation, editing, rendering and distance labels.
 */

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

    log('showRouteEditWindow(' + i + ')', LOG_DEFAULT);

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
            '<h3>Route</h3>' +
            '<div class="leftCol">' +
                '<label for="editRouteName">Name: </label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editRouteName" type="text" oninput="validateRouteEditForm();" placeholder="Enter name of Route ..." title="Enter at least 3 characters" value="' + route.name + '">' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<label for="editRouteDescription">Description:</label><br>' +
            '<textarea id="editRouteDescription" rows="5" oninput="validateRouteEditForm();"placeholder="Enter detailed description of route ...">' + route.description + '</textarea>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editRouteColor">Color: </label>' +
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
            '<label for="editRouteInvert"><span></span>invert route</label>' +
        '</div>';
    }

    if (user.is_admin === true) {
        content +=
        '<div class="infoWindowElement">' +
            '<input type="checkbox" id="editRouteStatus" name="editRouteStatus"';
        if (route.public == 1) content += ' checked';
        content += '>' +
            '<label for="editRouteStatus"><span></span>Public (visible for everyone)</label>' +
        '</div>';
    }

    content +=
        '<div class="infoWindowElement">' +
            '<button id="editRouteSaveBtn" class="button" onClick="saveRoute(' + i + ')" disabled>Save</button>&nbsp;';

    if (((i !== null) && (typeof i !== 'undefined')) && (routes[i].user_id == user.id)) {
        content = content +
            '<button id="editRouteRemoveBtn" class="button" onClick="removeRoute(' + i + ')">Remove</button>&nbsp;';
    }

    content += '<button class="button" onClick="cancelEditRoute(' + i + ')">Cancel</button>' +
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

    log('saveRoute(' + i + ')', LOG_DEFAULT, routeData);

    var request = (id === null)
        ? Ytan.post('/routes', routeData)
        : Ytan.put('/routes/' + id, routeData);

    request.then(answer => {
        log('saveRoute() success', LOG_DEFAULT, answer);

        measureTool.end();
        routeData.points = JSON.parse(routeData.points);
        var index = i;

        if (index == null) {
            log('INSERT into array routes[]', LOG_DEFAULT);
            routeData.id = answer.data.id;
            index = routes.push(routeData) - 1;
            log('new index: ' + index, LOG_DEFAULT);
        } else {
            routes[index] = routeData;
            log('UPDATE array routes[' + i +']', LOG_DEFAULT, routes[index]);
        }

        createRoute(index);

        if (settings.detailroutes === false) {
            document.getElementById('detailroutes').checked = true;
            settings.detailroutes = true;
            saveSettings();
            showRoutes();
        }

        setSessionTimeout(SESSION_TIMEOUT_SECONDS);
    }).catch(err => {
        log('saveRoute() failed: ' + err.message, LOG_DEFAULT);
        alert('Saving the route failed: ' + err.message);
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
function removeRoute(i) {
    if (typeof routes[i] === null || typeof routes[i] === 'undefined') {
        log('removeRoute(' + i + '): index not found.', LOG_DEFAULT);
        return false;
    }

    if (confirm('Do you really want to delete this Route?') == false) {
        return false;
    }

    var id = routes[i].id;

    hideRouteLabels(i);

    log('removeRoute(' + i + ')', LOG_DEFAULT, id);

    Ytan.del('/routes/' + id).then(() => {
        log('removeRoute() success', LOG_DEFAULT);
        measureTool.index = null;
        measureTool.end();
        hideRoute(i);
        routes.splice(i, 1);
        document.getElementById('routeButton').classList.remove('active');
        routeEditWindow.close();
    }).catch(err => {
        log('removeRoute() failed: ' + err.message, LOG_DEFAULT);
        alert('Removing the route failed: ' + err.message);
    }).finally(() => {
        setSessionTimeout(SESSION_TIMEOUT_SECONDS);
    });
}

/**
 * Hole alle Routen eines Users und speichere sie in routes[] und zeige sie an
 *
 * @param {number} userId
 */
function getRoutesByUserId(userId) {
    Ytan.get('/routes?scope=mine_public').then(answer => {
        routes = answer.data;
        log('getRoutesByUserId(' + userId + ')', LOG_DEFAULT, answer);
        for (let i = 0; i < routes.length; i++) {
            routes[i].points = JSON.parse(routes[i].points);
            routes[i].labels = [];
        }
        createRoutes();
        if (settings.detailroutes) {
            showRoutes();
        }
    }).catch(err => log('getRoutesByUserId() failed: ' + err.message, LOG_DEFAULT));
}

/**
 * Hole alle öffentlichen Routen speichere sie in routes[] und zeige sie an
 */
function getPublicRoutes() {
    Ytan.get('/routes?scope=public').then(answer => {
        log('getPublicRoutes()', LOG_DEFAULT, answer);
        routes = answer.data;
        for (let i = 0; i < routes.length; i++) {
            routes[i].points = JSON.parse(routes[i].points);
            routes[i].labels = [];
        }
        createRoutes();
        if (settings.detailroutes) {
            showRoutes();
        }
    }).catch(err => log('getPublicRoutes() failed: ' + err.message, LOG_DEFAULT));
}

/**
 * Hole alle Routen einer Tour, speichere sie in routes[] und zeige sie an
 *
 * @param {number} tourId
 */
function getRoutesByTourId(tourId) {
    Ytan.get('/routes?tour_id=' + encodeURIComponent(tourId)).then(answer => {
        routes = answer.data;
        log('getRoutesByTourId(' + tourId + ')', LOG_DEFAULT, answer);
        for (let i = 0; i < routes.length; i++) {
            routes[i].points = JSON.parse(routes[i].points);
            routes[i].labels = [];
        }
        createRoutes();
        if (settings.detailroutes) {
            showRoutes();
            fitToRouteBounds();
        }
    }).catch(err => log('getRoutesByTourId() failed: ' + err.message, LOG_DEFAULT));
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
 * Erzeugt das Polygon für eine Route
 *
 * @param {integer} i Index im Array routes[]
 */
function createRoute(i) {
    if (typeof routes[i] !== 'undefined') {
        var path = routes[i].points;

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
                showRouteLabels.call(this, i);
            }
        );

        google.maps.event.addListener(routePathBackground, 'click',
            function(event) {
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

            var content =
                '<div class="contextMenuItem" onClick="routeContextMenuEditRoute(' + i + ', null, null);">Edit route</div>' +
                '<div class="contextMenuItem" onClick="routeContextMenuEditInfo(' + i + ');">Edit Info</div>' +
                '<div class="contextMenuItem" onClick="routeContextMenuRemoveRoute(' + i + ');">Delete route</div>' +
                '<div class="contextMenuItem" onClick="closeContextMenu();">Cancel</div>'
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
        '<div class="infoWindowElement">Length: ' + length + ' km</div>' +
        '<div class="infoWindowElement">' + marked.parse(routes[i]['description']) + '<div>';

    if (user.id !== null) {
        if (routes[i].user_id == user.id) {
            content +=
            '<div class="infoWindowBottom">' +
                '<div class="lefthalf">&nbsp;</div>' +
                '<div class="routeEdit"><i class="material-icons-round" onClick="editRoute(' + i + ', ' + event.latLng.lat() + ', ' + event.latLng.lng() +');">edit</i></div>' +
            '</div>';
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

function editRoute(i, lat, lng) {
    if (routeInfoWindow.isOpen) {
        closeRouteInfoWindow(i);
    }
    hideRoute(i);
    log('editRoute(' + i + ')', LOG_DEFAULT, routes[i]);

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

    var lat, lng, r, p;

    for (r = 0; r < routes.length; r++) {
        if (typeof routes[r] !== "undefined") {
            for (p = 0; p < routes[r].points.length; p++) {
                lat = routes[r].points[p].lat;
                lng = routes[r].points[p].lng;

                if (lat < minLat) minLat = lat;
                if (lng < minLng) minLng = lng;
                if (lat > maxLat) maxLat = lat;
                if (lng > maxLng) maxLng = lng;
            }
        }
    }

    log('SW:' + minLat + ', ' + minLng + '    NE:' + maxLat + ', ' + maxLng);

    var bounds = new google.maps.LatLngBounds(
        new google.maps.LatLng(minLat, minLng),
        new google.maps.LatLng(maxLat, maxLng)
    );
    map.fitBounds(bounds);
}
