/**
 * Area (polygon) creation, editing and rendering.
 */

function editAreaBtnClick(elementId) {
    var element = document.getElementById(elementId);

    if (element.classList.contains('disabled')) {
        return;
    }

    if (!element.classList.contains('active')) {
        element.classList.add('active');
        disablePoiButton();
        disableRouteButton();
        editMode(true);
        measureTool.start();
        showSecondToolbar(elementId);
    }
}

function showAreaEditWindow(i, latLng) {
    log('showAreaEditWindow(' + i + ')', LOG_INFO, this);

    hideSecondToolbar();

    if (measureTool.segments.length < 1) {
        document.getElementById('areaButton').classList.remove('active');
        cancelEditArea(i);
        return;
    }

    if (user.id === null) {
        cancelEditArea(i);
        return;
    }

    var lastPoint = measureTool.points.slice(-1)[0];
    var area;

    if ((i !== null) && (typeof i !== 'undefined')) {
        area = areas[i];
    } else {
        area = {
            name: '',
            description: '',
            public: false,
            color: '#00FF30',
            opacity: 0.2,
            zindex: 1,
        }
    }

    var content =
        '<div class="infoWindowElement">' +
            '<h3>Area</h3>' +
            '<div class="leftCol">' +
                '<label for="editAreaName">Name: </label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editAreaName" type="text" oninput="validateAreaEditForm();" placeholder="Enter name of Area ..." title="Enter at least 3 characters" value="' + area.name + '">' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<label for="editAreaDescription">Description:</label><br>' +
            '<textarea id="editAreaDescription" rows="5" oninput="validateAreaEditForm();"placeholder="Enter detailed description of area ...">' + area.description + '</textarea>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editAreaColor">Color: </label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<div id="editAreaColorWrapper">' +
                    '<input type="color" id="editAreaColor" name="editAreaColor" value="' + area.color + '">' +
                '</div>' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editAreaOpacity">Opacity: </label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editAreaOpacity" class="slider" type="range" min="0" max="20" oninput="validateAreaEditForm();" title="Enter at opacity (0.05 to 1.0)" value="' + area.opacity * 20 + '">' +
                '&nbsp;<span id="editAreaOpacityValue">' + area.opacity.toFixed(2) + '</span>' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editAreaZindex">Z-Index: </label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editAreaZindex" type="text" oninput="validateAreaEditForm();" placeholder="1" title="Enter at z-index (integer)" value="' + area.zindex + '">' +
            '</div>' +
        '</div>'
        ;

    if (user.is_admin === true) {
        content +=
        '<div class="infoWindowElement">' +
            '<input type="checkbox" id="editAreaStatus" name="editAreaStatus"';
        if (area.public == 1) content += ' checked';
        content += '>' +
            '<label for="editAreaStatus"><span></span>Public (visible for everyone)</label>' +
        '</div>';
    }

    content +=
        '<div class="infoWindowElement">' +
            '<button id="editAreaSaveBtn" class="button" onClick="saveArea(' + i + ')" disabled>Save</button>&nbsp;';

    if (((i !== null) && (typeof i !== 'undefined')) && (areas[i].user_id == user.id)) {
        content = content +
            '<button id="editAreaRemoveBtn" class="button" onClick="removeArea(' + i + ')">Remove</button>&nbsp;';
    }

    content += '<button class="button" onClick="cancelEditArea(' + i + ')">Cancel</button>' +
        '</div>';

    areaEditWindow.setContent(content);
    if (latLng === null) {
        areaEditWindow.setPosition(lastPoint);
    } else {
        areaEditWindow.setPosition(latLng);
    }

    areaEditWindow.open(map);

    areaEditWindow.addListener('domready', function() {
        validateAreaEditForm();

        var color_picker = document.getElementById("editAreaColor");
        var color_picker_wrapper = document.getElementById("editAreaColorWrapper");
        color_picker.onchange = function() {
            color_picker_wrapper.style.backgroundColor = color_picker.value;
        }
        color_picker_wrapper.style.backgroundColor = color_picker.value;
    });

    google.maps.event.addListener(areaEditWindow, 'closeclick', function(i, event) {
        cancelEditArea(i);
    }.bind(areaEditWindow, i));
}

