/**
 * POI markers, WSI markers, portages, POI CRUD and info/edit windows.
 */

function showMarkerByPoiId(id) {
    for (let i = 0; i < markers.length; i++) {
        if (typeof markers[i] !== "undefined") {
            if (markers[i].id == id) {
                markers.setMap(map);
            }
        }
    }
}

function showWsiMarkerByPoiId(id) {
    for (let i = 0; i < wsiMarkers.length; i++) {
        if (typeof wsiMarkers[i] !== "undefined") {
            if (wsiMarkers[i].id == id) {
                wsiMarkers[i].setMap(map);
            }
        }
    }
}

function addWsiMarker(id, location, direction) {
    const marker = new google.maps.Marker({
        position: location,
        icon: Ytan.wsiImageUrl(direction),
        id: id
    });
    wsiMarkers.push(marker);
}

function removeWsiMarkerById(id) {
    for (let i = 0; i < wsiMarkers.length; i++) {
        if (typeof wsiMarkers[i] !== "undefined") {
            if (id === wsiMarkers[i].id) {
                wsiMarkers[i].setMap(null);
                delete wsiMarkers[i];
                break;
            }
        }
    }
}

function removeMarkerById(id) {
    markers[id].setMap(null);
    poiInfoWindows.splice(id, 1);
    delete markers[id];
}

function deleteMarkers() {
    clearMarkers();
    markers = [];
}

function deleteWsiMarkerById(id) {
    for (let i = 0; i < wsiMarkers.length; i++) {
        if (wsiMarkers[i].id === id) {
            wsiMarkers[i].setMap(null);
            wsiMarkers.splice(i, 1);
        }
    }
}

function deleteWsiMarkers() {
    clearWsiMarkers();
    wsiMarkers = [];
}

function deletePortages() {
    clearPortages();
    portagePaths = [];
}


/**
 * Lade alle POIs (nur für Admins sinnvoll, siehe scope=all der API)
 */
function getPois() {
    Ytan.get('/pois?scope=all').then(answer => {
        pois = answer.data;
        setAllPois(pois);
    }).catch(err => log('getPois() failed', LOG_ERROR, err));
}

/**
 * Lade die öffentliches POIs aus der Datenbank
 */
function getPublicPois() {
    Ytan.get('/pois?scope=public').then(answer => {
        log("getPublicPois()", LOG_INFO, answer);
        pois = answer.data;
        setAllPois(pois);
    }).catch(err => log('getPublicPois() failed', LOG_ERROR, err));
}

/**
 * Lade die eigenen und öffentlichen POIs aus der Datenbank
 */
function getPoisByUserId(userId) {
    Ytan.get('/pois?scope=mine_public').then(answer => {
        log("getPoisByUserId()", LOG_INFO, answer);
        pois = answer.data;
        setAllPois(pois);
    }).catch(err => log('getPoisByUserId() failed', LOG_ERROR, err));
}

/**
 * Zoome die Karte, so dass alle POIs sichtbar sind
 */
function fitToPoiBounds() {
    Ytan.get('/pois/bounds').then(answer => {
        var arrBounds = answer.data;
        var bounds = new google.maps.LatLngBounds(
            new google.maps.LatLng(arrBounds['min_lat'], arrBounds['min_lng']), // Südwest
            new google.maps.LatLng(arrBounds['max_lat'], arrBounds['max_lng']) // Nordost
        );
        map.fitBounds(bounds);
    }).catch(err => log('fitToPoiBounds() failed', LOG_ERROR, err));
}

/**
 * Liefert die Koordinaten des Zentrums der POIs
 */
function centerMapToBounds() {
    Ytan.get('/pois/bounds').then(answer => {
        var arrBounds = answer.data;
        var bounds = new google.maps.LatLngBounds(
            new google.maps.LatLng(arrBounds['min_lat'], arrBounds['min_lng']),
            new google.maps.LatLng(arrBounds['max_lat'], arrBounds['max_lng'])
        );
        map.setCenter(bounds.getCenter());
    }).catch(err => log('centerMapToBounds() failed', LOG_ERROR, err));
}

/**
 * Switches between individual POI markers and grouped count indicators
 * depending on the current zoom level (POI_CLUSTER_ZOOM_THRESHOLD in
 * config.js). Re-run whenever the zoom level changes, whenever the POI set
 * is (re)loaded, and whenever a "Show POIs" type checkbox is toggled.
 */
function updatePoiClustering() {
    var projection = map.getProjection();
    if (!projection) {
        // Not ready yet (can happen very briefly during initial map load) - retry shortly.
        setTimeout(updatePoiClustering, 200);
        return;
    }

    clearPoiClusterMarkers();

    if (map.getZoom() < POI_CLUSTER_ZOOM_THRESHOLD) {
        setMapOnAllMarkers(null);
        showPoiClusters(projection);
    } else {
        applyPoiTypeVisibility();
    }
}

/**
 * Restores each POI marker's visibility from the "Show POIs" checkboxes
 * (settings.detail<poitype_id>), independently of what clustering last did.
 */
function applyPoiTypeVisibility() {
    for (let i = 0; i < pois.length; i++) {
        if (typeof pois[i] === "undefined") {
            continue;
        }

        var marker = markers[pois[i].id];
        if (marker) {
            marker.setMap(settings["detail" + pois[i].poitype_id] ? map : null);
        }
    }
}

/**
 * Groups POIs (whose type checkbox is currently enabled) by on-screen
 * proximity at the current zoom level, and shows one count indicator per
 * group instead of individual markers.
 *
 * This is a two-step grid clustering: POIs are first bucketed into a fixed
 * pixel grid, then adjacent buckets whose centroids still end up close
 * enough on screen to visually overlap (a point near a cell edge can land
 * only a few pixels from a point in the next cell) are merged. Without
 * that second step, one bubble can end up fully covering another, hiding
 * its count entirely - which is why, before this fix, clusters appeared to
 * only ever show counts for whichever type's bubble happened to be drawn
 * last (in practice mostly "Lights", since those POIs are spread evenly
 * along the whole coastline and so very often land in a cell adjacent to a
 * bigger, different-type cluster).
 *
 * @param {google.maps.Projection} projection
 */
