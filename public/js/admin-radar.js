/**
 * Logic for /admin/radar (templates/admin-radar.php): the rain radar job.
 *
 * Status (card #radarStatusCard): what the job wrote to
 * storage/radar/status.json on its last runs - reloaded with
 * GET /settings/radar.
 *
 * Settings (card #radarSettingsCard): history and forecast length, fetch
 * interval, parallel processes, country boxes (RadarConfig::PRESETS, grey
 * on the map) and own rectangles (editable google.maps.Rectangle). Saved
 * with PUT /settings/radar, which also hands them to the job
 * (storage/radar/config.json); it recomputes every frame on its next run
 * when the regions changed.
 */
var RADAR_CUSTOM_COLOR = '#BF409F';
var RADAR_PRESET_COLOR = '#6c757d';
var RADAR_MAX_CUSTOM = 10;
var radarSettings = {
    map: null,
    presetRects: {}, // code -> Rectangle, only while checked
    custom: [] // {rect: google.maps.Rectangle, name: string}
};

function radarSettingsData() {
    return window.YTAN_RADAR_SETTINGS || { forecast_minutes: 60, presets: [], custom: [], preset_regions: {}, max_mosaic_pixels: 0 };
}

/** The limit as typed in #radarMaxMosaic (million pixels), in pixels; NaN while invalid. */
function radarMaxMosaicPixels() {
    var data = radarSettingsData();
    var millions = Number(document.getElementById('radarMaxMosaic').value);
    var px = Math.round(millions) * 1e6;
    if (!Number.isFinite(millions) || px < data.max_mosaic_pixels_min || px > data.max_mosaic_pixels_limit) {
        return NaN;
    }
    return px;
}

function radarCheckedPresets() {
    return Array.prototype.slice.call(document.querySelectorAll('#radarPresets input:checked')).map(function (box) {
        return box.value;
    });
}

function radarRectBox(rect) {
    var b = rect.getBounds();
    var round = function (v) { return Math.round(v * 10000) / 10000; };
    return {
        south: round(b.getSouthWest().lat()), west: round(b.getSouthWest().lng()),
        north: round(b.getNorthEast().lat()), east: round(b.getNorthEast().lng())
    };
}

/** All regions as {south, west, north, east}, for the size estimate. */
function radarAllBoxes() {
    var presets = radarSettingsData().preset_regions;
    var boxes = radarCheckedPresets().map(function (code) { return presets[code]; });
    if (radarSettings.map) {
        radarSettings.custom.forEach(function (c) { boxes.push(radarRectBox(c.rect)); });
    } else {
        radarSettingsData().custom.forEach(function (c) { boxes.push(c); }); // no map: the stored ones stay
    }
    return boxes;
}

