/**
 * Logic for /admin/workers (templates/admin-workers.php): the registry of the YTAN workers (unique
 * names) and, per job, the priority list of who computes it. GET /settings/workers loads everything
 * (the lists, what the workers reported, who works on each job now), PUT /settings/workers saves
 * registry and lists together; POST /settings/workers/{id}/reset frees a name.
 *
 * All edits happen in `workersState` and are saved with one button; who "works" / is "ready" is what
 * the server computed when it was loaded - it is recomputed by saving.
 */
var WORKERS_REFRESH_MS = 30000;
var WORKERS_NAME = /^[a-z0-9][a-z0-9-]{0,31}$/;
var workersState = null;   // the server's answer, edited in place
var workersDirty = false;

function workersClone(value) {
    return JSON.parse(JSON.stringify(value));
}

function workersSetDirty(dirty) {
    workersDirty = dirty;
    document.getElementById('workersDirty').hidden = !dirty;
}

/** Name, label of a registered worker (or the server's entry) for the lists. */
function workersLabelOf(id) {
    if (id === 'server') {
        return t('admin.workers.server');
    }
    var worker = workersState.workers.find(function (w) { return w.id === id; });
    return worker && worker.label ? worker.label + ' (' + id + ')' : id;
}

function workersMinutesAgo(iso) {
    return Math.max(0, Math.round((Date.now() - new Date(iso)) / 60000));
}

/** What a worker has reported, as one cell of text (plain text parts, escaped when put together). */
function workersStateParts(report) {
    var parts = [];
    var status = report ? report.status : null;
    if (!status) {
        parts.push({ text: t('admin.workers.state_never'), cls: 'text-secondary' });
    } else {
        var host = status.host || '?';
        if (status.address) {
            host += ', ' + status.address;
        }
        parts.push({ text: t('admin.workers.state_contact', { min: workersMinutesAgo(status.tick), host: host }), cls: status.stale ? 'text-danger' : 'text-secondary' });
        if (status.stale) {
            parts.push({ text: t('admin.workers.state_stale'), cls: 'text-danger' });
        }
        if (status.running && status.running.job) {
            parts.push({ text: t('admin.workers.state_running', { job: status.running.job }), cls: 'text-primary' });
        }
        if (Array.isArray(status.capabilities)) {
            parts.push({ text: t('admin.workers.state_can', { jobs: status.capabilities.join(', ') || '-' }), cls: 'text-secondary' });
        }
    }
    if (report && report.conflict) {
        parts.push({ text: t('admin.workers.state_conflict', { host: report.conflict.host || '?' }), cls: 'text-danger fw-semibold' });
    }
    return parts;
}

function workersStateHtml(report) {
    return workersStateParts(report).map(function (p) {
        return '<div class="small ' + p.cls + '">' + escapeHTML(p.text) + '</div>';
    }).join('');
}

function workersRenderRegistered() {
    var body = document.getElementById('workersRegistered');
    body.innerHTML = workersState.workers.map(function (worker, index) {
        var report = workersState.reports[worker.id] || null;
        var resetButton = report
            ? '<button type="button" class="btn btn-outline-secondary btn-sm" data-workers-reset="' + escapeHTML(worker.id) + '" title="' + escapeHTML(t('admin.workers.reset_title')) + '"><i class="bi bi-arrow-counterclockwise"></i> <span class="d-none d-lg-inline">' + escapeHTML(t('admin.workers.reset')) + '</span></button> '
            : '';
        return '<tr>' +
            '<td><code>' + escapeHTML(worker.id) + '</code></td>' +
            '<td><input type="text" class="form-control form-control-sm" maxlength="60" data-workers-field="label" data-workers-index="' + index + '" value="' + escapeHTML(worker.label) + '" aria-label="' + escapeHTML(t('admin.workers.col_label')) + '"></td>' +
            '<td class="d-none d-md-table-cell"><input type="text" class="form-control form-control-sm" maxlength="200" data-workers-field="note" data-workers-index="' + index + '" value="' + escapeHTML(worker.note) + '" aria-label="' + escapeHTML(t('admin.workers.col_note')) + '"></td>' +
            '<td>' + workersStateHtml(report) + '</td>' +
            '<td class="text-nowrap">' + resetButton +
            '<button type="button" class="btn btn-outline-danger btn-sm" data-workers-remove="' + index + '" title="' + escapeHTML(t('admin.workers.remove_title')) + '"><i class="bi bi-trash"></i> <span class="d-none d-lg-inline">' + escapeHTML(t('admin.workers.remove')) + '</span></button></td>' +
            '</tr>';
    }).join('');
}

