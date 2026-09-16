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
            '<h3>' + t('area.edit.heading') + '</h3>' +
            '<div class="leftCol">' +
                '<label for="editAreaName">' + t('area.edit.name_label') + '</label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editAreaName" type="text" oninput="validateAreaEditForm();" placeholder="' + t('area.edit.name_placeholder') + '" title="' + t('common.min_3_chars_title') + '" value="' + area.name + '">' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<label for="editAreaDescription">' + t('area.edit.description_label') + '</label><br>' +
            '<textarea id="editAreaDescription" rows="5" oninput="validateAreaEditForm();"placeholder="' + t('area.edit.description_placeholder') + '">' + area.description + '</textarea>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editAreaColor">' + t('area.edit.color_label') + '</label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<div id="editAreaColorWrapper">' +
                    '<input type="color" id="editAreaColor" name="editAreaColor" value="' + area.color + '">' +
                '</div>' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editAreaOpacity">' + t('area.edit.opacity_label') + '</label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editAreaOpacity" class="slider" type="range" min="0" max="20" oninput="validateAreaEditForm();" title="' + t('area.edit.opacity_title') + '" value="' + area.opacity * 20 + '">' +
                '&nbsp;<span id="editAreaOpacityValue">' + area.opacity.toFixed(2) + '</span>' +
            '</div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol">' +
                '<label for="editAreaZindex">' + t('area.edit.zindex_label') + '</label>' +
            '</div>' +
            '<div class="rightCol">' +
                '<input id="editAreaZindex" type="text" oninput="validateAreaEditForm();" placeholder="1" title="' + t('area.edit.zindex_title') + '" value="' + area.zindex + '">' +
            '</div>' +
        '</div>'
        ;

    // Photos: only for an already-existing area (needs an id first, same
    // rule tour-admin.js's photo grid follows for tours) - see poi.js's
    // initPoiEditWindow() for the identical pattern and photo-upload.js's
    // doc comment for why the grid starts empty and repaints shortly after.
    if ((i !== null) && (typeof i !== 'undefined')) {
        initPhotoUpload('areas', area.id, AREA_PHOTO_MAX_COUNT);
        content +=
            '<div class="infoWindowElement">' +
                '<label>' + t('area.edit.photos_label') + '</label>' +
                '<div id="' + PHOTO_UPLOAD_CONTAINER_ID + '">' + photoUploadGridHtml() + '</div>' +
            '</div>';
    } else {
        // No photo section shown for a brand-new area (needs an id first) -
        // reset rather than leave a previous area's still-running
        // compression able to block this unrelated create form's Save.
        resetPhotoUpload();
    }

    if (user.is_admin === true) {
        content +=
        '<div class="infoWindowElement">' +
            '<input type="checkbox" id="editAreaStatus" name="editAreaStatus"';
        if (area.public == 1) content += ' checked';
        content += '>' +
            '<label for="editAreaStatus"><span></span>' + t('common.public_label') + '</label>' +
        '</div>';
    }

    content +=
        '<div class="infoWindowElement">' +
            '<button id="editAreaSaveBtn" class="button" onClick="saveArea(' + i + ')" disabled>' + t('common.save') + '</button>&nbsp;';

    if (((i !== null) && (typeof i !== 'undefined')) && (areas[i].user_id == user.id)) {
        content = content +
            '<button id="editAreaRemoveBtn" class="button" onClick="removeArea(' + i + ')">' + t('common.remove') + '</button>&nbsp;';
    }

    content += '<button class="button" onClick="cancelEditArea(' + i + ')">' + t('common.cancel') + '</button>' +
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
    // A photo still being compressed (see stagePhotoUpload()) isn't staged
    // for upload yet - saving now would end the edit without ever
    // uploading it.
    if (photoUploadIsProcessing()) {
        showToast(t('photo_upload.still_processing'), 'warning');
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

        // Purely array/network-based (no DOM dependency), so this still
        // completes correctly even after the edit window has closed.
        if (id !== null) {
            applyPendingPhotoUploadChanges('areas', id);
        }

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
        showToast(t('area.save_failed', { error: err.message }), 'error');
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

    if (!(await showConfirmDialog(t('area.confirm_delete'), { type: 'danger', confirmLabel: t('common.delete') }))) {
        return false;
    }

    var id = areas[i].id;

    log('removeArea(' + i + ')', LOG_INFO, id);

    Ytan.del('/areas/' + id).then(() => {
        log('removeArea() success', LOG_INFO);
        measureTool.index = null;
        measureTool.end();

        // delete areas[i]/areaPolygons[i] rather than .splice() them out - a
        // splice() shifts every later area down by one array index, but
        // createArea()'s click/contextmenu listeners on each area's polygon
        // are closed over the index it had at creation time, so a shift
        // leaves them stale (pointing at the wrong area, or past the end of
        // the now-shorter array - "Cannot read properties of undefined
        // (reading 'user_id')" in showAreaContextMenu()). delete leaves a
        // hole instead of shifting anything, so every other area's index -
        // and its listeners - stays valid. Same approach poi.js's
        // removeMarkerById() already uses; the rest of this file already
        // guards every areas[]/areaPolygons[] access with
        // typeof !== 'undefined' for exactly this reason (createArea(),
        // createAreas(), hideArea(), ...), so no new guards are needed here
        // - just this deletion itself.
        google.maps.event.clearInstanceListeners(areaPolygons[i]);
        areaPolygons[i].setMap(null);
        delete areaPolygons[i];
        delete areas[i];

        document.getElementById('areaButton').classList.remove('active');
        areaEditWindow.close();
    }).catch(err => {
        log('removeArea() failed', LOG_ERROR, err);
        showToast(t('area.remove_failed', { error: err.message }), 'error');
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
                if (shouldSuppressClick()) {
                    return;
                }
                showAreaInfoWindow.call(this, event, i);
            }
        );

        google.maps.event.addListener(areaPolygon, 'contextmenu',
            function(event) {
                showAreaContextMenu.call(this, event, i);
            }
        );

        // Fallback for touch devices where the browser doesn't translate a
        // long-press into the 'contextmenu' event above (map-core.js).
        attachLongPressContextMenu(areaPolygon, function(event) {
            showAreaContextMenu.call(areaPolygon, event, i);
        });

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
                '<div class="contextMenuItem" onClick="areaContextMenuEditArea(' + i + ', null, null);"><i class="material-icons-round">edit</i>' + t('area.context.edit_area') + '</div>' +
                '<div class="contextMenuItem" onClick="areaContextMenuEditInfo(' + i + ');"><i class="material-icons-round">description</i>' + t('common.edit_info') + '</div>' +
                '<div class="contextMenuItem" onClick="areaContextMenuRemoveArea(' + i + ');"><i class="material-icons-round">delete</i>' + t('area.context.delete_area') + '</div>' +
                '<div class="contextMenuItem" onClick="closeContextMenu();"><i class="material-icons-round">close</i>' + t('common.cancel') + '</div>'
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