function showPoiClusters(projection) {
    var scale = Math.pow(2, map.getZoom());
    var cellPx = 60; // cluster grid cell size in CSS pixels, roughly constant on screen
    var buckets = {};

    for (let i = 0; i < pois.length; i++) {
        if (typeof pois[i] === "undefined") {
            continue;
        }
        if (!settings["detail" + pois[i].poitype_id]) {
            continue;
        }

        var lat = parseFloat(pois[i].latitude);
        var lng = parseFloat(pois[i].longitude);
        var point = projection.fromLatLngToPoint(new google.maps.LatLng(lat, lng));
        var px = point.x * scale;
        var py = point.y * scale;
        var cx = Math.floor(px / cellPx);
        var cy = Math.floor(py / cellPx);
        var key = cx + '_' + cy;

        if (!buckets[key]) {
            buckets[key] = {
                cx: cx, cy: cy, count: 0,
                sumLat: 0, sumLng: 0, sumPx: 0, sumPy: 0,
                minLat: lat, maxLat: lat, minLng: lng, maxLng: lng,
                soloPoiId: null
            };
        }
        var bucket = buckets[key];
        bucket.count++;
        bucket.soloPoiId = (bucket.count === 1) ? pois[i].id : null;
        bucket.sumLat += lat;
        bucket.sumLng += lng;
        bucket.sumPx += px;
        bucket.sumPy += py;
        bucket.minLat = Math.min(bucket.minLat, lat);
        bucket.maxLat = Math.max(bucket.maxLat, lat);
        bucket.minLng = Math.min(bucket.minLng, lng);
        bucket.maxLng = Math.max(bucket.maxLng, lng);
    }

    Object.keys(buckets).forEach(function (key) {
        mergeCloseNeighborBuckets(buckets, key, cellPx);
    });

    var groups = {};
    Object.keys(buckets).forEach(function (key) {
        var root = findClusterRoot(buckets, key);
        var bucket = buckets[key];
        if (!groups[root]) {
            groups[root] = {
                count: 0, sumLat: 0, sumLng: 0,
                minLat: bucket.minLat, maxLat: bucket.maxLat,
                minLng: bucket.minLng, maxLng: bucket.maxLng,
                soloPoiId: null
            };
        }
        var group = groups[root];
        // Only the first bucket merged into this group can still leave it
        // representing a single POI - as soon as a second bucket (or a
        // bucket that already holds more than one POI) joins in, fall back
        // to the count-bubble.
        group.soloPoiId = (group.count === 0) ? bucket.soloPoiId : null;
        group.count += bucket.count;
        group.sumLat += bucket.sumLat;
        group.sumLng += bucket.sumLng;
        group.minLat = Math.min(group.minLat, bucket.minLat);
        group.maxLat = Math.max(group.maxLat, bucket.maxLat);
        group.minLng = Math.min(group.minLng, bucket.minLng);
        group.maxLng = Math.max(group.maxLng, bucket.maxLng);
    });

    Object.keys(groups).forEach(function (key) {
        var group = groups[key];

        // A group representing just one POI is shown as that POI's own
        // marker (with its actual icon and click behavior) instead of a
        // count bubble - a "cluster of one" isn't a cluster.
        if (group.count === 1 && group.soloPoiId !== null && markers[group.soloPoiId]) {
            markers[group.soloPoiId].setMap(map);
            return;
        }

        var bounds = new google.maps.LatLngBounds(
            new google.maps.LatLng(group.minLat, group.minLng),
            new google.maps.LatLng(group.maxLat, group.maxLng)
        );
        addPoiClusterMarker(
            new google.maps.LatLng(group.sumLat / group.count, group.sumLng / group.count),
            group.count,
            bounds
        );
    });
}

/**
 * Union-find over grid bucket keys, used to merge buckets whose combined
 * centroid ends up close enough on screen to overlap another bucket's.
 * Buckets carry their own `parent` pointer (initialized lazily below).
 */
function findClusterRoot(buckets, key) {
    var bucket = buckets[key];
    if (!bucket.parent) {
        bucket.parent = key;
    }
    if (bucket.parent !== key) {
        bucket.parent = findClusterRoot(buckets, bucket.parent);
    }
    return bucket.parent;
}

function unionClusterBuckets(buckets, keyA, keyB) {
    var rootA = findClusterRoot(buckets, keyA);
    var rootB = findClusterRoot(buckets, keyB);
    if (rootA !== rootB) {
        buckets[rootA].parent = rootB;
    }
}

/**
 * Merges bucket `key` with any of its 8 grid neighbors whose centroid is
 * still within `mergeDistancePx` on screen AND within
 * POI_CLUSTER_MAX_MERGE_DISTANCE_METERS in the real world - the latter
 * keeps e.g. two separate touring regions on opposite sides of the map
 * from silently being combined into one indicator just because they
 * happen to render close together on screen at a very low zoom level.
 */
function mergeCloseNeighborBuckets(buckets, key, mergeDistancePx) {
    var bucket = buckets[key];
    var bcx = bucket.sumPx / bucket.count;
    var bcy = bucket.sumPy / bucket.count;
    var bLatLng = { lat: bucket.sumLat / bucket.count, lng: bucket.sumLng / bucket.count };

    for (var dx = -1; dx <= 1; dx++) {
        for (var dy = -1; dy <= 1; dy++) {
            if (dx === 0 && dy === 0) {
                continue;
            }

            var neighborKey = (bucket.cx + dx) + '_' + (bucket.cy + dy);
            var neighbor = buckets[neighborKey];
            if (!neighbor) {
                continue;
            }

            var ncx = neighbor.sumPx / neighbor.count;
            var ncy = neighbor.sumPy / neighbor.count;
            var pixelDistance = Math.sqrt(Math.pow(bcx - ncx, 2) + Math.pow(bcy - ncy, 2));
            if (pixelDistance >= mergeDistancePx) {
                continue;
            }

            var nLatLng = { lat: neighbor.sumLat / neighbor.count, lng: neighbor.sumLng / neighbor.count };
            if (getDistance(bLatLng, nLatLng) > POI_CLUSTER_MAX_MERGE_DISTANCE_METERS) {
                continue;
            }

            unionClusterBuckets(buckets, key, neighborKey);
        }
    }
}

/**
 * Adds one count-indicator marker. Clicking it zooms/pans to fit the POIs
 * it represents, which will generally reveal them as individual markers.
 *
 * @param {google.maps.LatLng} position
 * @param {int} count
 * @param {google.maps.LatLngBounds} bounds
 */
function addPoiClusterMarker(position, count, bounds) {
    var size = count >= 50 ? 44 : (count >= 10 ? 38 : 32);

    var marker = new markerWithLabel.MarkerWithLabel({
        position: position,
        map: map,
        zIndex: ZINDEX_POI,
        clickable: true,
        icon: ' ', // no default pin - the label below is the whole visual
        labelContent: '<div class="poiClusterBubble" style="width:' + size + 'px;height:' + size + 'px;">' + count + '</div>',
        labelClass: 'poiClusterLabel',
        labelAnchor: new google.maps.Point(size / 2, size / 2),
    });

    google.maps.event.addListener(marker, 'click', function () {
        map.fitBounds(bounds);
    });

    poiClusterMarkers.push(marker);
}

function clearPoiClusterMarkers() {
    for (let i = 0; i < poiClusterMarkers.length; i++) {
        poiClusterMarkers[i].setMap(null);
    }
    poiClusterMarkers = [];
}

/**
 * Löscht alle Marker, setzt sie neu
 *
 * @param {*} arrPois
 */