function workersRenderUnknown() {
    var registered = workersState.workers.map(function (w) { return w.id; });
    var strangers = Object.keys(workersState.reports).filter(function (id) { return registered.indexOf(id) === -1; });
    document.getElementById('workersUnknownCard').hidden = strangers.length === 0;
    document.getElementById('workersUnknown').innerHTML = strangers.map(function (id) {
        return '<li class="list-group-item d-flex flex-wrap align-items-center justify-content-between gap-2">' +
            '<div><code>' + escapeHTML(id) + '</code>' + workersStateHtml(workersState.reports[id]) + '</div>' +
            '<div class="text-nowrap">' +
            '<button type="button" class="btn btn-outline-primary btn-sm" data-workers-register="' + escapeHTML(id) + '">' + escapeHTML(t('admin.workers.register')) + '</button> ' +
            '<button type="button" class="btn btn-outline-secondary btn-sm" data-workers-reset="' + escapeHTML(id) + '" title="' + escapeHTML(t('admin.workers.reset_title')) + '">' + escapeHTML(t('admin.workers.reset')) + '</button>' +
            '</div></li>';
    }).join('');
}

/** How an entry of a job's list stands: working / ready / away / cannot / never reported / off - with the badge colour. */
function workersEntryState(job, entry) {
    if (!entry.enabled) {
        return { text: t('admin.workers.entry_off'), cls: 'text-bg-secondary' };
    }
    if (workersState.active[job] === entry.id) {
        return { text: t('admin.workers.entry_active'), cls: 'text-bg-success' };
    }
    if (entry.id === 'server') {
        return workersState.server.alive
            ? { text: t('admin.workers.server_alive'), cls: 'text-bg-info' }
            : { text: t('admin.workers.server_dead'), cls: 'text-bg-warning' };
    }
    var report = workersState.reports[entry.id];
    var status = report ? report.status : null;
    if (!status) {
        return { text: t('admin.workers.entry_never'), cls: 'text-bg-warning' };
    }
    if (status.stale) {
        return { text: t('admin.workers.entry_away'), cls: 'text-bg-warning' };
    }
    if (!Array.isArray(status.capabilities) || status.capabilities.indexOf(job) === -1) {
        return { text: t('admin.workers.entry_cannot'), cls: 'text-bg-warning' };
    }
    return { text: t('admin.workers.entry_ready'), cls: 'text-bg-info' };
}

function workersJobName(job) {
    switch (job) {
    case 'radar': return t('admin.workers.job_radar');
    case 'wind': return t('admin.workers.job_wind');
    default: return job;
    }
}

