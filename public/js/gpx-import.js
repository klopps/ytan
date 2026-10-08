/**
 * GPX import (todo.md "Import von GPX-Daten"): drawer row "Import GPX"
 * (signed in only) -> full-screen panel #gpximportmenu, same slide-in
 * pattern as the Tours panel. Three views in #gpximportmenu-body:
 * choose a file -> preview (file data, tracks with checkboxes, merge/
 * separate/tour options, "import waypoints as POIs") -> result ("Show
 * route" / "Tour details").
 *
 * The file is read in the browser and its text sent to POST /gpx/preview
 * and again to POST /gpx/import (GpxImportController) - the server parses,
 * simplifies and creates everything, nothing is kept between the steps.
 * Everything is created private, which the preview says plainly.
 */
const GPX_IMPORT_MAX_BYTES = 5 * 1024 * 1024; // GpxImportService::MAX_BYTES

let gpxImportText = null;    // the chosen file's content, sent with both requests
let gpxImportPreview = null; // POST /gpx/preview's answer

function openGpxImportMenu() {
    slideInPanel('gpximportmenu');
    pushMenuLeft();
    panelOpened();
    renderGpxImportStart();
}

function closeGpxImportMenu() {
    slideOutPanel('gpximportmenu');
    unpushMenuLeft();
    panelClosed();
}

/** Drawer row: only for signed-in users (importing creates their routes). */
function updateGpxImportMenuVisibility() {
    document.getElementById('gpxImportMenuRow').style.display = user.id !== null ? '' : 'none';
}

function gpxImportBody(html) {
    document.getElementById('gpximportmenu-body').innerHTML = html;
}

function renderGpxImportStart() {
    gpxImportText = null;
    gpxImportPreview = null;
    gpxImportBody(
        '<p>' + t('gpx_import.intro') + '</p>' +
        '<label class="nav-btn-primary gpx-import-file-btn"><i class="material-icons-round">file_upload</i>&nbsp;' + t('gpx_import.choose_file') +
            '<input type="file" id="gpxImportFile" accept=".gpx,application/gpx+xml,application/xml,text/xml" onchange="gpxImportFileChosen(this);" hidden>' +
        '</label>' +
        '<div class="gpx-import-private-note"><i class="material-icons-round">lock</i><span>' + t('gpx_import.private_note') + '</span></div>'
    );
}

async function gpxImportFileChosen(input) {
    var file = input.files && input.files[0];
    if (!file) {
        return;
    }
    if (file.size > GPX_IMPORT_MAX_BYTES) {
        showToast(t('gpx_import.too_large'), 'error');
        input.value = '';
        return;
    }

    gpxImportBody('<p>' + t('gpx_import.reading') + '</p>');
    try {
        gpxImportText = await file.text();
        var answer = await Ytan.post('/gpx/preview', { gpx: gpxImportText });
        gpxImportPreview = answer.data;
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'error');
        renderGpxImportStart();
        return;
    }

    if (gpxImportPreview.tracks.length === 0 && gpxImportPreview.waypoints.length === 0) {
        showToast(t('gpx_import.no_tracks'), 'error');
        renderGpxImportStart();
        return;
    }
    renderGpxImportPreview();
}

function gpxImportTime(iso) {
    return iso ? new Date(iso).toLocaleString(language, { dateStyle: 'medium', timeStyle: 'short' }) : '';
}

function gpxImportTrackName(track) {
    return track.name || t('gpx_import.unnamed_track', { n: track.index + 1 });
}

/**
 * "(60 Wegpunkte)" behind the track name - or, for a track longer than the
 * import keeps, "(5,000 Wegpunkte, als Route 500)" (GpxImportService::
 * toRoute() simplifies it; route_point_count is that count as a separate
 * route).
 */
function gpxImportPointCount(track) {
    var count = Number(track.point_count).toLocaleString(language);
    if (track.route_point_count < track.point_count) {
        return t('gpx_import.waypoints_simplified', { count: count, kept: Number(track.route_point_count).toLocaleString(language) });
    }
    return t('gpx_import.waypoints', { count: count });
}