function setAllPois(arrPois) {
    var i;

    if ((arrPois == null) || (typeof arrPois == "undefined")) {
        return;
    }

    deleteMarkers();

    for (i = 0; i < arrPois.length; i++) {
        if (typeof arrPois[i] !== "undefinded") {
            setPoi(i);
        }
    }

    updatePoiClustering();
}

/**
* Löscht einen POI und setzt ihn neu.
* Wird z.B. für Updates eines POIs benötigt.
*
* @param {int} i Index of POI on pois[]
*/
function setPoi(i) {

    if (i === null  || typeof i === 'undefined') {
        return false;
    }

    deleteWsiMarkerById(pois[i].id);
    hideSectorLight(i);

    var LatLng = new google.maps.LatLng(pois[i].latitude, pois[i].longitude);

    var marker = new google.maps.Marker({
        position: LatLng,
        title: pois[i].name,
        icon: {
            url: "markers/poi_" + pois[i].poitype_id + "_" + ICONSET + ".png",
        },
        zIndex: ZINDEX_POI
    });

    // Für Leuchttürme wird die Mitte des Markers auf die Postion gesetzt
    if (pois[i].poitype_id == 14) {
        var markerOffsetX = 17;
        var markerOffsetY = 17;
        marker.icon.anchor = new google.maps.Point(markerOffsetX, markerOffsetY);
    }

    if (settings["detail"+pois[i].poitype_id]) {
        marker.setMap(map);
    }

    google.maps.event.addListener(marker, 'contextmenu',
        function(event) {
            showPoiContextMenu.call(this, event, i);
        }
    );

    // Fallback for touch devices where the browser doesn't translate a
    // long-press into the 'contextmenu' event above (map-core.js).
    attachLongPressContextMenu(marker, function(event) {
        showPoiContextMenu.call(marker, event, i);
    });

    google.maps.event.addListener(marker, 'click',
        function(event) {
            if (shouldSuppressClick()) {
                return;
            }
            showPoiInfoWindow.call(this, event, i, marker);
        }
    );

    google.maps.event.addListener(marker, 'dblclick', zoomToMaxAndPan);

    // WSI
    if ((pois[i].poitype_id == '1') || (pois[i].poitype_id == '11')) {
        if (pois[i].direction !== null) {
            addWsiMarker(pois[i].id, LatLng, pois[i].direction);
        }
    }

    // Portage
    if (pois[i].poitype_id == '9') {
        if (pois[i].portage_path) {
            addPortage(pois[i].id, pois[i].portage_path);
        }
    }

    markers[pois[i].id] = marker;

    if (settings.detailwsi === true) {
        showWsiMarkerByPoiId(pois[i].id);
    }
}

function showPoiInfoWindow(event, i, marker) {
    if (typeof(poiInfoWindows[i]) == "undefined") {

        var content = "";

        poiInfoWindows[i] = new google.maps.InfoWindow();

        if ((pois[i].poitype_id == '1') || (pois[i].poitype_id == '11')) {
            if (pois[i].direction !== null) {
                content += '<img class="wsi" src="' + Ytan.wsiImageUrl(pois[i].direction) + '">';
            }
        }

        content += '<h3>' + pois[i].name + '</h3>';
        content +=  marked.parse(pois[i].description);
        content += '<div class="infoWindowCoordinates"><i class="material-icons-round">navigation</i>' + formatCoordinates(Number.parseFloat(pois[i].latitude), Number.parseFloat(pois[i].longitude)) + '</div>';

        // Lighthouse
        if (pois[i].poitype_id == '14') {
            if (pois[i].characteristic !== null) {
                content += '<div class="InfoWindowElement"><p class="LighthouseCharacteristic">' + pois[i].characteristic + '</p></div>';

                var lighthouseId = "lighthouse" +  pois[i].id;
                content += '<div class="InfoWindowLighthouseContainer" onClick="switchSectorLight(' + i + ')">';
                content += '<div id="' + lighthouseId + '" class="InfoWindowLighthouseCharacteristic"></div>';
                content += '</div>';

                poiInfoWindows[i].addListener('domready', function() {
                    light.animateElement(pois[i].characteristic, lighthouseId);
                });
            }
        }

        content += '<div class="infoWindowBottom">';
        if (pois[i].url !== '') {
            content += '<div class="lefthalf"><a href="' + pois[i].url + '" target="_blank"><i class="material-icons-round">info</i>&nbsp;' + t('common.info') + '</a></div>';
        }

        if ((pois[i].user_id == user.id) || user.is_admin) {
            content += '<div class="poiEdit"><i class="material-icons-round" onClick="editPoi(' + i + ');">edit</i></div>';
        }
        content += '</div>';

        poiInfoWindows[i].addListener('closeclick', function() {
            delete poiInfoWindows[i];
        });

        poiInfoWindows[i].setContent(content);
        poiInfoWindows[i].open(map, marker);
    } else {
        closePoiInfoWindow(i);
    }
}

function closePoiInfoWindow(i) {
    // editPoi() calls this unconditionally, but it's also reachable via the
    // right-click context menu's "Edit" item, which never opens the POI's
    // InfoWindow first - poiInfoWindows[i] is only ever set once that
    // InfoWindow has actually been opened (see setPoi() above).
    if (typeof poiInfoWindows[i] === 'undefined') {
        return;
    }
    poiInfoWindows[i].close();
    delete poiInfoWindows[i];
}

function removeAllPoiInfowWindows() {
    for (const poiInfoWindow of poiInfoWindows) {
        poiInfoWindow.close();
    }
    poiInfoWindows = [];
}

function showPoiContextMenu(event, i) {
    log('showPoiContextMenu(event, ' + i +')', LOG_DEBUG);

    // Same handler the generic empty-map-point context menu uses
    // (weather.js's openWeatherTimelineForLocation()) - available for
    // every POI regardless of type/ownership, since weather data is
    // public and isn't tied to editing rights. contextMenuLastLatLng
    // (map-core.js) is the established way to hand a coordinate from
    // here to an onclick string, which runs later in global scope and
    // can't see this function's own `event` closure - same pattern
    // route.js's showRouteContextMenu() already uses.
    contextMenuLastLatLng = event.latLng;
    var content = '<div class="contextMenuItem" onClick="poiContextMenuShowWeather();"><i class="material-icons-round">cloud</i>' + t('weather.context_menu.item') + '</div>';

    if (pois[i].poitype_id == 14) {
        log('show contextMenu(Sector Light Switch)', LOG_DEBUG);
        if (pois[i].sector_characteristic != null) {
            if (pois[i].sector_characteristic.trim() != "") {
                var sectorLightVisible;
                var sectorLightCommand;
                if (typeof(sectorLights[i]) != "undefined") {
                    sectorLightVisible = false;
                    sectorLightCommand = t('poi.context.hide_sector_light');
                } else {
                    sectorLightVisible = true;
                    sectorLightCommand = t('poi.context.show_sector_light');
                }
                content = '<div class="contextMenuItem" onClick="poiContextMenuSwitchSectorLight(' + i + ', ' + sectorLightVisible + ');"><i class="material-icons-round">lightbulb_outline</i>' + sectorLightCommand + '</div>';
            }
        }
    }

    if (user.id !== null) {
        if ((pois[i].user_id == user.id) || (user.is_admin === true)) {
            log('show contextMenu (Edit)', LOG_DEBUG);

            content +=
                '<div class="contextMenuItem" onClick="poiContextMenuEditPoi(' + i + ', null, null);"><i class="material-icons-round">edit</i>' + t('common.edit') + '</div>' +
                '<div class="contextMenuItem" onClick="poiContextMenuRemovePoi(' + i + ');"><i class="material-icons-round">delete</i>' + t('common.delete') + '</div>';
        }
    }

    if (content != '') {
        content += '<div class="contextMenuItem" onClick="closeContextMenu();"><i class="material-icons-round">close</i>' + t('common.cancel') + '</div>';

        contextMenu.setPosition(event.latLng);
        contextMenu.setContent(content);
        contextMenu.open(map);
    }
}