function workersRenderJobs() {
    var container = document.getElementById('workersJobs');
    container.innerHTML = workersState.jobs.map(function (job) {
        var list = workersState.job_workers[job] || [];
        var active = workersState.active[job];
        var activeLine = active
            ? '<span class="text-success">' + escapeHTML(t('admin.workers.active_now', { worker: workersLabelOf(active) })) + '</span>'
            : '<span class="text-danger">' + escapeHTML(t('admin.workers.active_none')) + '</span>';
        var rows = list.map(function (entry, index) {
            var state = workersEntryState(job, entry);
            return '<li class="list-group-item d-flex flex-wrap align-items-center gap-2" data-workers-job="' + escapeHTML(job) + '" data-workers-index="' + index + '">' +
                '<div class="btn-group btn-group-sm">' +
                '<button type="button" class="btn btn-outline-secondary" data-workers-move="-1" ' + (index === 0 ? 'disabled ' : '') + 'aria-label="' + escapeHTML(t('admin.workers.move_up')) + '"><i class="bi bi-arrow-up"></i></button>' +
                '<button type="button" class="btn btn-outline-secondary" data-workers-move="1" ' + (index === list.length - 1 ? 'disabled ' : '') + 'aria-label="' + escapeHTML(t('admin.workers.move_down')) + '"><i class="bi bi-arrow-down"></i></button>' +
                '</div>' +
                '<span class="badge text-bg-light border">' + (index + 1) + '</span>' +
                '<span class="flex-grow-1">' + escapeHTML(workersLabelOf(entry.id)) + '</span>' +
                '<span class="badge ' + state.cls + '">' + escapeHTML(state.text) + '</span>' +
                '<div class="form-check form-switch mb-0">' +
                '<input class="form-check-input" type="checkbox" role="switch" data-workers-enabled="1" id="workersEnabled-' + escapeHTML(job) + '-' + index + '"' + (entry.enabled ? ' checked' : '') + '>' +
                '<label class="form-check-label small" for="workersEnabled-' + escapeHTML(job) + '-' + index + '">' + escapeHTML(t('admin.workers.enabled')) + '</label></div>' +
                '<button type="button" class="btn btn-outline-danger btn-sm" data-workers-remove-entry="1" aria-label="' + escapeHTML(t('admin.workers.remove_entry')) + '" title="' + escapeHTML(t('admin.workers.remove_entry')) + '"><i class="bi bi-x-lg"></i></button>' +
                '</li>';
        }).join('');
        var inList = list.map(function (e) { return e.id; });
        var candidates = ['server'].concat(workersState.workers.map(function (w) { return w.id; })).filter(function (id) { return inList.indexOf(id) === -1; });
        var full = list.length >= workersState.limits.max_list;
        var options = '<option value="">' + escapeHTML(t('admin.workers.add_entry_choose')) + '</option>' + candidates.map(function (id) {
            return '<option value="' + escapeHTML(id) + '">' + escapeHTML(workersLabelOf(id)) + '</option>';
        }).join('');
        return '<div class="card mb-3" data-workers-card="' + escapeHTML(job) + '"><div class="card-body">' +
            '<div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><h3 class="h6 mb-0">' + escapeHTML(workersJobName(job)) + '</h3><div class="small">' + activeLine + '</div></div>' +
            (list.length ? '<ul class="list-group mb-2">' + rows + '</ul>' : '<p class="small text-secondary">' + escapeHTML(t('admin.workers.empty_list')) + '</p>') +
            '<div class="input-group input-group-sm">' +
            '<select class="form-select" data-workers-add-select="' + escapeHTML(job) + '"' + (full || candidates.length === 0 ? ' disabled' : '') + ' aria-label="' + escapeHTML(t('admin.workers.add_entry')) + '">' + options + '</select>' +
            '<button type="button" class="btn btn-outline-primary" data-workers-add-entry="' + escapeHTML(job) + '"' + (full || candidates.length === 0 ? ' disabled' : '') + '>' + escapeHTML(t('admin.workers.add_entry')) + '</button></div>' +
            (full ? '<div class="small text-secondary mt-1">' + escapeHTML(t('admin.workers.list_full')) + '</div>' : '') +
            '</div></div>';
    }).join('');
}

function workersRender() {
    workersRenderRegistered();
    workersRenderUnknown();
    workersRenderJobs();
}

function workersLoad(quiet) {
    return Ytan.get('/settings/workers').then(function (answer) {
        workersState = workersClone(answer.data);
        workersSetDirty(false);
        workersRender();
    }).catch(function (err) {
        if (quiet !== true) {
            showAdminToast(t('common.save_failed', { error: err.data ? translateApiError(err.data) : err.message }), 'error');
        }
    });
}

/** Only what the server needs: the registry and the lists. */
function workersPayload() {
    return {
        workers: workersState.workers.map(function (w) { return { id: w.id, label: w.label, note: w.note }; }),
        job_workers: workersState.job_workers
    };
}

function workersSave() {
    var btn = document.getElementById('workersSave');
    btn.disabled = true;
    Ytan.put('/settings/workers', workersPayload()).then(function (answer) {
        workersState = workersClone(answer.data);
        workersSetDirty(false);
        workersRender();
        showAdminToast(t('admin.workers.saved'), 'success');
    }).catch(function (err) {
        showAdminToast(t('common.save_failed', { error: err.data ? translateApiError(err.data) : err.message }), 'error');
    }).finally(function () {
        btn.disabled = false;
    });
}

/** Registers a name (the form's fields or a stranger's name) - saved with the save button. */
function workersRegister(id, label, note) {
    if (!WORKERS_NAME.test(id) || id === 'server') {
        showAdminToast(t('admin.workers.invalid_name'), 'error');
        return false;
    }
    if (workersState.workers.some(function (w) { return w.id === id; })) {
        showAdminToast(t('admin.workers.name_taken'), 'error');
        return false;
    }
    workersState.workers.push({ id: id, label: label, note: note });
    workersSetDirty(true);
    workersRender();
    return true;
}