function validateAreaEditForm() {
    var errors = 0;

    var opacityValue = document.getElementById('editAreaOpacity').value / 20;

    document.getElementById('editAreaOpacityValue').innerHTML = opacityValue.toFixed(2);

    if (document.getElementById('editAreaName').value.length < 3) {
        errors = errors + 1;
    }

    if ((opacityValue < 0) || (opacityValue > 1)) {
        errors = errors + 2;
    }

    if (isNaN(parseInt(document.getElementById('editAreaZindex').value))) {
        errors = errors + 4;
    }

    if (errors & 1) {
        document.getElementById('editAreaName').classList.add('inputError');
    } else {
        document.getElementById('editAreaName').classList.remove('inputError');
    }

    if (errors & 2) {
        document.getElementById('editAreaOpacity').classList.add('inputError');
    } else {
        document.getElementById('editAreaOpacity').classList.remove('inputError');
    }

    if (errors & 4) {
        document.getElementById('editAreaZindex').classList.add('inputError');
    } else {
        document.getElementById('editAreaZindex').classList.remove('inputError');
    }

    if (errors == 0) {
        document.getElementById('editAreaSaveBtn').disabled = false;
        return true;
    } else {
        document.getElementById('editAreaSaveBtn').disabled = true;
        return false;
    }
}

function cancelEditArea(i) {
    closeAreaEditWindow();

    measureTool.index = null;
    measureTool.end();
    document.getElementById('areaButton').classList.remove('active');
    // Not just via closeAreaEditWindow() - that only re-enables the other
    // buttons if the naming/save window was actually open, but cancelling
    // with too few points (or while logged out) never gets that far.
    enableAreaButton();
    enablePoiButton();
    enableRouteButton();
    editMode(false);
    if ((i !== null) && (typeof i !== 'undefined')) {
        showArea(i);
    }
}

function closeAreaEditWindow() {
    if (areaEditWindow.isOpen) {
        enableAreaButton();
        enablePoiButton();
        enableRouteButton();
        editMode(false);
        areaEditWindow.close();
    }
}

/**
 * Speichert eine Area in der Datenbank
 */
function saveArea(i) {
    if (!(validateAreaEditForm() == true)) {
        return false;
    }

    if (user.id === null) {
        return false;
    }

    var id = null;
    var publicState = 0;
    if ((i !== null) && (typeof i !== 'undefined')) {
        id = areas[i].id;
        publicState = areas[i].public;
    }

    if (document.getElementById('editAreaStatus') !== null) {
        publicState = document.getElementById('editAreaStatus').checked ? 1 : 0;
    }

    var areaData = {
        id: id,
        user_id: user.id,
        name: escapeHTML(document.getElementById('editAreaName').value),
        description: escapeHTML(document.getElementById('editAreaDescription').value),
        public: publicState,
        points: JSON.stringify(measureTool.points),
        color: document.getElementById('editAreaColor').value,
        opacity: Number(document.getElementById('editAreaOpacity').value) / 20,
        zindex: parseInt(document.getElementById('editAreaZindex').value)
    }

    log('saveArea(' + i + ')', LOG_INFO, areaData);

    var request = (id === null)
        ? Ytan.post('/areas', areaData)
        : Ytan.put('/areas/' + id, areaData);

    request.then(answer => {
        log('saveArea() success', LOG_INFO, answer);

        measureTool.end();
        areaData.points = JSON.parse(areaData.points);
        var index = i;

        if (index == null) {
            log('INSERT into array areas[]', LOG_INFO);
            areaData.id = answer.data.id;
            index = areas.push(areaData) - 1;
            log('new index: ' + index, LOG_INFO);
        } else {
            log('UPDATE array areas[' + i +']', LOG_INFO);
            areas[index] = areaData;
        }

        createArea(index);

        if (settings.detailareas === false) {
            document.getElementById('detailareas').checked = true;
            settings.detailareas = true;
            saveSettings();
            showAreas();
        }
    }).catch(err => {
        log('saveArea() failed', LOG_ERROR, err);
        showToast('Saving the area failed: ' + err.message, 'error');
    });

    measureTool.index = null;

    closeAreaEditWindow();

    document.getElementById('areaButton').classList.remove('active');

    return true;
}

