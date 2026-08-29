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
    }).catch(err => log('getPois() failed: ' + err.message, LOG_DEFAULT));
}

/**
 * Lade die öffentliches POIs aus der Datenbank
 */
function getPublicPois() {
    Ytan.get('/pois?scope=public').then(answer => {
        log("getPublicPois()", LOG_DEFAULT, answer);
        pois = answer.data;
        setAllPois(pois);
    }).catch(err => log('getPublicPois() failed: ' + err.message, LOG_DEFAULT));
}

/**
 * Lade die eigenen und öffentlichen POIs aus der Datenbank
 */
function getPoisByUserId(userId) {
    Ytan.get('/pois?scope=mine_public').then(answer => {
        log("getPoisByUserId()", LOG_DEFAULT, answer);
        pois = answer.data;
        setAllPois(pois);
    }).catch(err => log('getPoisByUserId() failed: ' + err.message, LOG_DEFAULT));
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
    }).catch(err => log('fitToPoiBounds() failed: ' + err.message, LOG_DEFAULT));
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
    }).catch(err => log('centerMapToBounds() failed: ' + err.message, LOG_DEFAULT));
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
        labelContent: '<div class="poiClusterBubble" style="width:' + size + 'px;height:' + size + 'px;line-height:' + size + 'px;">' + count + '</div>',
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

    google.maps.event.addListener(marker, 'click',
        function(event) {
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
        content += '<div class="infoWindowCoordinates"><i class="material-icons-round">navigation</i>' + Number.parseFloat(pois[i].latitude).toFixed(6) + ', ' + Number.parseFloat(pois[i].longitude).toFixed(6) + '</div>';
        content += '<div class="infoWindowCoordinates"><i class="material-icons-round">navigation</i>' + decimalLatLngToDMS(pois[i].latitude, pois[i].longitude) + '</div>';

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
            content += '<div class="lefthalf"><a href="' + pois[i].url + '" target="_blank"><i class="material-icons-round">info</i>&nbsp;Info</a></div>';
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

    var content = '';

    if (pois[i].poitype_id == 14) {
        log('show contextMenu(Sector Light Switch)', LOG_DEBUG);
        if (pois[i].sector_characteristic != null) {
            if (pois[i].sector_characteristic.trim() != "") {
                var sectorLightVisible;
                var sectorLightCommand;
                if (typeof(sectorLights[i]) != "undefined") {
                    sectorLightVisible = false;
                    sectorLightCommand = 'Hide';
                } else {
                    sectorLightVisible = true;
                    sectorLightCommand = 'Show';
                }
                content = '<div class="contextMenuItem" onClick="poiContextMenuSwitchSectorLight(' + i + ', ' + sectorLightVisible + ');">' + sectorLightCommand + ' Sector Light</div>';
            }
        }
    }

    if (user.id !== null) {
        if ((pois[i].user_id == user.id) || (user.is_admin === true)) {
            log('show contextMenu (Edit)', LOG_DEBUG);

            content +=
                '<div class="contextMenuItem" onClick="poiContextMenuEditPoi(' + i + ', null, null);">Edit</div>' +
                '<div class="contextMenuItem" onClick="poiContextMenuRemovePoi(' + i + ');">Delete</div>';
        }
    }

    if (content != '') {
        content += '<div class="contextMenuItem" onClick="closeContextMenu();">Cancel</div>';

        contextMenu.setPosition(event.latLng);
        contextMenu.setContent(content);
        contextMenu.open(map);
    }
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
        console.log('editPoiBtnClick is disabled');
        return;
    }

    if (user.id === null) {
        console.log('editPoiBtnClick: user not logged on');
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
    for (let x=0; x<=15; x++) {
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
                '<label for="editPoiName">Name: *</label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editPoiName" type="text" oninput="validatePoiEditForm();" placeholder="Enter name of POI ..." title="Enter at least 3 characters" value="' + poi.name + '">' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editPoiType">Type:</label>&nbsp;' +
            '</div>' +
            '<div class="rightCol">' +
                '<select id="editPoiType" class="select-css" onChange="changePoiType(event); validatePoiEditForm();" placeholder="select type ...">' +
                    '<option value="0"'  + selected[0]  + '>choose type ...</option>' +
                    '<option value="1"'  + selected[1]  + '>Camp</option>' +
                    '<option value="10"' + selected[10] + '>Shelter</option>' +
                    '<option value="11"' + selected[11] + '>Campsite (commercial)</option>' +
                    '<option value="2"'  + selected[2]  + '>Landing Site</option>' +
                    '<option value="3"'  + selected[3]  + '>Drinking Water</option>' +
                    '<option value="4"'  + selected[4]  + '>Toilet</option>' +
                    '<option value="5"'  + selected[5]  + '>Historic Site</option>' +
                    '<option value="6"'  + selected[6]  + '>Danger Zone</option>' +
                    '<option value="7"'  + selected[7]  + '>Natural Sight</option>' +
                    '<option value="8"'  + selected[8]  + '>Shopping</option>' +
                    '<option value="12"'  + selected[12]  + '>Medical care</option>' +
                    '<option value="13"'  + selected[13]  + '>Club / Institution</option>' +
                    '<option value="14"'  + selected[14]  + '>Light (Lighthouse, Sea Mark)</option>' +
                    '<option value="15"'  + selected[15]  + '>Parking</option>' +
                '</select>' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<label for="editPoiDescription">Description:</label><br>' +
            '<textarea id="editPoiDescription" rows="5"  oninput="validatePoiEditForm();"placeholder="Enter detailed description of POI ...">' + poi.description + '</textarea>' +
        '</div>' +
        '<div id="infoWindowElement" class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editPoiUrl">URL:</label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editPoiURL" type="text" maxlength="300" oninput="validatePoiEditForm();" placeholder="https://www.mypoi.tld" title="URL of POI" value="' + poi.url + '">' +
            '</div>' +
        '</div>' +
        '<div id="editPoiWSIContainer" class="infoWindowElement"' + styleWsi +'>' +
            '<div class="leftCol">' +
                '<label for="editPoiWSI">WSI-Code: *</label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editPoiWSI" type="text" maxlength="16" oninput="validatePoiEditForm();" placeholder="0011222222221100" title="The WSI code must consist of 16 characters from 0, 1 or 2." value="' + poi.direction + '">' +
            '</div>' +
        '</div>' +
        '<div id="editPoiLighthouseContainer" class="infoWindowElement"' + styleLighthouse +'>' +
            '<div class="leftCol">' +
                '<label for="editPoiLighthouseCharacteristic">Characteristic: </label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editPoiLighthouseCharacteristic" type="text" maxlength="50" oninput="validatePoiEditForm();" placeholder="Iso WRG 6s" title="Lighthouse Charcteristic, e.g. Iso WRG 6s." value="' + poi.characteristic + '">' +
            '</div>' +
            '<div class="infoWindowElement">' +
                '<label for="editPoiLighthouseSectorCharacteristic">Sector Characteristic: </label>' +
            '</div>' +
            '<div class="infoWindowElement">' +
                '<textarea id="editPoiLighthouseSectorCharacteristic" rows="6" maxlength="250" oninput="validatePoiEditForm();" placeholder="G 8M 30-110&#10;W 10M 110-195&#10;R 8M 195-275" title="Light Charcteristic">' + poi.sector_characteristic + '</textarea>' +
            '</div>' +
        '</div>'+
    '</div>';

    if (user.is_admin === true) {
        content +=
        '<div class="infoWindowElement">' +
            '<input type="checkbox" id="editPoiStatus" name="editPoiStatus"';
        if (poi.public == 1) content += ' checked';
        content += '>' +
            '<label for="editPoiStatus"><span></span>Public (visible for everyone)</label>' +
        '</div>';
    }
    content +=
        '<div class="infoWindowElement">' +
            '<button id="editPoiSaveBtn" class="button" onClick="savePoi(' + i + ')" disabled>Save</button>&nbsp;';

    if ((i !== null) && (typeof i !== 'undefined')) {
        content += '<button id="editPoiRemoveBtn" class="button" onClick="removePoi(' + i + ')">Remove</button>&nbsp;';
    }
    content += '<button class="button" onClick="cancelEditPoi(' + i +')">Cancel</button>' +
        '</div>';

    content += '<div id="poiCoordinates" class="poiEditWindowCoordinates"><i class="material-icons-round">navigation</i>' + Number.parseFloat(poi.latitude).toFixed(6) + ', ' + Number.parseFloat(poi.longitude).toFixed(6) + '</div>';
    content += '<div id="poiCoordinatesDMS" class="poiEditWindowCoordinates"><i class="material-icons-round">navigation</i>' + decimalLatLngToDMS(poi.latitude, poi.longitude) + '</div>';

    poiEditWindow.setContent(content);

    google.maps.event.addListener(poiEditWindow, 'closeclick', function(i, event) {
        cancelEditPoi(i);
    }.bind(poiEditWindow, i));

    poiEditWindow.addListener('position_changed', function () {
        var pos = poiEditWindow.getPosition();
        document.getElementById('poiCoordinates').innerHTML = '<i class="material-icons-round">navigation</i>' + pos.lat().toFixed(6) + ', ' + pos.lng().toFixed(6);
        document.getElementById('poiCoordinatesDMS').innerHTML = '<i class="material-icons-round">navigation</i>' + decimalLatLngToDMS(pos.lat(),pos.lng());
    });
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

    log('editPoi() called for: ' + i, LOG_DEFAULT, pois[i]);

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

    log('validation error: ' + errors, LOG_DEFAULT);

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

    log("savePoi()", LOG_DEFAULT, poiData);

    var request = (id === null)
        ? Ytan.post('/pois', poiData)
        : Ytan.put('/pois/' + id, poiData);

    request.then(answer => {
        log('savePoi() success', LOG_DEFAULT, answer);

        var index;
        if (i === null) {
            poiData.id = answer.data.id;
            index = pois.push(poiData) - 1;
            log('new index: ' + index, LOG_DEFAULT);
        } else {
            index = i;
            pois[index] = poiData;
            log('UPDATE array pois[' + i + ']', LOG_DEFAULT);
        }

        setPoi(index);
    }).catch(err => {
        log('savePoi() failed: ' + err.message, LOG_DEFAULT);
        alert('Saving the POI failed: ' + err.message);
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
function removePoi(i) {
    if (confirm('Do you really want to delete this POI?') == false) {
        return false;
    }

    var id = pois[i].id;

    Ytan.del('/pois/' + id).then(() => {
        log('removePoi(' + i + ') success', LOG_DEFAULT);

        removeMarkerById(pois[i].id);
        if ((pois[i].poitype_id === 1) || (pois[i].poitype_id === 11)) {
            removeWsiMarkerById(pois[i].id);
        }
        delete pois[i];
    }).catch(err => {
        log('removePoi() failed: ' + err.message, LOG_DEFAULT);
        alert('Removing the POI failed: ' + err.message);
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