function poiContextMenuShowWeather() {
    closeContextMenu();
    openWeatherTimelineForLocation(contextMenuLastLatLng);
}

function poiContextMenuSwitchSectorLight(i, sectorLightVisible) {
    closeContextMenu();
    if (sectorLightVisible == true) {
        showSectorLight(i);
    } else {
        hideSectorLight(i);
    }
}

function poiContextMenuEditPoi(i) {
    closeContextMenu();
    editPoi(i);
}

function poiContextMenuRemovePoi(i) {
    closeContextMenu();
    removePoi(i);
}

/**
 * Erzeugt die Polylines zur Darstellung der Portagen
 *
 * @param {int} poi_id
 * @param {string} coordinates
 */
function addPortage(poi_id, coordinates) {
    var portageCoordinates = [];
    var arrCoordinates = coordinates.split(";");

    if (arrCoordinates.length > 0) {
        for (let i = 0; i < arrCoordinates.length; i++) {
            var point = new google.maps.LatLng(arrCoordinates[i].split(',')[1], arrCoordinates[i].split(',')[0]);
            portageCoordinates.push(point);
        }

        var portagePathBackground = new google.maps.Polyline({
            id: poi_id,
            path: portageCoordinates,
            geodesic: true,
            strokeColor: '#FFFFFF',
            strokeOpacity: 0.7,
            strokeWeight: 10,
            clickable: false
        });

        var portagePathLine = new google.maps.Polyline({
            id: poi_id,
            path: portageCoordinates,
            geodesic: true,
            strokeColor: '#FF0000',
            strokeOpacity: 1.0,
            strokeWeight: 4
        });

        if (settings["detail9"]) {
            portagePathBackground.setMap(map);
            portagePathLine.setMap(map);
        }
        portagePaths.push(portagePathBackground);
        portagePaths.push(portagePathLine);
    }
}


function editPoiBtnClick(elementId) {
    var element = document.getElementById(elementId);

    if (element.classList.contains('disabled')) {
        log('editPoiBtnClick is disabled', LOG_DEBUG);
        return;
    }

    if (user.id === null) {
        log('editPoiBtnClick: user not logged on', LOG_DEBUG);
        disablePoiButton();
        return;
    }

    if (element.classList.contains('active')) {
        google.maps.event.removeListener(mapClickListener);
        map.setOptions({draggableCursor:''});
        enableAreaButton();
        enablePoiButton();
        enableRouteButton();
        editMode(false);
        hideSecondToolbar();
    } else {
        element.classList.add('active');
        mapClickListener = google.maps.event.addListener(map, "click", (event) => {
            addPoi(event.latLng, null);
        });
        map.setOptions({draggableCursor:'crosshair'});
        disableAreaButton();
        disableRouteButton();
        editMode(true);
        showSecondToolbar(elementId);
    }
}

/**
 * Erzeugt das Fenster zum Bearbeiten eines POI.
 * @param {int} i Index des POI im Array pois[]
 */
