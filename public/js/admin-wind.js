/**
 * Logic for the wind card on /admin/radar (templates/admin-radar.php,
 * #windCard): the wind job (tools/wind/). Loaded after admin-radar.js, whose
 * helpers (radarFormatTime(), radarFormatDuration(), radarFormatMb()) it
 * reuses; initWindCard() is called from there once the admin is signed in.
 *
 * Status: what the job wrote to storage/wind/status.json, reloaded with
 * GET /settings/wind. Settings: PUT /settings/wind, which also hands them to
 * the job (storage/radar/config.json, "wind" object). The regions are the
 * rain radar's.
 */
var WIND_POLL_MS = 4000;
var windPollTimer = null;

function windData() {
    return window.YTAN_WIND_SETTINGS || { status: null, running: false };
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
        ? radarFormatTime(last.end) + ' - ' + windOutcomeLabel(last.outcome) + (last.error ? ' (' + last.error + ')' : '')
        : t('admin.radar.never_ran'));
    windSetText('windModelRuns', proc
        ? 'ICON-EU ' + windRunLabel(proc.eu_run) + (proc.d2_run ? ', ICON-D2 ' + windRunLabel(proc.d2_run) : '')
        : t('admin.wind.no_run'));
    windSetText('windLastProcessed', proc ? radarFormatTime(proc.end) + ' - ' + t('admin.wind.hours_count', { n: proc.hours }) : '-');
    windSetText('windDuration', proc ? radarFormatDuration(proc.start, proc.end) : '-');
    windSetText('windDownload', proc
        ? radarFormatMb(proc.bytes_downloaded) + ', ' + t('admin.radar.files', { n: proc.files_downloaded })
        : '-');
    windSetText('windWritten', proc ? radarFormatMb(proc.bytes_written) : '-');
    windSetText('windStorage', status && status.storage_bytes !== undefined
        ? radarFormatMb(status.storage_bytes) + ' (' + t('admin.radar.measured_at', { time: radarFormatTime(status.storage_measured_at) }) + ')'
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
        fine: document.getElementById('windFine').checked
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
    });
}

function initWindCard() {
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