function workersRemove(index) {
    var id = workersState.workers[index].id;
    workersState.workers.splice(index, 1);
    Object.keys(workersState.job_workers).forEach(function (job) {
        workersState.job_workers[job] = workersState.job_workers[job].filter(function (e) { return e.id !== id; });
        if (workersState.job_workers[job].length === 0) {
            workersState.job_workers[job] = [{ id: 'server', enabled: true }];
        }
    });
    workersSetDirty(true);
    workersRender();
}

function workersReset(id) {
    if (!window.confirm(t('admin.workers.reset_confirm', { worker: id }))) {
        return;
    }
    Ytan.post('/settings/workers/' + encodeURIComponent(id) + '/reset', {}).then(function (answer) {
        // only the reports: unsaved edits of the registry and the lists stay as they are
        workersState.reports = answer.data.reports;
        workersState.active = answer.data.active;
        workersRender();
        showAdminToast(t('admin.workers.reset_done'), 'success');
    }).catch(function (err) {
        showAdminToast(t('common.save_failed', { error: err.data ? translateApiError(err.data) : err.message }), 'error');
    });
}

/** The list entry a click or change came from: {job, index}. */
function workersEntryOf(target) {
    var li = target.closest('[data-workers-job]');
    return li ? { job: li.dataset.workersJob, index: parseInt(li.dataset.workersIndex, 10) } : null;
}

function initWorkersPage() {
    document.getElementById('workersSave').addEventListener('click', workersSave);
    document.getElementById('workersAdd').addEventListener('click', function () {
        var idField = document.getElementById('workersNewId');
        var label = document.getElementById('workersNewLabel');
        var note = document.getElementById('workersNewNote');
        if (workersRegister(idField.value.trim(), label.value.trim(), note.value.trim())) {
            idField.value = '';
            label.value = '';
            note.value = '';
        }
    });
    document.addEventListener('input', function (event) {
        var field = event.target.dataset ? event.target.dataset.workersField : null;
        if (field) {
            workersState.workers[parseInt(event.target.dataset.workersIndex, 10)][field] = event.target.value;
            workersSetDirty(true);
        }
    });
    document.addEventListener('change', function (event) {
        if (event.target.dataset && event.target.dataset.workersEnabled) {
            var entry = workersEntryOf(event.target);
            workersState.job_workers[entry.job][entry.index].enabled = event.target.checked;
            workersSetDirty(true);
            workersRenderJobs();
        }
    });
    document.addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button || !workersState) {
            return;
        }
        var data = button.dataset;
        if (data.workersMove) {
            var entry = workersEntryOf(button);
            var list = workersState.job_workers[entry.job];
            var to = entry.index + parseInt(data.workersMove, 10);
            if (to >= 0 && to < list.length) {
                list.splice(to, 0, list.splice(entry.index, 1)[0]);
                workersSetDirty(true);
                workersRenderJobs();
            }
        } else if (data.workersRemoveEntry) {
            var removed = workersEntryOf(button);
            workersState.job_workers[removed.job].splice(removed.index, 1);
            if (workersState.job_workers[removed.job].length === 0) {
                workersState.job_workers[removed.job] = [{ id: 'server', enabled: true }];
            }
            workersSetDirty(true);
            workersRenderJobs();
        } else if (data.workersAddEntry) {
            var select = document.querySelector('[data-workers-add-select="' + data.workersAddEntry + '"]');
            if (select && select.value) {
                workersState.job_workers[data.workersAddEntry].push({ id: select.value, enabled: true });
                workersSetDirty(true);
                workersRenderJobs();
            }
        } else if (data.workersRemove) {
            workersRemove(parseInt(data.workersRemove, 10));
        } else if (data.workersReset) {
            workersReset(data.workersReset);
        } else if (data.workersRegister) {
            var stranger = workersState.reports[data.workersRegister];
            workersRegister(data.workersRegister, '', stranger && stranger.status && stranger.status.host ? stranger.status.host : '');
        }
    });
    workersLoad();
    // what the workers reported changes by itself; not while there are unsaved edits or in a background tab
    setInterval(function () {
        if (!document.hidden && !workersDirty) {
            workersLoad(true);
        }
    }, WORKERS_REFRESH_MS);
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initWorkersPage });