function renderGpxImportPreview() {
    var p = gpxImportPreview;
    var html = '<div class="gpx-import-file">' +
        '<div class="gpx-import-file-name">' + escapeHTML(p.name || t('gpx_import.unnamed_file')) + '</div>' +
        (p.desc ? '<div class="gpx-import-desc">' + escapeHTML(p.desc) + '</div>' : '') +
        (p.time ? '<div class="tour-list-meta">' + escapeHTML(gpxImportTime(p.time)) + '</div>' : '') +
        '</div>';

    html += '<div class="gpx-import-private-note"><i class="material-icons-round">lock</i><span>' + t('gpx_import.private_note') + '</span></div>';

    if (p.tracks.length > 0) {
        html += '<div class="gpx-import-select-all">' +
            '<input type="checkbox" id="gpxImportAll" checked onchange="gpxImportToggleAll(this.checked);"><label for="gpxImportAll">' + t('gpx_import.select_all', { count: p.tracks.length }) + '</label>' +
            '</div>';
    }
    html += '<ul class="gpx-import-tracks">';
    p.tracks.forEach(function (track) {
        var desc = track.desc && track.desc.length > 120 ? track.desc.slice(0, 120) + '…' : track.desc;
        var meta = [formatDistance(track.length, settings.unit)];
        if (track.time) {
            meta.unshift(gpxImportTime(track.time));
        }
        if (track.kind === 'rte') {
            meta.push(t('gpx_import.kind_route'));
        }
        html += '<li class="gpx-import-track">' +
            '<input type="checkbox" id="gpxImportTrack' + track.index + '" class="gpx-import-track-check" data-index="' + track.index + '" checked onchange="gpxImportSelectionChanged();">' +
            '<label for="gpxImportTrack' + track.index + '">' +
                '<span class="gpx-import-track-name">' + escapeHTML(gpxImportTrackName(track)) + '</span>' +
                ' <span class="gpx-import-track-points">' + escapeHTML(gpxImportPointCount(track)) + '</span>' +
                (desc ? '<span class="gpx-import-desc">' + escapeHTML(desc) + '</span>' : '') +
                '<span class="tour-list-meta">' + escapeHTML(meta.join(' · ')) + '</span>' +
            '</label></li>';
    });
    html += '</ul>';

    html += '<div class="gpx-import-options" id="gpxImportOptions">' +
        '<div class="nav-segmented">' +
            '<input type="radio" id="gpxImportModeSeparate" name="gpxImportMode" value="separate" checked onchange="gpxImportSelectionChanged();"><label for="gpxImportModeSeparate">' + t('gpx_import.mode_separate') + '</label>' +
            '<input type="radio" id="gpxImportModeMerge" name="gpxImportMode" value="merge" onchange="gpxImportSelectionChanged();"><label for="gpxImportModeMerge">' + t('gpx_import.mode_merge') + '</label>' +
        '</div>';
    if (canCreateTours()) {
        html += '<div class="gpx-import-tour-option">' +
            '<input type="checkbox" id="gpxImportCreateTour"><label for="gpxImportCreateTour">' + t('gpx_import.create_tour') + '</label>' +
            '</div>';
    }
    html += '</div>';

    // <wpt>s with a name/description (GpxImportService) - optional, off by
    // default; the names are listed in a collapsed <details> below it.
    if (p.waypoints.length > 0) {
        html += '<div class="gpx-import-waypoints">' +
            '<input type="checkbox" id="gpxImportWaypoints"' + (p.tracks.length === 0 ? ' checked' : '') + ' onchange="gpxImportSelectionChanged();">' +
            '<label for="gpxImportWaypoints">' + t('gpx_import.with_waypoints', { count: p.waypoints.length }) + '</label>' +
            '<details><summary>' + t('gpx_import.show_waypoints') + '</summary><ul>' +
            p.waypoints.map(function (waypoint) { return '<li>' + escapeHTML(waypoint.name) + '</li>'; }).join('') +
            '</ul></details></div>';
    }

    html += '<button type="button" class="nav-btn-primary" id="gpxImportSubmit" onclick="submitGpxImport();"><i class="material-icons-round">check</i>&nbsp;<span id="gpxImportSubmitLabel"></span></button>' +
        '<button type="button" class="nav-btn-secondary" onclick="renderGpxImportStart();">' + t('gpx_import.other_file') + '</button>';

    gpxImportBody(html);
    gpxImportSelectionChanged();
}

function gpxImportSelectedIndices() {
    return Array.from(document.querySelectorAll('.gpx-import-track-check'))
        .filter(function (box) { return box.checked; })
        .map(function (box) { return Number(box.dataset.index); });
}

function gpxImportToggleAll(checked) {
    document.querySelectorAll('.gpx-import-track-check').forEach(function (box) { box.checked = checked; });
    gpxImportSelectionChanged();
}

/**
 * Keeps the dependent controls consistent with the selection: "select all"
 * mirrors the boxes, merge/separate only matters for 2+ tracks, a tour only
 * comes from separate routes, and the button says what will be created.
 */
function gpxImportSelectionChanged() {
    var selected = gpxImportSelectedIndices();
    var total = gpxImportPreview.tracks.length;
    var all = document.getElementById('gpxImportAll');
    if (all) {
        all.checked = selected.length === total;
        all.indeterminate = selected.length > 0 && selected.length < total;
    }
    var withWaypoints = gpxImportWithWaypoints();

    var merge = document.getElementById('gpxImportModeMerge').checked;
    document.getElementById('gpxImportOptions').style.display = selected.length > 1 ? '' : 'none';
    var tourBox = document.getElementById('gpxImportCreateTour');
    if (tourBox) {
        tourBox.disabled = merge;
        if (merge) {
            tourBox.checked = false;
        }
    }

    var routeCount = selected.length > 1 && merge ? 1 : selected.length;
    var label;
    if (routeCount === 0) {
        label = withWaypoints ? t('gpx_import.submit_pois', { count: gpxImportPreview.waypoints.length }) : t('gpx_import.submit_many', { count: 0 });
    } else {
        label = routeCount === 1 ? t('gpx_import.submit_one') : t('gpx_import.submit_many', { count: routeCount });
        if (withWaypoints) {
            label += ' ' + t('gpx_import.submit_plus_pois', { count: gpxImportPreview.waypoints.length });
        }
    }
    document.getElementById('gpxImportSubmitLabel').textContent = label;
    document.getElementById('gpxImportSubmit').disabled = selected.length === 0 && !withWaypoints;
}