function initPoiEditWindow(i) {

    var poi = {};
    var styleWsi = ' style="display:none"';
    var styleLighthouse = ' style="display:none"';

    var selected = [];
    for (let x=0; x<=16; x++) {
        selected[x] = '';
    }

    if ((i !== null) && (typeof i !== 'undefined')) {
        poi = pois[i];
        selected[poi.poitype_id] = ' selected';
        if ((poi.poitype_id == 1) || (poi.poitype_id == 11)) {
            styleWsi = '';
        }
        if (poi.poitype_id == 14) {
            styleLighthouse = '';
            if (poi.sector_characteristic == null) {
                poi.sector_characteristic = '';
            }
        }
        poiMarker = markers[i];
    } else {
        poi.name = '';
        poi.poitype_id = 0;
        poi.description = '';
        poi.characteristic = '';
        poi.sector_characteristic = '';
        poi.url = '';
        poi.direction = '';
        poi.public = 0;
        selected[poi.poitype_id] = ' selected';
    }

    var content =
        '<div class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editPoiName">' + t('poi.edit.name_label') + '</label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editPoiName" type="text" oninput="validatePoiEditForm();" placeholder="' + t('poi.edit.name_placeholder') + '" title="' + t('poi.edit.name_title') + '" value="' + poi.name + '">' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editPoiType">' + t('poi.edit.type_label') + '</label>&nbsp;' +
            '</div>' +
            '<div class="rightCol">' +
                '<select id="editPoiType" class="select-css" onChange="changePoiType(event); validatePoiEditForm();" placeholder="' + t('poi.edit.type_placeholder') + '">' +
                    '<option value="0"'  + selected[0]  + '>' + t('poi.edit.type_choose') + '</option>' +
                    '<option value="1"'  + selected[1]  + '>' + t('poi.type.1') + '</option>' +
                    '<option value="10"' + selected[10] + '>' + t('poi.type.10') + '</option>' +
                    '<option value="11"' + selected[11] + '>' + t('poi.type.11') + '</option>' +
                    '<option value="2"'  + selected[2]  + '>' + t('poi.type.2') + '</option>' +
                    '<option value="3"'  + selected[3]  + '>' + t('poi.type.3') + '</option>' +
                    '<option value="4"'  + selected[4]  + '>' + t('poi.type.4') + '</option>' +
                    '<option value="5"'  + selected[5]  + '>' + t('poi.type.5') + '</option>' +
                    '<option value="6"'  + selected[6]  + '>' + t('poi.type.6') + '</option>' +
                    '<option value="7"'  + selected[7]  + '>' + t('poi.type.7') + '</option>' +
                    '<option value="8"'  + selected[8]  + '>' + t('poi.type.8') + '</option>' +
                    '<option value="12"'  + selected[12]  + '>' + t('poi.type.12') + '</option>' +
                    '<option value="13"'  + selected[13]  + '>' + t('poi.type.13') + '</option>' +
                    '<option value="14"'  + selected[14]  + '>' + t('poi.type.14') + '</option>' +
                    '<option value="15"'  + selected[15]  + '>' + t('poi.type.15') + '</option>' +
                    '<option value="16"'  + selected[16]  + '>' + t('poi.type.16') + '</option>' +
                '</select>' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<label for="editPoiDescription">' + t('poi.edit.description_label') + '</label><br>' +
            '<textarea id="editPoiDescription" rows="5"  oninput="validatePoiEditForm();"placeholder="' + t('poi.edit.description_placeholder') + '">' + poi.description + '</textarea>' +
        '</div>' +
        '<div id="infoWindowElement" class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editPoiUrl">' + t('poi.edit.url_label') + '</label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editPoiURL" type="text" maxlength="300" oninput="validatePoiEditForm();" placeholder="https://www.mypoi.tld" title="' + t('poi.edit.url_title') + '" value="' + poi.url + '">' +
            '</div>' +
        '</div>' +
        '<div id="editPoiWSIContainer" class="infoWindowElement"' + styleWsi +'>' +
            '<div class="infoWindowElement">' +
                '<label for="editPoiWSI">' + t('poi.edit.wsi_label') + '</label>' +
                '<p class="wsi-instructions">' + t('poi.edit.wsi_text_instructions') + '</p>' +
            '</div>' +
            '<input id="editPoiWSI" type="text" maxlength="16" oninput="validatePoiEditForm();" placeholder="0011222222221100" title="' + t('poi.edit.wsi_title') + '" value="' + poi.direction + '">' +
            '<button type="button" class="button wsi-editor-btn" onclick="openWsiEditorModal();"><i class="material-icons-round">explore</i>' + t('poi.edit.wsi_editor_button') + '</button>' +
        '</div>' +
        '<div id="editPoiLighthouseContainer" class="infoWindowElement"' + styleLighthouse +'>' +
            '<div class="leftCol">' +
                '<label for="editPoiLighthouseCharacteristic">' + t('poi.edit.characteristic_label') + ' </label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editPoiLighthouseCharacteristic" type="text" maxlength="50" oninput="validatePoiEditForm();" placeholder="Iso WRG 6s" title="' + t('poi.edit.characteristic_title') + '" value="' + poi.characteristic + '">' +
            '</div>' +
            '<div class="infoWindowElement">' +
                '<label for="editPoiLighthouseSectorCharacteristic">' + t('poi.edit.sector_characteristic_label') + ' </label>' +
            '</div>' +
            '<div class="infoWindowElement">' +
                '<textarea id="editPoiLighthouseSectorCharacteristic" rows="6" maxlength="250" oninput="validatePoiEditForm();" placeholder="G 8M 30-110&#10;W 10M 110-195&#10;R 8M 195-275" title="' + t('poi.edit.sector_characteristic_title') + '">' + poi.sector_characteristic + '</textarea>' +
            '</div>' +
        '</div>'+
    '</div>';

    // Photos: only for an already-existing POI (needs an id first, same
    // rule tour-admin.js's photo grid follows for tours) - initPhotoUpload()
    // kicks off a background fetch of the POI's existing photos, so the
    // grid below starts empty and repaints once that resolves (see
    // photo-upload.js's doc comment for why POI edit windows can't just
    // wait on that fetch before opening, the way tour-admin.js's edit form
    // does).
    if ((i !== null) && (typeof i !== 'undefined')) {
        initPhotoUpload('pois', poi.id, POI_PHOTO_MAX_COUNT);
        content +=
            '<div class="infoWindowElement">' +
                '<label>' + t('poi.edit.photos_label') + '</label>' +
                '<div id="' + PHOTO_UPLOAD_CONTAINER_ID + '">' + photoUploadGridHtml() + '</div>' +
            '</div>';
    } else {
        // No photo section shown for a brand-new POI (needs an id first) -
        // reset rather than leave a previous POI's still-running
        // compression able to block this unrelated create form's Save.
        resetPhotoUpload();
    }

    if (user.is_admin === true) {
        content +=
        '<div class="infoWindowElement">' +
            '<input type="checkbox" id="editPoiStatus" name="editPoiStatus"';
        if (poi.public == 1) content += ' checked';
        content += '>' +
            '<label for="editPoiStatus"><span></span>' + t('poi.edit.public_label') + '</label>' +
        '</div>';
    }
    content +=
        '<div class="infoWindowElement">' +
            '<button id="editPoiSaveBtn" class="button" onClick="savePoi(' + i + ')" disabled>' + t('common.save') + '</button>&nbsp;';

    if ((i !== null) && (typeof i !== 'undefined')) {
        content += '<button id="editPoiRemoveBtn" class="button" onClick="removePoi(' + i + ')">' + t('common.remove') + '</button>&nbsp;';
    }
    content += '<button class="button" onClick="cancelEditPoi(' + i +')">' + t('common.cancel') + '</button>' +
        '</div>';

    content += '<div id="poiCoordinates" class="poiEditWindowCoordinates"><i class="material-icons-round">navigation</i>' + formatCoordinates(Number.parseFloat(poi.latitude), Number.parseFloat(poi.longitude)) + '</div>';

    poiEditWindow.setContent(content);

    google.maps.event.addListener(poiEditWindow, 'closeclick', function(i, event) {
        cancelEditPoi(i);
    }.bind(poiEditWindow, i));

    poiEditWindow.addListener('position_changed', function () {
        var pos = poiEditWindow.getPosition();
        document.getElementById('poiCoordinates').innerHTML = '<i class="material-icons-round">navigation</i>' + formatCoordinates(pos.lat(), pos.lng());
    });
}

// --- WSI (Wind Shelter Indicator) graphical dial editor ---------------------
//
// Replaces hand-typing a 16-char 0/1/2 string with a clickable 16-sector
// compass dial (index 0 = North, clockwise, 22.5°/sector - matches
// WsiRenderer.php's server-side rendering exactly, see that file for the
// same -90°-offset angle convention and the 0=exposed/1=partial/2=sheltered
// semantics). #editPoiWSI itself stays a real, always-present
// <input type="text"> (just hidden by default) - it remains the single
// source of truth validatePoiEditForm()/savePoi() already read/write
// unchanged; this dial only ever reads/writes that same element's .value,
// never introduces a parallel state.

const WSI_SECTOR_COUNT = 16;
const WSI_DIAL_CENTER = 110;
const WSI_DIAL_INNER_RADIUS = 46;
const WSI_DIAL_OUTER_RADIUS = 96;
// Deliberately larger than WSI_DIAL_OUTER_RADIUS - the invisible hit-test
// sectors (wsiDescribeHitSector()) reach all the way in to the center point
// instead of just covering the visible ring, since a thin 16-way ring alone
// gives too small a touch target (see wsiDescribeHitSector()'s own comment).
const WSI_DIAL_HIT_RADIUS = 100;

/**
 * Same -90°-offset convention as WsiRenderer::polarToCartesian() (PHP) -
 * angle 0 points north/up, increasing angle goes clockwise on screen.
 */
