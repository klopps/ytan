/**
 * Logic for /admin/wind (templates/admin-wind.php): the wind job (tools/wind/).
 *
 * Status: what the job wrote to storage/wind/status.json on its last runs,
 * reloaded with GET /settings/wind. Settings: PUT /settings/wind, which also
 * hands them to the job (storage/radar/config.json, "wind" object). The fill
 * opacity is each user's own setting (Preferences, settings cookie). The area
 * is the rain radar's regions (/admin/radar) or own rectangles.
 */
var WIND_POLL_MS = 4000;
var windPollTimer = null;

function windFormatTime(iso) {
    if (!iso) {
        return '-';
    }
    return new Date(iso).toLocaleString(window.YTAN_LOCALE || undefined, {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
}

/** End minus start ("1 min 12 s", "47 s"); "-" when one of them is missing. */
function windFormatDuration(start, end) {
    if (!start || !end) {
        return '-';
    }
    var seconds = Math.max(0, Math.round((new Date(end) - new Date(start)) / 1000));
    var minutes = Math.floor(seconds / 60);
    return minutes > 0
        ? t('admin.radar.duration_min_s', { min: minutes, s: seconds % 60 })
        : t('admin.radar.duration_s', { s: seconds });
}

/** Bytes as MB (1024 x 1024) with 2 decimals. */
function windFormatMb(bytes) {
    if (bytes === null || bytes === undefined) {
        return '-';
    }
    return t('admin.radar.mb', {
        mb: (bytes / 1048576).toLocaleString(window.YTAN_LOCALE || undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    });
}

function windData() {
    return window.YTAN_WIND_SETTINGS || { status: null, running: false };
}

/** "ICON-EU ..., ICON-D2 ..., ICON global ..." - only the models the last processing used. */
function windModelRunsLabel(proc) {
    var parts = [];
    if (proc.eu_run) {
        parts.push('ICON-EU ' + windRunLabel(proc.eu_run));
    }
    if (proc.d2_run) {
        parts.push('ICON-D2 ' + windRunLabel(proc.d2_run));
    }
    if (proc.gl_run) {
        parts.push('ICON global ' + windRunLabel(proc.gl_run));
    }
    return parts.join(', ') || t('admin.wind.no_run');
}

function windSetText(id, text) {
    document.getElementById(id).textContent = text;
}

/** Outcome of a run in words (literal keys: the translation tool finds usages by scanning). */
function windOutcomeLabel(outcome) {
    switch (outcome) {
    case 'processed': return t('admin.wind.outcome.processed');
    case 'unchanged': return t('admin.wind.outcome.unchanged');
    case 'no_data': return t('admin.wind.outcome.no_data');
    case 'disabled': return t('admin.wind.outcome.disabled');
    default: return t('admin.wind.outcome.failed');
    }
}

/** "2026-10-18 18 UTC" for a model run id like 2026101818. */
function windRunLabel(run) {
    if (!run) {
        return t('admin.wind.no_run');
    }
    return run.substr(0, 4) + '-' + run.substr(4, 2) + '-' + run.substr(6, 2) + ' ' + run.substr(8, 2) + ' UTC';
}

function renderWindStatus() {
    var data = windData();
    var status = data.status || null;
    var last = status && status.last_run;
    var proc = status && status.last_processing;

    windSetText('windLastRun', last
        ? windFormatTime(last.end) + ' - ' + windOutcomeLabel(last.outcome) + (last.error ? ' (' + last.error + ')' : '')
        : t('admin.radar.never_ran'));
    windSetText('windModelRuns', proc
        ? windModelRunsLabel(proc)
        : t('admin.wind.no_run'));
    windSetText('windLastProcessed', proc ? windFormatTime(proc.end) + ' - ' + t('admin.wind.hours_count', { n: proc.hours }) : '-');
    windSetText('windDuration', proc ? windFormatDuration(proc.start, proc.end) : '-');
    windSetText('windDownload', proc
        ? windFormatMb(proc.bytes_downloaded) + ', ' + t('admin.radar.files', { n: proc.files_downloaded })
        : '-');
    windSetText('windWritten', proc ? windFormatMb(proc.bytes_written) : '-');
    windSetText('windStorage', status && status.storage_bytes !== undefined
        ? windFormatMb(status.storage_bytes) + ' (' + t('admin.radar.measured_at', { time: windFormatTime(status.storage_measured_at) }) + ')'
        : '-');

    var running = !!data.running;
    var runBtn = document.getElementById('windRunNow');
    runBtn.disabled = running || !document.getElementById('windEnabled').checked;
    document.getElementById('windRunning').hidden = !running;
    if (running) {
        windStartPolling();
    }
}

function refreshWindStatus(quiet) {
    var btn = document.getElementById('windStatusRefresh');
    btn.disabled = true;
    return Ytan.get('/settings/wind').then(function (answer) {
        // only the status: unsaved edits of the settings stay as they are
        windData().status = answer.data.status;
        windData().running = answer.data.running;
        renderWindStatus();
        return answer.data.running;
    }).catch(function (err) {
        if (quiet !== true) {
            showAdminToast(err.message, 'error');
        }
        return false;
    }).finally(function () {
        btn.disabled = false;
    });
}

function windStartPolling() {
    if (windPollTimer) {
        return;
    }
    windPollTimer = setInterval(function () {
        refreshWindStatus(true).then(function (running) {
            if (!running) {
                clearInterval(windPollTimer);
                windPollTimer = null;
                showAdminToast(t('admin.wind.run_finished'), 'success');
            }
        });
    }, WIND_POLL_MS);
}

function startWindRun() {
    var btn = document.getElementById('windRunNow');
    btn.disabled = true;
    Ytan.post('/settings/wind/run', {}).then(function () {
        showAdminToast(t('admin.wind.run_started'), 'success');
        // the job takes a moment to start and take its lock
        setTimeout(function () {
            refreshWindStatus(true).then(function () {
                windData().running = true; // keep polling until the run is over
                renderWindStatus();
            });
        }, 1500);
    }).catch(function (err) {
        showAdminToast(err.data ? translateApiError(err.data) : err.message, 'error');
        refreshWindStatus(true);
    });
}

function saveWindSettings() {
    var btn = document.getElementById('windSaveBtn');
    var body = {
        enabled: document.getElementById('windEnabled').checked,
        horizon_hours: parseInt(document.getElementById('windHorizon').value, 10),
        run_interval_hours: parseInt(document.getElementById('windInterval').value, 10),
        gusts: document.getElementById('windGusts').checked,
        fine: document.getElementById('windFine').checked,
        world: document.getElementById('windWorld').checked,
        regions: windRegionsForSave()
    };
    btn.disabled = true;
    Ytan.put('/settings/wind', body).then(function (answer) {
        window.YTAN_WIND_SETTINGS = Object.assign(windData(), answer.data);
        renderWindStatus();
        showAdminToast(t('admin.wind.saved'), 'success');
    }).catch(function (err) {
        showAdminToast(t('common.save_failed', { error: err.data ? translateApiError(err.data) : err.message }), 'error');
    }).finally(function () {
        btn.disabled = false;
        windUpdateAreaSize();
    });
}

/* ---- own regions ------------------------------------------------------- */

var windArea = { map: null, custom: [] };
var WIND_AREA_COLOR = '#0d6efd';

function windRectBox(rect) {
    var b = rect.getBounds();
    return { south: b.getSouthWest().lat(), west: b.getSouthWest().lng(), north: b.getNorthEast().lat(), east: b.getNorthEast().lng() };
}

/** null = the rain radar's regions; otherwise the drawn rectangles (the stored ones when there is no map). */
function windRegionsForSave() {
    if (!document.getElementById('windAreaOwn').checked) {
        return null;
    }
    if (!windArea.map) {
        return windData().regions || null;
    }
    return windArea.custom.map(function (entry) {
        var box = windRectBox(entry.rect);
        box.name = entry.name.trim();
        return box;
    });
}

function windUpdateAreaSize() {
    var el = document.getElementById('windAreaSize');
    var own = document.getElementById('windAreaOwn').checked;
    var save = document.getElementById('windSaveBtn');
    if (!own || !windArea.map) {
        el.textContent = '';
        save.disabled = false;
        return;
    }
    var boxes = windArea.custom.map(function (entry) { return windRectBox(entry.rect); });
    if (boxes.length === 0) {
        el.textContent = t('admin.wind.area_none');
        el.className = 'small mb-2 text-danger';
        save.disabled = true;
        return;
    }
    var south = Math.min.apply(null, boxes.map(function (b) { return b.south; }));
    var north = Math.max.apply(null, boxes.map(function (b) { return b.north; }));
    var west = Math.min.apply(null, boxes.map(function (b) { return b.west; }));
    var east = Math.max.apply(null, boxes.map(function (b) { return b.east; }));
    var area = (north - south) * (east - west);
    var outside = south < -90 || north > 90 || west < -180 || east > 180;
    el.textContent = t('admin.wind.area_size', { area: Math.round(area) });
    el.className = 'small mb-2 ' + (outside ? 'text-danger' : 'text-secondary');
    save.disabled = outside;
}

function windAddRegion(box, name) {
    var rect = new google.maps.Rectangle({
        map: windArea.map, bounds: box, editable: true, draggable: true,
        strokeColor: WIND_AREA_COLOR, strokeWeight: 2, fillColor: WIND_AREA_COLOR, fillOpacity: 0.15
    });
    rect.addListener('bounds_changed', function () {
        windRenderRegionList();
        windUpdateAreaSize();
    });
    windArea.custom.push({ rect: rect, name: name || '' });
    windRenderRegionList();
    windUpdateAreaSize();
}

/** One rectangle around the whole earth, replacing the others. */
function windAddWholeWorld() {
    if (!windArea.map) {
        return;
    }
    windArea.custom.forEach(function (entry) { entry.rect.setMap(null); });
    windArea.custom = [];
    windAddRegion({ south: -90, west: -180, north: 90, east: 180 }, t('admin.wind.area_world'));
    windArea.map.fitBounds({ south: -60, west: -180, north: 75, east: 180 });
}

/** A new rectangle in the middle of the visible map, 20% of its width and height. */
function windAddRegionInView() {
    if (!windArea.map) {
        return;
    }
    var max = windData().max_regions || 10;
    if (windArea.custom.length >= max) {
        showAdminToast(t('admin.settings.radar_max_custom', { max: max }), 'error');
        return;
    }
    var b = windArea.map.getBounds();
    var sw = b.getSouthWest();
    var ne = b.getNorthEast();
    var dLat = (ne.lat() - sw.lat()) * 0.1;
    var dLng = (ne.lng() - sw.lng()) * 0.1;
    var c = b.getCenter();
    windAddRegion({ south: c.lat() - dLat, north: c.lat() + dLat, west: c.lng() - dLng, east: c.lng() + dLng }, '');
}

function windRenderRegionList() {
    var list = document.getElementById('windRegionList');
    list.textContent = '';
    windArea.custom.forEach(function (entry, i) {
        var box = windRectBox(entry.rect);
        var li = document.createElement('li');
        li.className = 'list-group-item d-flex flex-wrap align-items-center gap-2';
        var name = document.createElement('input');
        name.type = 'text';
        name.className = 'form-control form-control-sm';
        name.style.maxWidth = '14rem';
        name.maxLength = 60;
        name.placeholder = t('admin.settings.radar_region_name', { n: i + 1 });
        name.value = entry.name;
        name.addEventListener('input', function () { entry.name = name.value; });
        var coords = document.createElement('span');
        coords.className = 'small text-secondary';
        coords.textContent = box.south.toFixed(2) + ' - ' + box.north.toFixed(2) + ' N, '
            + box.west.toFixed(2) + ' - ' + box.east.toFixed(2) + ' E';
        var show = document.createElement('button');
        show.type = 'button';
        show.className = 'btn btn-outline-secondary btn-sm ms-auto';
        show.innerHTML = '<i class="bi bi-zoom-in"></i>';
        show.title = t('admin.settings.radar_show_region');
        show.setAttribute('aria-label', show.title);
        show.addEventListener('click', function () { windArea.map.fitBounds(entry.rect.getBounds()); });
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-outline-danger btn-sm';
        del.innerHTML = '<i class="bi bi-trash"></i>';
        del.title = t('admin.settings.radar_delete_region');
        del.setAttribute('aria-label', del.title);
        del.addEventListener('click', function () {
            entry.rect.setMap(null);
            windArea.custom.splice(windArea.custom.indexOf(entry), 1);
            windRenderRegionList();
            windUpdateAreaSize();
        });
        li.append(name, coords, show, del);
        list.appendChild(li);
    });
}

/** The map loads the first time the own-area choice is shown (a page with the radar's regions needs no Google Maps). */
var windMapStarted = false;

async function windStartAreaMap() {
    if (windMapStarted) {
        return;
    }
    windMapStarted = true;
    try {
        await loadAdminGoogleMaps();
    } catch (err) {
        document.getElementById('windNoKey').hidden = false;
        document.getElementById('windMap').hidden = true;
        document.getElementById('windAddRegion').hidden = true;
        document.getElementById('windAddWorld').hidden = true;
        return;
    }
    windArea.map = new google.maps.Map(document.getElementById('windMap'), {
        center: { lat: 30, lng: 10 }, zoom: 2, gestureHandling: 'greedy',
        streetViewControl: false, fullscreenControl: false, clickableIcons: false
    });
    (windData().regions || []).forEach(function (c) {
        windAddRegion({ south: c.south, west: c.west, north: c.north, east: c.east }, c.name);
    });
    windUpdateAreaSize();
}

function windAreaChoiceChanged() {
    var own = document.getElementById('windAreaOwn').checked;
    document.getElementById('windOwnArea').hidden = !own;
    if (own) {
        windStartAreaMap();
    }
    windUpdateAreaSize();
}

function initWindPage() {
    document.getElementById('windAreaRadar').addEventListener('change', windAreaChoiceChanged);
    document.getElementById('windAreaOwn').addEventListener('change', windAreaChoiceChanged);
    document.getElementById('windAddRegion').addEventListener('click', windAddRegionInView);
    document.getElementById('windAddWorld').addEventListener('click', windAddWholeWorld);
    windAreaChoiceChanged();
    document.getElementById('windSaveBtn').addEventListener('click', saveWindSettings);
    document.getElementById('windRunNow').addEventListener('click', startWindRun);
    document.getElementById('windStatusRefresh').addEventListener('click', function () { refreshWindStatus(); });
    document.getElementById('windEnabled').addEventListener('change', renderWindStatus);
    renderWindStatus();
    // a run started by cron meanwhile shows up too (not in a background tab)
    setInterval(function () {
        if (!document.hidden && !windPollTimer) {
            refreshWindStatus(true);
        }
    }, 15000);
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initWindPage });