/** Same estimate as RadarConfig::mosaicPixels(): the enclosing rectangle at zoom 8. */
function radarMosaicPixels(boxes) {
    if (boxes.length === 0) {
        return 0;
    }
    var south = Math.min.apply(null, boxes.map(function (b) { return b.south; }));
    var north = Math.max.apply(null, boxes.map(function (b) { return b.north; }));
    var west = Math.min.apply(null, boxes.map(function (b) { return b.west; }));
    var east = Math.max.apply(null, boxes.map(function (b) { return b.east; }));
    var world = 256 * Math.pow(2, 8);
    var y = function (lat) {
        var s = Math.sin(lat * Math.PI / 180);
        return (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * world;
    };
    return (east - west) / 360 * world * (y(south) - y(north));
}

function radarUpdateSize() {
    var data = radarSettingsData();
    var boxes = radarAllBoxes();
    var el = document.getElementById('radarSize');
    var px = radarMosaicPixels(boxes);
    var limit = radarMaxMosaicPixels();
    if (Number.isNaN(limit)) {
        el.textContent = t('admin.radar.max_mosaic_invalid', {
            min: data.max_mosaic_pixels_min / 1e6, max: data.max_mosaic_pixels_limit / 1e6
        });
        el.className = 'small mb-0 text-danger';
        document.getElementById('radarSaveBtn').disabled = true;
        return;
    }
    var tooLarge = px > limit;
    if (boxes.length === 0) {
        el.textContent = t('admin.settings.radar_no_region');
    } else {
        el.textContent = t('admin.settings.radar_size', {
            size: (px / 1e6).toLocaleString(undefined, { maximumFractionDigits: 1 }),
            max: (limit / 1e6).toLocaleString(undefined, { maximumFractionDigits: 0 })
        }) + (tooLarge ? ' ' + t('admin.settings.radar_too_large') : '');
    }
    el.className = 'small mb-0 ' + (tooLarge || boxes.length === 0 ? 'text-danger' : 'text-secondary');
    document.getElementById('radarSaveBtn').disabled = tooLarge || boxes.length === 0;
}

function radarSyncPresetRects() {
    if (!radarSettings.map) {
        return;
    }
    var presets = radarSettingsData().preset_regions;
    var checked = radarCheckedPresets();
    Object.keys(presets).forEach(function (code) {
        var on = checked.indexOf(code) >= 0;
        if (on && !radarSettings.presetRects[code]) {
            var p = presets[code];
            radarSettings.presetRects[code] = new google.maps.Rectangle({
                map: radarSettings.map,
                bounds: { south: p.south, west: p.west, north: p.north, east: p.east },
                strokeColor: RADAR_PRESET_COLOR, strokeWeight: 1, fillColor: RADAR_PRESET_COLOR, fillOpacity: 0.12,
                clickable: false
            });
        } else if (!on && radarSettings.presetRects[code]) {
            radarSettings.presetRects[code].setMap(null);
            delete radarSettings.presetRects[code];
        }
    });
}

function radarAddCustom(box, name) {
    var rect = new google.maps.Rectangle({
        map: radarSettings.map,
        bounds: box,
        editable: true,
        draggable: true,
        strokeColor: RADAR_CUSTOM_COLOR, strokeWeight: 2, fillColor: RADAR_CUSTOM_COLOR, fillOpacity: 0.15
    });
    var entry = { rect: rect, name: name || '' };
    rect.addListener('bounds_changed', function () {
        radarRenderCustomList();
        radarUpdateSize();
    });
    radarSettings.custom.push(entry);
    radarRenderCustomList();
    radarUpdateSize();
}

/** A new rectangle in the middle of the visible map, 20% of its width and height. */
function radarAddCustomInView() {
    if (!radarSettings.map) {
        return;
    }
    if (radarSettings.custom.length >= RADAR_MAX_CUSTOM) {
        showAdminToast(t('admin.settings.radar_max_custom', { max: RADAR_MAX_CUSTOM }), 'error');
        return;
    }
    var b = radarSettings.map.getBounds();
    var sw = b.getSouthWest();
    var ne = b.getNorthEast();
    var dLat = (ne.lat() - sw.lat()) * 0.1;
    var dLng = (ne.lng() - sw.lng()) * 0.1;
    var c = b.getCenter();
    radarAddCustom({ south: c.lat() - dLat, north: c.lat() + dLat, west: c.lng() - dLng, east: c.lng() + dLng }, '');
}

function radarRenderCustomList() {
    var list = document.getElementById('radarCustomList');
    list.textContent = '';
    radarSettings.custom.forEach(function (entry, i) {
        var box = radarRectBox(entry.rect);
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
        show.addEventListener('click', function () { radarSettings.map.fitBounds(entry.rect.getBounds()); });
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-outline-danger btn-sm';
        del.innerHTML = '<i class="bi bi-trash"></i>';
        del.title = t('admin.settings.radar_delete_region');
        del.setAttribute('aria-label', del.title);
        del.addEventListener('click', function () {
            entry.rect.setMap(null);
            radarSettings.custom.splice(radarSettings.custom.indexOf(entry), 1);
            radarRenderCustomList();
            radarUpdateSize();
        });
        li.append(name, coords, show, del);
        list.appendChild(li);
    });
}

function saveRadarSettings() {
    var btn = document.getElementById('radarSaveBtn');
    var custom = radarSettings.map
        ? radarSettings.custom.map(function (entry) {
            var box = radarRectBox(entry.rect);
            box.name = entry.name.trim();
            return box;
        })
        : radarSettingsData().custom; // no map: keep the stored ones
    var body = {
        history_minutes: parseInt(document.getElementById('radarHistoryMinutes').value, 10),
        forecast_minutes: parseInt(document.getElementById('radarForecastMinutes').value, 10),
        fetch_interval_minutes: parseInt(document.getElementById('radarFetchInterval').value, 10),
        workers: parseInt(document.getElementById('radarWorkers').value, 10),
        max_mosaic_pixels: radarMaxMosaicPixels(),
        presets: radarCheckedPresets(),
        custom: custom
    };
    btn.disabled = true;
    Ytan.put('/settings/radar', body).then(function (answer) {
        window.YTAN_RADAR_SETTINGS = Object.assign(radarSettingsData(), answer.data);
        renderRadarStatus();
        showAdminToast(t('admin.settings.radar_saved'), 'success');
    }).catch(function (err) {
        showAdminToast(t('common.save_failed', { error: err.data ? translateApiError(err.data) : err.message }), 'error');
    }).finally(function () {
        radarUpdateSize();
    });
}

/* ---- status ---------------------------------------------------------- */

function radarRegionLabel(name) {
    var key = 'admin.settings.radar_region.' + name;
    var label = t(key);
    return label === key ? (name || t('admin.settings.radar_region_name', { n: '' }).trim()) : label;
}

function radarFormatTime(iso) {
    if (!iso) {
        return '-';
    }
    var d = new Date(iso);
    return d.toLocaleString(window.YTAN_LOCALE || undefined, {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'
    });
}

/** End minus start ("1 min 12 s", "47 s"); "-" when one of them is missing. */
function radarFormatDuration(start, end) {
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
function radarFormatMb(bytes) {
    if (bytes === null || bytes === undefined) {
        return '-';
    }
    return t('admin.radar.mb', {
        mb: (bytes / 1048576).toLocaleString(window.YTAN_LOCALE || undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    });
}

function radarSetText(id, text) {
    document.getElementById(id).textContent = text;
}

/** Fills #radarStatusCard from radarSettingsData() (settings + status.json). */
function renderRadarStatus() {
    var data = radarSettingsData();
    var status = data.status || null;
    renderRadarPause();
    renderRadarRunning();

    var configured = (data.presets || []).map(radarRegionLabel)
        .concat((data.custom || []).map(function (c, i) { return c.name || t('admin.settings.radar_region_name', { n: i + 1 }); }));
    radarSetText('radarCurrentRegions', configured.length ? configured.join(', ') : '-');

    if (status && status.storage_bytes !== undefined) {
        radarSetText('radarStorageTotal', radarFormatMb(status.storage_bytes)
            + ' (' + t('admin.radar.measured_at', { time: radarFormatTime(status.storage_measured_at) }) + ')');
    } else {
        radarSetText('radarStorageTotal', '-');
    }

    var last = status && status.last_run;
    radarSetText('radarLastRun', last
        ? radarFormatTime(last.start) + ' (' + radarFormatDuration(last.start, last.end) + ') - '
            + t('admin.radar.outcome.' + last.outcome)
            + (last.error ? ': ' + last.error : '')
        : t('admin.radar.never_ran'));

    var p = status && status.last_processing;
    document.getElementById('radarNoProcessing').hidden = !!p;
    document.getElementById('radarProcessingTable').hidden = !p;
    if (!p) {
        return;
    }
    radarSetText('radarRunStart', radarFormatTime(p.start));
    radarSetText('radarRunEnd', radarFormatTime(p.end));
    radarSetText('radarRunDuration', radarFormatDuration(p.start, p.end));
    radarSetText('radarRunRegions', (p.regions || []).map(radarRegionLabel).join(', ') || '-');
    radarSetText('radarDownloadStart', radarFormatTime(p.download && p.download.start));
    radarSetText('radarDownloadEnd', radarFormatTime(p.download && p.download.end));
    radarSetText('radarDownloadDuration', p.download ? radarFormatDuration(p.download.start, p.download.end) : '-');
    radarSetText('radarDownloadSize', p.download
        ? radarFormatMb(p.download.bytes) + ' - ' + t('admin.radar.files', { n: p.download.files })
        : '-');
    radarSetText('radarProcessingStart', radarFormatTime(p.processing && p.processing.start));
    radarSetText('radarProcessingEnd', radarFormatTime(p.processing && p.processing.end));
    radarSetText('radarProcessingDuration', p.processing ? radarFormatDuration(p.processing.start, p.processing.end) : '-');
    radarSetText('radarProcessingWorkers', p.processing ? String(p.processing.workers) : '-');
    var fin = p.finishing || null; // missing in status.json files of older job versions
    radarSetText('radarFinishingStart', radarFormatTime(fin && fin.start));
    radarSetText('radarFinishingEnd', radarFormatTime(fin && fin.end));
    radarSetText('radarFinishingDuration', fin ? radarFormatDuration(fin.start, fin.end) : '-');
    radarSetText('radarProcessingSize', p.processing
        ? radarFormatMb(p.processing.bytes) + ' - ' + t('admin.radar.frames', { n: p.processing.frames })
        : '-');
}

var RADAR_POLL_MS = 4000;
var radarPollTimer = null;

/** Reloads the job status (GET /settings/radar); resolves with whether a run is in progress. */
function refreshRadarStatus(quiet) {
    var btn = document.getElementById('radarStatusRefresh');
    btn.disabled = true;
    return Ytan.get('/settings/radar').then(function (answer) {
        // only the status: unsaved edits in the settings card stay as they are
        radarSettingsData().status = answer.data.status;
        radarSettingsData().running = answer.data.running;
        // a timed pause may have run out meanwhile
        radarSettingsData().paused = answer.data.paused;
        radarSettingsData().paused_until = answer.data.paused_until;
        radarSettingsData().paused_forever = answer.data.paused_forever;
        renderRadarStatus();
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

/**
 * Every 15 seconds while the page is open (not in a background tab): has
 * a run started meanwhile, e.g. by cron? renderRadarRunning() then switches
 * to the 4-second polling until it is over. Errors stay quiet here - the
 * next check simply tries again.
 */
var RADAR_IDLE_CHECK_MS = 15000;

function radarIdleCheck() {
    if (document.hidden || radarPollTimer) {
        return; // background tab, or the faster polling of a running job is on
    }
    refreshRadarStatus(true);
}

/** "Datenabruf: aktiv / pausiert bis ... / dauerhaft abgeschaltet" and its buttons. */
function renderRadarPause() {
    var data = radarSettingsData();
    var state = document.getElementById('radarPauseState');
    var paused = !!data.paused;
    if (!paused) {
        state.textContent = t('admin.radar.fetching_active');
        state.className = 'small text-success';
    } else if (data.paused_forever) {
        state.textContent = t('admin.radar.fetching_off');
        state.className = 'small text-danger fw-semibold';
    } else {
        state.textContent = t('admin.radar.fetching_paused_until', { time: radarFormatTime(data.paused_until) });
        state.className = 'small text-warning-emphasis fw-semibold';
    }
    document.getElementById('radarResume').hidden = !paused;
}

function setRadarPause(value) {
    var request = value === null
        ? Ytan.del('/settings/radar/pause')
        : Ytan.post('/settings/radar/pause', value === 'forever' ? { forever: true } : { minutes: parseInt(value, 10) });
    request.then(function (answer) {
        var data = radarSettingsData();
        data.paused = answer.data.paused;
        data.paused_until = answer.data.paused_until;
        data.paused_forever = answer.data.paused_forever;
        renderRadarStatus();
        showAdminToast(t(value === null ? 'admin.radar.resumed' : 'admin.radar.paused_toast'), 'success');
    }).catch(function (err) {
        showAdminToast(err.data ? translateApiError(err.data) : err.message, 'error');
    });
}

/** "Läuft gerade" box and button state; while a run is in progress the status is polled. */
function renderRadarRunning() {
    var data = radarSettingsData();
    var box = document.getElementById('radarRunning');
    var btn = document.getElementById('radarRunNow');
    var running = !!data.running;
    btn.disabled = running || !!data.paused;
    btn.title = data.paused ? t('admin.radar.run_now_paused') : '';
    box.hidden = !running;
    if (running) {
        var info = (data.status && data.status.running) || null;
        box.textContent = info
            ? t('admin.radar.running_since', {
                time: radarFormatTime(info.start),
                trigger: t('admin.radar.trigger.' + (info.trigger === 'manual' ? 'manual' : 'cron'))
            })
            : t('admin.radar.running');
        radarStartPolling();
    }
}

function radarStartPolling() {
    if (radarPollTimer) {
        return;
    }
    radarPollTimer = setInterval(function () {
        refreshRadarStatus().then(function (running) {
            if (!running) {
                clearInterval(radarPollTimer);
                radarPollTimer = null;
                showAdminToast(t('admin.radar.run_finished'), 'success');
            }
        });
    }, RADAR_POLL_MS);
}

function startRadarRun() {
    var btn = document.getElementById('radarRunNow');
    btn.disabled = true;
    Ytan.post('/settings/radar/run', {}).then(function () {
        showAdminToast(t('admin.radar.run_started'), 'success');
        // the job takes a moment to start and take its lock
        setTimeout(function () {
            refreshRadarStatus().then(function () {
                radarSettingsData().running = true; // keep polling until the run is over
                renderRadarRunning();
            });
        }, 1500);
    }).catch(function (err) {
        showAdminToast(err.data ? translateApiError(err.data) : err.message, 'error');
        refreshRadarStatus();
    });
}

/* ---- init ------------------------------------------------------------- */

async function initRadarSettings() {
    document.querySelectorAll('#radarPresets input').forEach(function (box) {
        box.addEventListener('change', function () {
            radarSyncPresetRects();
            radarUpdateSize();
        });
    });
    document.getElementById('radarAddRegion').addEventListener('click', radarAddCustomInView);
    document.getElementById('radarMaxMosaic').addEventListener('input', radarUpdateSize);
    document.getElementById('radarSaveBtn').addEventListener('click', saveRadarSettings);
    radarUpdateSize();
    try {
        await loadAdminGoogleMaps();
    } catch (err) {
        document.getElementById('radarNoKey').hidden = false;
        document.getElementById('radarMap').hidden = true;
        document.getElementById('radarAddRegion').hidden = true;
        return;
    }
    radarSettings.map = new google.maps.Map(document.getElementById('radarMap'), {
        center: { lat: 57, lng: 14 },
        zoom: 4,
        gestureHandling: 'greedy',
        streetViewControl: false,
        fullscreenControl: false,
        clickableIcons: false
    });
    radarSyncPresetRects();
    radarSettingsData().custom.forEach(function (c) {
        radarAddCustom({ south: c.south, west: c.west, north: c.north, east: c.east }, c.name);
    });
}


function initRadarPage() {
    renderRadarStatus();
    document.getElementById('radarStatusRefresh').addEventListener('click', function () { refreshRadarStatus(); });
    document.getElementById('radarRunNow').addEventListener('click', startRadarRun);
    document.querySelectorAll('[data-radar-pause]').forEach(function (btn) {
        btn.addEventListener('click', function () { setRadarPause(btn.dataset.radarPause); });
    });
    document.getElementById('radarResume').addEventListener('click', function () { setRadarPause(null); });
    setInterval(radarIdleCheck, RADAR_IDLE_CHECK_MS);
    initRadarSettings();
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initRadarPage });