/**
 * Entfernt die Area <i> aus der Datenbank
 *
 * @param {int} i Index der Area im Array areas[]
 */
async function removeArea(i) {
    if (typeof areas[i] === null || typeof areas[i] === 'undefined') {
        log('removeArea(' + i + '): index not found.', LOG_INFO);
        return false;
    }

    if (!(await showConfirmDialog('Do you really want to delete this Area?', { type: 'danger', confirmLabel: 'Delete' }))) {
        return false;
    }

    var id = areas[i].id;

    log('removeArea(' + i + ')', LOG_INFO, id);

    Ytan.del('/areas/' + id).then(() => {
        log('removeArea() success', LOG_INFO);
        measureTool.index = null;
        measureTool.end();
        hideArea(i);
        areas.splice(i, 1);
        document.getElementById('areaButton').classList.remove('active');
        areaEditWindow.close();
    }).catch(err => {
        log('removeArea() failed', LOG_ERROR, err);
        showToast('Removing the area failed: ' + err.message, 'error');
    });
}

/**
 * Hole alle Areas eines Users und speichere sie in areas[] und zeige sie an
 *
 * @param {number} userId
 */
function getAreasByUserId(userId) {
    Ytan.get('/areas?scope=mine_public').then(answer => {
        areas = answer.data;
        log('getAreasByUserId(' + userId + ')', LOG_INFO, answer);
        for (let i = 0; i < areas.length; i++) {
            areas[i].points = JSON.parse(areas[i].points);
            areas[i].opacity = parseFloat(areas[i].opacity);
            areas[i].zindex = parseInt(areas[i].zindex);
        }
        createAreas();
        if (settings.detailareas) {
            showAreas();
        }
    }).catch(err => log('getAreasByUserId() failed', LOG_ERROR, err));
}

/**
 * Hole alle öffentlichen Areas speichere sie in areas[] und zeige sie an
 */
function getPublicAreas() {
    Ytan.get('/areas?scope=public').then(answer => {
        log('getPublicAreas()', LOG_INFO, answer);
        areas = answer.data;
        for (let i = 0; i < areas.length; i++) {
            areas[i].points = JSON.parse(areas[i].points);
            areas[i].opacity = parseFloat(areas[i].opacity);
            areas[i].zindex = parseInt(areas[i].zindex);
        }
        createAreas();
        if (settings.detailareas) {
            showAreas();
        }
    }).catch(err => log('getPublicAreas() failed', LOG_ERROR, err));
}

function showAreas() {
    for (let i = 0; i < areas.length; i++) {
        if (typeof areas[i] !== "undefined") {
            showArea(i);
        }
    }
}

function showArea(i) {
    if (typeof areas[i] !== 'undefined') {
        areaPolygons[i].setMap(map);
    }
}

function hideAreas() {
    for (let i = 0; i < areas.length; i++) {
        hideArea(i);
    }
}

function hideArea(i) {
    if (typeof areas[i] !== 'undefined') {
        areaPolygons[i].setMap();
    }
}

function createAreas() {
    for (let i = 0; i < areas.length; i++) {
        if (typeof areas[i] !== "undefined") {
            createArea(i);
        }
    }
}

/**
 * Erzeugt das Polygon für eine Area
 *
 * @param {integer} i Index im Array areas[]
 */