function wsiPolarToCartesian(cx, cy, r, angleDeg) {
    var angleRad = (angleDeg - 90) * Math.PI / 180;
    return { x: cx + r * Math.cos(angleRad), y: cy + r * Math.sin(angleRad) };
}

/**
 * SVG path for one donut-shaped (annular) sector between two radii/angles.
 * The two arcs' sweep-flags are NOT interchangeable: the outer arc is drawn
 * forward (angle1->angle2, clockwise on screen) so it needs sweep-flag=1;
 * the inner arc is the return leg (angle2->angle1, i.e. backwards) so it
 * needs sweep-flag=0. Swapping them silently produces a self-intersecting
 * bowtie instead of a clean wedge. large-arc-flag is always 0 - a single
 * 22.5° sector is always the minor arc.
 */
function wsiDescribeAnnularSector(cx, cy, rInner, rOuter, angle1, angle2) {
    var outerStart = wsiPolarToCartesian(cx, cy, rOuter, angle1);
    var outerEnd = wsiPolarToCartesian(cx, cy, rOuter, angle2);
    var innerStart = wsiPolarToCartesian(cx, cy, rInner, angle1);
    var innerEnd = wsiPolarToCartesian(cx, cy, rInner, angle2);
    return 'M ' + innerStart.x + ' ' + innerStart.y +
        ' L ' + outerStart.x + ' ' + outerStart.y +
        ' A ' + rOuter + ' ' + rOuter + ' 0 0 1 ' + outerEnd.x + ' ' + outerEnd.y +
        ' L ' + innerEnd.x + ' ' + innerEnd.y +
        ' A ' + rInner + ' ' + rInner + ' 0 0 0 ' + innerStart.x + ' ' + innerStart.y +
        ' Z';
}

/**
 * SVG path for a sector's full pie-shaped (center-to-r) hit-test area -
 * deliberately larger/simpler than the visible donut ring so the effective
 * tap target's tightest dimension is the arc length at WSI_DIAL_HIT_RADIUS
 * (~39px) rather than the much narrower one the visible ring's own inner
 * edge alone would give (~18px at WSI_DIAL_INNER_RADIUS) - with a 16-way
 * split, that difference is the gap between "usually taps the right sector"
 * and "frequently taps the neighbor". Routing every hit path through the
 * same center point also means there's no rounding-error hairline gap
 * between neighboring sectors the way 16 independently-computed inner-ring
 * edges could in theory leave.
 */
function wsiDescribeHitSector(cx, cy, r, angle1, angle2) {
    var start = wsiPolarToCartesian(cx, cy, r, angle1);
    var end = wsiPolarToCartesian(cx, cy, r, angle2);
    return 'M ' + cx + ' ' + cy +
        ' L ' + start.x + ' ' + start.y +
        ' A ' + r + ' ' + r + ' 0 0 1 ' + end.x + ' ' + end.y +
        ' Z';
}

/**
 * Builds the 32 <path> elements (16 invisible hit-test sectors, painted
 * first so they never cover the visible ring, + 16 visible donut-ring
 * wedges) for the given 16-char WSI code. Falls back to an all-"0" dial for
 * anything that doesn't match the same ^[012]{16}$ the rest of the app
 * already validates against - a brand-new POI's poi.direction is '', a POI
 * without a windshelter row is null, and #editPoiWSI (always a real,
 * user-editable text field now - see openWsiEditorModal()) can hold a
 * temporarily-invalid value while the user is mid-edit. Purely a rendering
 * helper - never writes back into #editPoiWSI itself, so an in-progress
 * invalid text value is never silently overwritten.
 */
function renderWsiDialPaths(code) {
    if (!/^[012]{16}$/.test(code)) {
        code = '0'.repeat(WSI_SECTOR_COUNT);
    }
    var svg = '';
    for (var i = 0; i < WSI_SECTOR_COUNT; i++) {
        var angle1 = i * (360 / WSI_SECTOR_COUNT);
        var angle2 = (i + 1) * (360 / WSI_SECTOR_COUNT);
        var value = code.charAt(i);
        svg += '<path class="wsi-hit" d="' + wsiDescribeHitSector(WSI_DIAL_CENTER, WSI_DIAL_CENTER, WSI_DIAL_HIT_RADIUS, angle1, angle2) + '" onclick="wsiDialSectorClick(' + i + ');"></path>';
        svg += '<path id="wsiSector' + i + '" class="wsi-sector wsi-sector-' + value + '" d="' + wsiDescribeAnnularSector(WSI_DIAL_CENTER, WSI_DIAL_CENTER, WSI_DIAL_INNER_RADIUS, WSI_DIAL_OUTER_RADIUS, angle1, angle2) + '"></path>';
    }
    return svg;
}

// Set while the WSI editor modal (openWsiEditorModal() below) is open;
// null otherwise. wsiDialSectorClick() mutates this working copy instead of
// #editPoiWSI.value directly, so Cancel/Escape/backdrop-click can discard
// whatever was clicked without touching the real field - only "Apply"
// commits it. The modal's dial is rebuilt fresh from #editPoiWSI's current
// value every time it opens, so it always starts in sync with the text
// field (including any manual edit made there since the modal last closed).
let wsiModalWorkingCode = null;

/**
 * Cycles one sector 0->1->2->0 in wsiModalWorkingCode and updates just that
 * one <path>'s class in the open modal - only ever called from inside it
 * (the click handlers renderWsiDialPaths() embeds), so a no-op guard
 * against wsiModalWorkingCode being null covers the (should be impossible)
 * case of a stray call with no modal open.
 */
function wsiDialSectorClick(index) {
    if (wsiModalWorkingCode === null) {
        return;
    }
    var next = (parseInt(wsiModalWorkingCode.charAt(index), 10) + 1) % 3;
    wsiModalWorkingCode = wsiModalWorkingCode.substring(0, index) + next + wsiModalWorkingCode.substring(index + 1);

    var sector = document.getElementById('wsiSector' + index);
    if (sector) {
        sector.setAttribute('class', 'wsi-sector wsi-sector-' + next);
    }
}

/**
 * Opens the graphical WSI dial as a modal overlay (same overlay/dialog
 * shell showConfirmDialog()/showCaptchaDialog() use in confirm-dialog.js -
 * built via document.createElement rather than an HTML string, since this
 * needs live DOM node references for its own click handlers, and appended
 * to document.body so it sits above the POI edit InfoWindow, which has far
 * less width to work with than a centered full-viewport modal needs for a
 * comfortable 16-sector dial). #editPoiWSI (the always-visible text field)
 * is only written to if the user taps "Apply" - Cancel, Escape, or clicking
 * the backdrop all discard wsiModalWorkingCode instead.
 */