function gpxImportWithWaypoints() {
    var box = document.getElementById('gpxImportWaypoints');
    return !!box && box.checked;
}

async function submitGpxImport() {
    var selected = gpxImportSelectedIndices();
    var merge = selected.length > 1 && document.getElementById('gpxImportModeMerge').checked;
    var tourBox = document.getElementById('gpxImportCreateTour');
    var body = {
        gpx: gpxImportText,
        tracks: selected,
        mode: merge ? 'merge' : 'separate',
        create_tour: selected.length > 1 && !merge && !!tourBox && tourBox.checked,
        import_waypoints: gpxImportWithWaypoints(),
    };

    var button = document.getElementById('gpxImportSubmit');
    button.disabled = true;
    try {
        var answer = await Ytan.post('/gpx/import', body);
        renderGpxImportResult(answer.data);
    } catch (err) {
        showToast(translateApiError(err.data) || err.message, 'error');
        button.disabled = false;
    }
}

function renderGpxImportResult(result) {
    // Show the new routes on the map (and the new tour in the Tours panel)
    // right away - same reloads as after a login.
    getRoutesByUserId(user.id);
    if (result.tour) {
        getToursByUserId(user.id);
    }
    if (result.pois > 0) {
        getPoisByUserId(user.id);
    }

    var lines = [];
    if (result.routes.length === 1) {
        lines.push(t('gpx_import.done_one', { name: result.routes[0].name }));
    } else if (result.routes.length > 1) {
        lines.push(t('gpx_import.done_many', { count: result.routes.length }));
    }
    if (result.tour) {
        lines.push(t('gpx_import.done_tour', { name: result.tour.name }));
    }
    if (result.pois > 0) {
        lines.push(t('gpx_import.done_pois', { count: result.pois }));
    }
    var html = '<div class="gpx-import-done"><i class="material-icons-round">check_circle</i><div>' +
        lines.map(escapeHTML).join('<br>') +
        '</div></div>' +
        '<div class="gpx-import-private-note"><i class="material-icons-round">lock</i><span>' + t('gpx_import.private_note_done') + '</span></div>';

    if (result.tour) {
        html += '<button type="button" class="nav-btn-primary" onclick="gpxImportShowTour(' + Number(result.tour.id) + ');"><i class="material-icons-round">tour</i>&nbsp;' + t('gpx_import.tour_details') + '</button>';
    } else if (result.routes.length === 1) {
        html += '<button type="button" class="nav-btn-primary" onclick="gpxImportShowRoute(' + Number(result.routes[0].id) + ');"><i class="material-icons-round">visibility</i>&nbsp;' + t('gpx_import.show_route') + '</button>';
    }
    html += '<button type="button" class="nav-btn-secondary" onclick="renderGpxImportStart();">' + t('gpx_import.import_another') + '</button>';
    gpxImportBody(html);
}

/**
 * "Show route": zoom to the imported route and select it the way a click
 * on it does (waypoints + distance labels, showRouteLabels()), with every
 * other route deselected. getRoutesByUserId() reloads asynchronously, so
 * this waits for the route to show up in routes[].
 */
async function gpxImportShowRoute(routeId) {
    var i = -1;
    for (var attempt = 0; attempt < 50 && i === -1; attempt++) {
        for (var k = 0; k < routes.length; k++) {
            if (routes[k] && routes[k].id == routeId && routePaths[k]) {
                i = k;
                break;
            }
        }
        if (i === -1) {
            await new Promise(function (resolve) { setTimeout(resolve, 100); });
        }
    }
    if (i === -1) {
        showToast(t('gpx_import.route_not_loaded'), 'error');
        return;
    }

    closeGpxImportMenu();
    closeMenu();

    for (var j = 0; j < routes.length; j++) {
        if (j !== i && routes[j] && routes[j].labels && routes[j].labels.length) {
            hideRouteLabels(j);
        }
    }

    var bounds = new google.maps.LatLngBounds();
    routes[i].points.forEach(function (point) { bounds.extend(point); });
    map.fitBounds(bounds);
    // Labels depend on the zoom level (showRouteLabels() reads it), so
    // place them once the map has settled on the new bounds.
    google.maps.event.addListenerOnce(map, 'idle', function () {
        if (routes[i].labels && routes[i].labels.length) {
            hideRouteLabels(i);
        }
        showRouteLabels(i);
    });
}

function gpxImportShowTour(tourId) {
    closeGpxImportMenu();
    openTourAdminMenu();
    showTourDetail(tourId);
}