function createArea(i) {
    if (typeof areas[i] !== 'undefined') {
        var path = areas[i].points;

        var areaPolygon = new google.maps.Polygon({
            id: areas[i].id,
            paths: path,
            geodesic: true,
            strokeColor: areas[i].color,
            strokeOpacity: areas[i].opacity + 0.1,
            strokeWeight: 2.0,
            fillColor: areas[i].color,
            fillOpacity: areas[i].opacity,
            zIndex: areas[i].zindex,
        });

        google.maps.event.addListener(areaPolygon, 'click',
            function(event) {
                showAreaInfoWindow.call(this, event, i);
            }
        );

        google.maps.event.addListener(areaPolygon, 'contextmenu',
            function(event) {
                showAreaContextMenu.call(this, event, i);
            }
        );

        areaPolygons[i] = areaPolygon;

        if (settings.detailareas) {
            showArea(i);
        }
    }
}

function showAreaContextMenu(event, i) {
    log('showAreaContextMenu(event, ' + i +')', LOG_DEBUG);

    if (user.id !== null) {
        if ((areas[i].user_id == user.id) || (user.is_admin === true)) {
            log('show contextMenu', LOG_DEBUG);

            var content =
                '<div class="contextMenuItem" onClick="areaContextMenuEditArea(' + i + ', null, null);">Edit area</div>' +
                '<div class="contextMenuItem" onClick="areaContextMenuEditInfo(' + i + ');">Edit Info</div>' +
                '<div class="contextMenuItem" onClick="areaContextMenuRemoveArea(' + i + ');">Delete area</div>' +
                '<div class="contextMenuItem" onClick="closeContextMenu();">Cancel</div>'
                ;

            contextMenu.setPosition(event.latLng);
            contextMenu.setContent(content);
            contextMenu.open(map);
        }
    }
}

function areaContextMenuEditInfo(i) {
    closeContextMenu();

    measureTool.index = i;
    measureTool.start(areas[i].points);

    showAreaEditWindow(i, null);
}

function areaContextMenuEditArea(i) {
    closeContextMenu();
    editArea(i, null, null);
}

function areaContextMenuRemoveArea(i) {
    closeContextMenu();
    removeArea(i);
}

function showAreaInfoWindow(event, i) {
    if (areaInfoWindow.isOpen) {
        log('areaInfoWindow is open.', LOG_DEBUG);
        areaInfoWindow.close();
    }

    var areaSize = google.maps.geometry.spherical.computeArea(areas[i].points);
    var areaSizeText;
    if (areaSize < 100000) {
        areaSizeText = areaSize.toFixed(0) + '&nbsp;m²';
    } else {
        areaSizeText = (areaSize / 1000000).toFixed(2) + '&nbsp;km²';
    }

    var content =
        '<h3>' + areas[i]['name'] + '</h3>' +
        '<div class="infoWindowElement">' + marked.parse(areas[i]['description']) + '</div>' +
        '<div class="infoWindowBottom">' +
            '<div class="lefthalf"><i>' + areaSizeText + '</i></div>';
    if ((user.id !== null) && ((areas[i].user_id == user.id) || user.is_admin)) {
        content +=
            '<div class="righthalf areaEdit"><i class="material-icons-round" onClick="editArea(' + i + ', ' + event.latLng.lat() + ', ' + event.latLng.lng() +');">edit</i></div>';
    } else {
        content +=
            '<div class="righthalf"></div>';
    }
    content +=
        '</div>';

    areaInfoWindow.setPosition(event.latLng);
    areaInfoWindow.setContent(content);

    areaInfoWindow.open(map);
}

function editArea(i, lat, lng) {
    if (areaInfoWindow.isOpen) {
        areaInfoWindow.close();
    }
    hideArea(i);

    measureTool.index = i;
    measureTool.start(areas[i].points);
    document.getElementById('areaButton').classList.add('active');
    showSecondToolbar('areaButton');
}