function openWsiEditorModal() {
    var input = document.getElementById('editPoiWSI');
    wsiModalWorkingCode = /^[012]{16}$/.test(input.value) ? input.value : '0'.repeat(WSI_SECTOR_COUNT);

    var overlay = document.createElement('div');
    overlay.className = 'confirm-dialog-overlay';

    var modal = document.createElement('div');
    modal.className = 'wsi-modal';

    var title = document.createElement('div');
    title.className = 'wsi-modal-title';
    title.textContent = t('poi.edit.wsi_editor_title');

    var dialWrapper = document.createElement('div');
    dialWrapper.innerHTML = '<svg viewBox="0 0 220 220" class="wsi-dial">' + renderWsiDialPaths(wsiModalWorkingCode) + '</svg>';
    var dial = dialWrapper.firstElementChild;

    var legend = document.createElement('div');
    legend.className = 'wsi-legend';
    legend.innerHTML =
        '<span class="wsi-legend-item"><span class="wsi-legend-swatch wsi-legend-0"></span>' + t('poi.edit.wsi_legend_0') + '</span>' +
        '<span class="wsi-legend-item"><span class="wsi-legend-swatch wsi-legend-1"></span>' + t('poi.edit.wsi_legend_1') + '</span>' +
        '<span class="wsi-legend-item"><span class="wsi-legend-swatch wsi-legend-2"></span>' + t('poi.edit.wsi_legend_2') + '</span>';

    var instructions = document.createElement('p');
    instructions.className = 'wsi-instructions';
    instructions.textContent = t('poi.edit.wsi_instructions');

    var actions = document.createElement('div');
    actions.className = 'confirm-dialog-actions';

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'button';
    cancelBtn.textContent = t('common.cancel');

    var applyBtn = document.createElement('button');
    applyBtn.type = 'button';
    applyBtn.className = 'startbtn';
    applyBtn.textContent = t('poi.edit.wsi_editor_apply');

    function close() {
        document.removeEventListener('keydown', onKeydown);
        overlay.remove();
        wsiModalWorkingCode = null;
    }

    function apply() {
        input.value = wsiModalWorkingCode;
        validatePoiEditForm();
        close();
    }

    function onKeydown(event) {
        if (event.key === 'Escape') {
            close();
        }
    }

    cancelBtn.addEventListener('click', close);
    applyBtn.addEventListener('click', apply);
    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
            close();
        }
    });

    actions.appendChild(cancelBtn);
    actions.appendChild(applyBtn);

    modal.appendChild(title);
    modal.appendChild(dial);
    modal.appendChild(legend);
    modal.appendChild(instructions);
    modal.appendChild(actions);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    document.addEventListener('keydown', onKeydown);
}

/**
 * Called once from map-core.js's initMap(). Registers this feature's
 * "Create POI" item in the generic map context menu instead of map-core.js
 * needing to know anything about POIs - see that file's own comment on
 * registerMapContextMenuItem() for why (same pattern weather.js already
 * uses for its own items).
 */
function initPoiMapContextMenu() {
    registerMapContextMenuItem('add_location', 'poi.context_menu.create_item', mapContextMenuCreatePoi);
}

/**
 * Handler for the "Create POI" map-context-menu item - starts POI creation
 * directly at the right-clicked/long-pressed point (addPoi() below), unlike
 * the toolbar's poiButton flow which needs a second click on the map to
 * place it. Silently no-ops when logged out, same as editPoiBtnClick()'s
 * existing guard for the toolbar button.
 */
function mapContextMenuCreatePoi(latLng) {
    if (user.id === null) {
        log('mapContextMenuCreatePoi: user not logged on', LOG_DEBUG);
        return;
    }
    addPoi(latLng);
}

/**
 * Hinzufügen eine POIs
 *
 * @param pos {} latLng
 */
function addPoi(pos) {
    disablePoiButton();
    closePoiEditWindow();
    initPoiEditWindow(null);

    map.panTo(pos);

    poiMarker = new google.maps.Marker({
        position: pos,
        map: map,
        draggable: true,
        type: 0,
        icon: "markers/poi_0_" + ICONSET + ".png",
        id: null
    });

    hideSecondToolbar();
    poiEditWindow.open(map, poiMarker);
}

/**
 * Ändern eines POIs
 *
 * @param i {int}
 */
function editPoi(i) {
    var poi = pois[i];
    var id = poi['id'];

    log('editPoi() called for: ' + i, LOG_INFO, pois[i]);

    disablePoiButton();
    closePoiEditWindow();
    closePoiInfoWindow(i);
    markers[id].setMap(null);

    initPoiEditWindow(i);

    poiMarker = new google.maps.Marker({
        position:  markers[id].position,
        map: map,
        draggable: true,
        type: 0,
        icon:  markers[id].icon,
        id: id
    });

    map.panTo(poiMarker.position);

    poiEditWindow.addListener('domready', function() {
        validatePoiEditForm();
    });

    hideSecondToolbar();
    poiEditWindow.open(map, poiMarker);
}

function cancelEditPoi(i) {
    hideSecondToolbar();

    if ((i !== null) && (typeof i !== 'undefined')) {
        markers[pois[i]['id']].setMap(map);
    }
    closePoiEditWindow();
    enableAreaButton();
    enablePoiButton();
    enableRouteButton();
    editMode(false);
}

function closePoiEditWindow() {
    if (typeof poiMarker !== 'undefined') {
        poiMarker.setMap(null);
    }

    if (poiEditWindow.isOpen) {
        poiEditWindow.close();
        enableAreaButton();
        enablePoiButton();
        enableRouteButton();
        editMode(false);
    }

    google.maps.event.clearListeners(poiEditWindow, 'position_changed');
}

