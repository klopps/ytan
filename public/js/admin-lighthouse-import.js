/**
 * Logic for the /admin/lighthouse-import tool (templates/admin-lighthouse-import.php):
 * lists the staged entries of the official lighthouse lists (server-side
 * filtered and paginated), keeps a selection across pages and filter changes,
 * and imports the selection as lighthouse POIs. An entry whose international
 * number already belongs to a lighthouse is reported by the server first; the
 * admin then chooses to overwrite those, skip them, or cancel.
 * Login-gate handled by admin-auth.js; loadEntries() runs as its onReady callback.
 */

var liState = {
    page: 1,
    pageSize: 50,
    total: 0,
    rows: [],
    selected: new Set(),
    filterTimer: null,
    pendingImportIds: [],
};

function liEscape(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

function liFilters() {
    var filters = {};
    document.querySelectorAll('#liFilterForm [data-filter]').forEach(function (input) {
        var value = input.value.trim();
        if (value !== '') {
            filters[input.dataset.filter] = value;
        }
    });
    return filters;
}

function liQuery(filters, extra) {
    var params = new URLSearchParams(Object.assign({}, filters, extra || {}));
    var text = params.toString();
    return text === '' ? '' : '?' + text;
}

async function loadEntries() {
    try {
        var answer = await Ytan.get('/admin/lighthouse-import' + liQuery(liFilters(), {
            limit: liState.pageSize,
            offset: (liState.page - 1) * liState.pageSize,
        }));
        liState.rows = answer.data;
        liState.total = answer.meta.total;
        liFillOptions('liRegionList', answer.meta.regions || []);
        liFillOptions('liSubregionList', answer.meta.subregions || []);
        liRender();
    } catch (err) {
        document.getElementById('liRows').innerHTML =
            '<tr><td colspan="10" class="text-danger">' + liEscape(t('admin.lighthouse_import.load_failed', { error: err.message })) + '</td></tr>';
    }
}

/** Fills a filter's suggestion list (<datalist>) once. */
function liFillOptions(listId, values) {
    var list = document.getElementById(listId);
    if (list.children.length === values.length) {
        return;
    }
    list.innerHTML = values.map(function (value) {
        return '<option value="' + liEscape(value) + '"></option>';
    }).join('');
}

function liRender() {
    var body = document.getElementById('liRows');
    if (liState.rows.length === 0) {
        body.innerHTML = '<tr><td colspan="10" class="text-secondary">' + liEscape(t('admin.lighthouse_import.no_results')) + '</td></tr>';
    } else {
        body.innerHTML = liState.rows.map(function (row) {
            var imported = row.poi_id !== null;
            var sectors = row.sector_characteristic
                ? '<span class="font-monospace small text-nowrap" title="' + liEscape(row.remarks || '') + '">' + liEscape(row.sector_characteristic).replace(/\n/g, '<br>') + '</span>'
                : '';
            if (Number(row.sector_missing_range) === 1) {
                sectors += ' <i class="bi bi-exclamation-triangle text-warning" title="' + liEscape(t('admin.lighthouse_import.sector_missing_range')) + '"></i>';
            }
            return '<tr>' +
                '<td><input type="checkbox" class="form-check-input liRowCheckbox" data-id="' + row.id + '"' +
                    (imported ? ' disabled' : '') + (liState.selected.has(Number(row.id)) ? ' checked' : '') + '></td>' +
                '<td class="text-nowrap small">' + liEscape(row.source === 'nga' ? t('admin.lighthouse_import.source_nga') : t('admin.lighthouse_import.source_dk')) + '</td>' +
                '<td class="text-nowrap">' + liEscape(row.dk_no) + '</td>' +
                '<td class="text-nowrap">' + liEscape(row.international_id) + '</td>' +
                '<td>' + liEscape(row.name) + '</td>' +
                '<td class="small">' + liEscape(row.location) + '</td>' +
                '<td class="small">' + liEscape(row.region) + (row.subregion ? '<br><span class="text-secondary">' + liEscape(row.subregion) + '</span>' : '') + '</td>' +
                '<td class="text-nowrap">' + liEscape(row.characteristic) + '</td>' +
                '<td>' + sectors + '</td>' +
                '<td>' + (imported
                    ? '<span class="badge text-bg-success">' + liEscape(t('admin.lighthouse_import.status_imported')) + '</span>'
                    : '<span class="badge text-bg-secondary">' + liEscape(t('admin.lighthouse_import.status_open')) + '</span>') + '</td>' +
            '</tr>';
        }).join('');
    }

    var totalPages = Math.max(1, Math.ceil(liState.total / liState.pageSize));
    document.getElementById('liPagination').innerHTML =
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="liPrev"' + (liState.page <= 1 ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>' +
        '<span class="small text-secondary">' + liEscape(liState.total === 0 ? t('admin.lighthouse_import.zero_results') : t('common.page_of', { page: liState.page, total: totalPages }) + ' (' + liState.total + ')') + '</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="liNext"' + (liState.page >= totalPages ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button>';

    liUpdateSelectionUi();
}

function liUpdateSelectionUi() {
    document.getElementById('liSelectAllMatches').textContent = t('admin.lighthouse_import.select_all_matches', { count: liState.total });
    document.getElementById('liSelectedCount').textContent = t('admin.lighthouse_import.selected_count', { count: liState.selected.size });
    document.getElementById('liImportButton').disabled = liState.selected.size === 0;

    var boxes = Array.prototype.slice.call(document.querySelectorAll('.liRowCheckbox:not(:disabled)'));
    var toggle = document.getElementById('liTogglePage');
    toggle.checked = boxes.length > 0 && boxes.every(function (box) { return box.checked; });
}

function liReload(resetPage) {
    if (resetPage) {
        liState.page = 1;
    }
    loadEntries();
}

async function liSelectAllMatches() {
    try {
        // Entries that are imported already can't be selected, whatever the status filter says.
        var answer = await Ytan.get('/admin/lighthouse-import/ids' + liQuery(Object.assign({}, liFilters(), { status: 'open' })));
        answer.data.forEach(function (id) { liState.selected.add(Number(id)); });
        liRender();
    } catch (err) {
        showAdminToast(t('admin.lighthouse_import.load_failed', { error: err.message }), 'error');
    }
}

/**
 * Imports the selection. The first call (no decisions) asks the server; if
 * some entries repeat an international number the server imports nothing and
 * returns them, liShowConflicts() lets the admin decide per entry, and
 * liConfirmConflicts() calls this again with those decisions.
 */
async function liImport(decisions) {
    var ids = decisions ? liState.pendingImportIds : Array.from(liState.selected);
    if (ids.length === 0) {
        return;
    }
    if (!decisions && !(await adminConfirm({
        title: t('admin.lighthouse_import.confirm_import_title'),
        message: t('admin.lighthouse_import.confirm_import', { count: ids.length }),
        confirmLabel: t('admin.lighthouse_import.confirm_import_button'),
    }))) {
        return;
    }

    try {
        var answer = await Ytan.post('/admin/lighthouse-import', {
            ids: ids,
            public: document.getElementById('liPublic').checked,
            decisions: decisions || {},
            default_ranges: {
                W: parseFloat(document.getElementById('liRangeW').value),
                R: parseFloat(document.getElementById('liRangeR').value),
                G: parseFloat(document.getElementById('liRangeG').value),
            },
        });
        var result = answer.data;

        if (result.conflicts.length > 0) {
            liState.pendingImportIds = ids;
            liShowConflicts(result.conflicts);
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('liConflictModal')).hide();
        liState.selected.clear();
        liState.pendingImportIds = [];
        showAdminToast(t('admin.lighthouse_import.import_result', {
            imported: result.imported,
            overwritten: result.overwritten,
            skipped: result.skipped,
        }), 'success');
        loadEntries();
    } catch (err) {
        showAdminToast(t('admin.lighthouse_import.import_failed', { error: err.message }), 'error');
    }
}

function liShowConflicts(conflicts) {
    document.getElementById('liConflictIntro').textContent = t('admin.lighthouse_import.conflict_intro', { count: conflicts.length });
    document.getElementById('liConflictAll').value = '';
    document.getElementById('liConflictList').innerHTML = conflicts.map(function (conflict) {
        var existing;
        if (conflict.existing_poi_id === null) {
            existing = t('admin.lighthouse_import.conflict_in_selection');
        } else {
            existing = t('admin.lighthouse_import.conflict_existing', { name: conflict.existing_name || '', id: conflict.existing_poi_id });
            if (conflict.existing_count > 1) {
                existing += ' ' + t('admin.lighthouse_import.conflict_more', { count: conflict.existing_count - 1 });
            }
        }
        // Skipping is the safe default: nothing changes unless the admin chooses otherwise.
        return '<div class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">' +
            '<div class="flex-grow-1"><strong>' + liEscape(conflict.international_id) + '</strong> ' + liEscape(conflict.name) +
                '<div class="small text-secondary">' + liEscape(existing) + '</div></div>' +
            '<select class="form-select form-select-sm w-auto liConflictAction" data-id="' + conflict.id + '">' +
                '<option value="skip">' + liEscape(t('admin.lighthouse_import.conflict_skip')) + '</option>' +
                '<option value="add">' + liEscape(t('admin.lighthouse_import.conflict_add')) + '</option>' +
                (conflict.existing_poi_id === null ? '' : '<option value="overwrite">' + liEscape(t('admin.lighthouse_import.conflict_overwrite')) + '</option>') +
            '</select>' +
        '</div>';
    }).join('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('liConflictModal')).show();
}

function liConfirmConflicts() {
    var decisions = {};
    document.querySelectorAll('.liConflictAction').forEach(function (select) {
        decisions[select.dataset.id] = select.value;
    });
    liImport(decisions);
}

function liApplyToAllConflicts(action) {
    if (action === '') {
        return;
    }
    document.querySelectorAll('.liConflictAction').forEach(function (select) {
        // "overwrite" doesn't exist for a number that only repeats within the selection.
        if (select.querySelector('option[value="' + action + '"]')) {
            select.value = action;
        }
    });
}

/** Downloads every staged entry as a transfer file (read by liLoadFile() on another system). */
async function liExport() {
    try {
        var answer = await Ytan.get('/admin/lighthouse-import/export');
        var blob = new Blob([JSON.stringify(answer.data)], { type: 'application/json' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'lighthouse-import-' + new Date().toISOString().slice(0, 10) + '.json';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
        showAdminToast(t('admin.lighthouse_import.export_done', { count: answer.data.entries.length }), 'success');
    } catch (err) {
        showAdminToast(t('admin.lighthouse_import.export_failed', { error: err.message }), 'error');
    }
}

/** Reads a transfer file chosen by the admin and adds/refreshes its entries in the staging table. */
async function liLoadFile(file) {
    var data;
    try {
        data = JSON.parse(await file.text());
    } catch (err) {
        showAdminToast(t('admin.lighthouse_import.file_invalid'), 'error');
        return;
    }
    var count = Array.isArray(data.entries) ? data.entries.length : 0;
    if (!(await adminConfirm({
        title: t('admin.lighthouse_import.confirm_load_file_title'),
        message: t('admin.lighthouse_import.confirm_load_file', { count: count, name: file.name }),
        confirmLabel: t('admin.lighthouse_import.confirm_load_file_button'),
    }))) {
        return;
    }

    try {
        var answer = await Ytan.post('/admin/lighthouse-import/file', data);
        showAdminToast(t('admin.lighthouse_import.file_loaded', { inserted: answer.data.inserted, updated: answer.data.updated }), 'success');
        liReload(true);
    } catch (err) {
        showAdminToast(t('admin.lighthouse_import.file_failed', { error: translateApiError(err.data) || err.message }), 'error');
    }
}

function liInit() {
    document.querySelectorAll('#liFilterForm [data-filter]').forEach(function (input) {
        var eventName = input.tagName === 'SELECT' ? 'change' : 'input';
        input.addEventListener(eventName, function () {
            clearTimeout(liState.filterTimer);
            liState.filterTimer = setTimeout(function () { liReload(true); }, 300);
        });
    });
    document.getElementById('liPageSize').addEventListener('change', function (event) {
        liState.pageSize = parseInt(event.target.value, 10);
        liReload(true);
    });

    document.getElementById('liRows').addEventListener('change', function (event) {
        if (!event.target.classList.contains('liRowCheckbox')) {
            return;
        }
        var id = Number(event.target.dataset.id);
        if (event.target.checked) {
            liState.selected.add(id);
        } else {
            liState.selected.delete(id);
        }
        liUpdateSelectionUi();
    });
    document.getElementById('liTogglePage').addEventListener('change', function (event) {
        document.querySelectorAll('.liRowCheckbox:not(:disabled)').forEach(function (box) {
            box.checked = event.target.checked;
            var id = Number(box.dataset.id);
            if (event.target.checked) {
                liState.selected.add(id);
            } else {
                liState.selected.delete(id);
            }
        });
        liUpdateSelectionUi();
    });
    document.getElementById('liSelectAllMatches').addEventListener('click', liSelectAllMatches);
    document.getElementById('liSelectNone').addEventListener('click', function () {
        liState.selected.clear();
        liRender();
    });

    document.getElementById('liPagination').addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button || button.disabled) {
            return;
        }
        liState.page += button.id === 'liNext' ? 1 : -1;
        loadEntries();
    });

    document.getElementById('liExportButton').addEventListener('click', liExport);
    document.getElementById('liFileButton').addEventListener('click', function () {
        document.getElementById('liFileInput').click();
    });
    document.getElementById('liFileInput').addEventListener('change', function (event) {
        var file = event.target.files[0];
        event.target.value = '';
        if (file) {
            liLoadFile(file);
        }
    });

    document.getElementById('liImportButton').addEventListener('click', function () { liImport(null); });
    document.getElementById('liConflictConfirm').addEventListener('click', liConfirmConflicts);
    document.getElementById('liConflictAll').addEventListener('change', function (event) {
        liApplyToAllConflicts(event.target.value);
    });

    loadEntries();
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: liInit });