function validatePoiEditForm() {
    var errors = 0;

    if (document.getElementById('editPoiName').value.length < 3) {
        errors = errors + 1;
    }

    if (document.getElementById('editPoiURL').value != '') {
        if (!/^https?:\/\/(www\.)?[-a-zA-Z0-9@:%._\+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_\+.~#?&//=]*)/gi.test(document.getElementById('editPoiURL').value)) {
            errors = errors + 2;
        }
    }

    if ((document.getElementById('editPoiType').value == '1') || (document.getElementById('editPoiType').value == 11)) {
        if (!/^[012]{16}$/.test(document.getElementById('editPoiWSI').value)) {
            errors = errors + 4;
        }
    }

    log('validation error: ' + errors, LOG_INFO);

    if (errors & 1) {
        document.getElementById('editPoiName').classList.add('inputError');
    } else {
        document.getElementById('editPoiName').classList.remove('inputError');
    }

    if (errors & 2) {
        document.getElementById('editPoiURL').classList.add('inputError');
    } else {
        document.getElementById('editPoiURL').classList.remove('inputError');
    }

    if (errors & 4) {
        document.getElementById('editPoiWSI').classList.add('inputError');
    } else {
        document.getElementById('editPoiWSI').classList.remove('inputError');
    }

    if (errors == 0) {
        document.getElementById('editPoiSaveBtn').disabled = false;
        return true;
    } else {
        document.getElementById('editPoiSaveBtn').disabled = true;
        return false;
    }
}

/**
 * Speichert einen POI
 */
function savePoi(i) {
    if (!(validatePoiEditForm() == true)) {
        return false;
    }
    // A photo still being compressed (see stagePhotoUpload()) isn't staged
    // for upload yet - saving now would close the window (see
    // closePoiEditWindow() below) without ever uploading it.
    if (photoUploadIsProcessing()) {
        showToast(t('photo_upload.still_processing'), 'warning');
        return false;
    }

    var id = null;
    var publicState = 0;
    if ((i !== null) && (typeof i !== 'undefined')) {
        id = pois[i].id;
        publicState = pois[i].public;
    }

    if (document.getElementById('editPoiStatus') !== null) {
        publicState = document.getElementById('editPoiStatus').checked ? 1 : 0;
    }

    var poiData = {
        id: id,
        user_id: user.id,
        poitype_id: parseInt(document.getElementById('editPoiType').value),
        name: escapeHTML(document.getElementById('editPoiName').value),
        description: escapeHTML(document.getElementById('editPoiDescription').value),
        url: document.getElementById('editPoiURL').value,
        latitude: poiMarker.getPosition().lat(),
        longitude: poiMarker.getPosition().lng(),
        direction: null,
        characteristic: null,
        sector_characteristic: null,
        public: publicState
    }

    if ((poiData.poitype_id === 1) || (poiData.poitype_id === 11)) {
        poiData.direction = document.getElementById('editPoiWSI').value;
    }

    if (poiData.poitype_id === 14) {
        poiData.characteristic = document.getElementById('editPoiLighthouseCharacteristic').value;
        poiData.sector_characteristic = escapeHTML(document.getElementById('editPoiLighthouseSectorCharacteristic').value);
    }

    log("savePoi()", LOG_INFO, poiData);

    var request = (id === null)
        ? Ytan.post('/pois', poiData)
        : Ytan.put('/pois/' + id, poiData);

    request.then(answer => {
        log('savePoi() success', LOG_INFO, answer);

        // Purely array/network-based (no DOM dependency), so this still
        // completes correctly even though closePoiEditWindow() below closes
        // the edit window synchronously, before this .then() ever runs.
        if (id !== null) {
            applyPendingPhotoUploadChanges('pois', id);
        }

        var index;
        if (i === null) {
            poiData.id = answer.data.id;
            index = pois.push(poiData) - 1;
            log('new index: ' + index, LOG_INFO);
        } else {
            index = i;
            pois[index] = poiData;
            log('UPDATE array pois[' + i + ']', LOG_INFO);
        }

        setPoi(index);
    }).catch(err => {
        log('savePoi() failed', LOG_ERROR, err);
        showToast(t('poi.save_failed', { error: err.message }), 'error');
    });

    closePoiEditWindow();
    enableAreaButton();
    enablePoiButton();
    enableRouteButton();
    editMode(false);

    return true;
}

/**
 * Löscht einen POI
 *
 * @param {integer} i Index des POI im Array pois[]
 * @returns
 */
async function removePoi(i) {
    if (!(await showConfirmDialog(t('poi.confirm_delete'), { type: 'danger', confirmLabel: t('common.delete') }))) {
        return false;
    }

    var id = pois[i].id;

    Ytan.del('/pois/' + id).then(() => {
        log('removePoi(' + i + ') success', LOG_INFO);

        removeMarkerById(pois[i].id);
        if ((pois[i].poitype_id === 1) || (pois[i].poitype_id === 11)) {
            removeWsiMarkerById(pois[i].id);
        }
        delete pois[i];
    }).catch(err => {
        log('removePoi() failed', LOG_ERROR, err);
        showToast(t('poi.remove_failed', { error: err.message }), 'error');
    });

    closePoiEditWindow();
    enablePoiButton();

    return true;
}

function changePoiType(event) {
    log('type changed. target.value: ' + event.target.value, LOG_DEBUG, event);

    if ((event.target.value == "1") || (event.target.value == "11")) {
        document.getElementById("editPoiWSIContainer").style.display = "block";
    } else {
        document.getElementById("editPoiWSIContainer").style.display = "none";
    }

    if (event.target.value == "14")  {
        document.getElementById("editPoiLighthouseContainer").style.display = "block";
    } else {
        document.getElementById("editPoiLighthouseContainer").style.display = "none";
    }

    poiMarker.setIcon("markers/poi_" + event.target.value + "_" + ICONSET + ".png")

    // Showing/hiding the WSI or Lighthouse section just now can make the
    // InfoWindow noticeably taller - it grows upward from its fixed anchor
    // at the marker (the normal InfoWindow layout: content sits above the
    // tail pointing at the marker), which can push its own top edge off the
    // top of the screen. Google only auto-pans the map to fit an InfoWindow
    // once, at open() time, based on whatever height it had then - it never
    // re-checks after content changes later, so this has to be done by hand.
    keepPoiEditWindowInView();
}

// How far below the viewport's top edge the InfoWindow must stay - clears
// the fixed hamburger/search-bar row (#sidemenu-toggle/.map-search-wrapper,
// top:16px + 40px tall) plus a little breathing room, matching the top:64px
// several other fixed map overlays already use for the same reason (e.g.
// #tourModeBadge).
const POI_EDIT_WINDOW_TOP_MARGIN_PX = 64;

/**
 * Pans the map down (map.panBy with a NEGATIVE y - panBy shifts the map's
 * center, so a negative y moves the center up, which is what makes
 * on-screen content, the marker and its InfoWindow included, shift DOWN)
 * just far enough that the currently-open POI edit InfoWindow's top edge
 * clears POI_EDIT_WINDOW_TOP_MARGIN_PX. No-ops if it's already far enough
 * down, or if it's not open at all (.gm-style-iw-c not found).
 */
function keepPoiEditWindowInView() {
    var bubble = document.querySelector('.gm-style-iw-c');
    if (!bubble) {
        return;
    }
    var top = bubble.getBoundingClientRect().top;
    if (top < POI_EDIT_WINDOW_TOP_MARGIN_PX) {
        map.panBy(0, top - POI_EDIT_WINDOW_TOP_MARGIN_PX);
    }
}


// Zeigt das Sektorlicht an, falls es nicht schon sichtbar ist
function showSectorLight(i) {
    if (typeof(sectorLights[i]) == "undefined") {
        var center = {
            lat: parseFloat(pois[i].latitude),
            lng: parseFloat(pois[i].longitude)
        };
        sectorLights[i] = new SectorLightOverlay(center, pois[i].characteristic, pois[i].sector_characteristic);
        sectorLights[i].setMap(map);
    }
}

// Entfernt das Sektorlicht
function hideSectorLight(i) {
    if (typeof(sectorLights[i]) == "object") {
        sectorLights[i].setMap(null);
        delete sectorLights[i];
    }
}

// Schaltet das Sektor Licht an und aus
function switchSectorLight(i) {
    if (typeof(sectorLights[i]) == "undefined") {
        showSectorLight(i);
    } else {
        hideSectorLight(i);
    }
}
