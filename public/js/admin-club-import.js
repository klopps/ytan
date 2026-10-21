/**
 * Logic for the /admin/club-import tool (templates/admin-club-import.php):
 * lists the staged canoe/rowing clubs (server-side filtered and paginated),
 * keeps a selection across pages and filter changes, loads a country from
 * OpenStreetMap, imports the selection as POIs and moves the staged list
 * between systems as a file. Login-gate handled by admin-auth.js.
 */

var ciState = {
    page: 1,
    pageSize: 50,
    total: 0,
    open: 0, // matches that are not imported yet - what "select all" selects
    rows: [],
    selected: new Set(),
    filterTimer: null,
};

function ciEscape(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    // Quotes too: the result also goes into attributes, and the data comes from outside (OpenStreetMap).
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function ciFilters() {
    var filters = {};
    document.querySelectorAll('#ciFilterForm [data-filter]').forEach(function (input) {
        var value = input.value.trim();
        if (value !== '') {
            filters[input.dataset.filter] = value;
        }
    });
    return filters;
}

function ciQuery(filters, extra) {
    var text = new URLSearchParams(Object.assign({}, filters, extra || {})).toString();
    return text === '' ? '' : '?' + text;
}

/** A website link only when it is an http(s) URL (the data comes from OpenStreetMap). */
function ciWebsiteLink(url) {
    if (!/^https?:\/\//i.test(url || '')) {
        return '';
    }
    return '<a href="' + ciEscape(url) + '" target="_blank" rel="noopener noreferrer">' + ciEscape(url.replace(/^https?:\/\/(www\.)?/i, '').replace(/\/$/, '')) + '</a>';
}

async function ciLoadEntries() {
    try {
        var answer = await Ytan.get('/admin/club-import' + ciQuery(ciFilters(), {
            limit: ciState.pageSize,
            offset: (ciState.page - 1) * ciState.pageSize,
        }));
        ciState.rows = answer.data;
        ciState.total = answer.meta.total;
        ciState.open = answer.meta.open;
        ciFillCountries(answer.meta.countries || []);
        ciRender();
    } catch (err) {
        document.getElementById('ciRows').innerHTML =
            '<tr><td colspan="7" class="text-danger">' + ciEscape(t('admin.club_import.load_failed', { error: err.message })) + '</td></tr>';
    }
}

/** Fills the country filter once per set of countries (keeps the chosen value). */
function ciFillCountries(countries) {
    var select = document.getElementById('ciFilterCountry');
    if (select.options.length - 1 === countries.length) {
        return;
    }
    var current = select.value;
    select.innerHTML = '<option value="">' + ciEscape(t('admin.club_import.filter_all')) + '</option>' +
        countries.map(function (code) { return '<option value="' + ciEscape(code) + '">' + ciEscape(code) + '</option>'; }).join('');
    select.value = current;
}

function ciKindName(kind) {
    return t('admin.club_import.kind_' + (kind === 'rowing' || kind === 'both' ? kind : 'canoe'));
}

function ciRender() {
    var body = document.getElementById('ciRows');
    if (ciState.rows.length === 0) {
        body.innerHTML = '<tr><td colspan="7" class="text-secondary">' + ciEscape(t('admin.club_import.no_results')) + '</td></tr>';
    } else {
        body.innerHTML = ciState.rows.map(function (row) {
            var imported = row.poi_id !== null;
            return '<tr>' +
                '<td><input type="checkbox" class="form-check-input ciRowCheckbox" data-id="' + row.id + '"' +
                    (imported ? ' disabled' : '') + (ciState.selected.has(Number(row.id)) ? ' checked' : '') + '></td>' +
                '<td>' + ciEscape(row.name) + '</td>' +
                '<td class="small text-nowrap">' + ciEscape(ciKindName(row.kind)) + '</td>' +
                '<td class="small">' + ciEscape(row.country) + '</td>' +
                '<td class="small">' + ciEscape(row.city) + '</td>' +
                '<td class="small">' + ciWebsiteLink(row.website) + '</td>' +
                '<td>' + (imported
                    ? '<span class="badge text-bg-success">' + ciEscape(t('admin.lighthouse_import.status_imported')) + '</span>'
                    : '<span class="badge text-bg-secondary">' + ciEscape(t('admin.lighthouse_import.status_open')) + '</span>') + '</td>' +
            '</tr>';
        }).join('');
    }

    var totalPages = Math.max(1, Math.ceil(ciState.total / ciState.pageSize));
    document.getElementById('ciPagination').innerHTML =
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="ciPrev"' + (ciState.page <= 1 ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>' +
        '<span class="small text-secondary">' + ciEscape(ciState.total === 0 ? t('admin.club_import.zero_results') : t('common.page_of', { page: ciState.page, total: totalPages }) + ' (' + ciState.total + ')') + '</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="ciNext"' + (ciState.page >= totalPages ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button>';

    ciUpdateSelectionUi();
}

function ciUpdateSelectionUi() {
    document.getElementById('ciSelectAllMatches').textContent = t('admin.club_import.select_all_matches', { count: ciState.open });
    document.getElementById('ciSelectedCount').textContent = t('admin.lighthouse_import.selected_count', { count: ciState.selected.size });
    document.getElementById('ciImportButton').disabled = ciState.selected.size === 0;

    var boxes = Array.prototype.slice.call(document.querySelectorAll('.ciRowCheckbox:not(:disabled)'));
    document.getElementById('ciTogglePage').checked = boxes.length > 0 && boxes.every(function (box) { return box.checked; });
}

function ciReload(resetPage) {
    if (resetPage) {
        ciState.page = 1;
    }
    ciLoadEntries();
}

async function ciSelectAllMatches() {
    try {
        // Imported entries can't be selected, whatever the status filter says.
        var answer = await Ytan.get('/admin/club-import/ids' + ciQuery(Object.assign({}, ciFilters(), { status: 'open' })));
        answer.data.forEach(function (id) { ciState.selected.add(Number(id)); });
        ciRender();
    } catch (err) {
        showAdminToast(t('admin.club_import.load_failed', { error: err.message }), 'error');
    }
}

async function ciImport() {
    var ids = Array.from(ciState.selected);
    if (ids.length === 0) {
        return;
    }
    if (!(await adminConfirm({
        title: t('admin.lighthouse_import.confirm_import_title'),
        message: t('admin.club_import.confirm_import', { count: ids.length }),
        confirmLabel: t('admin.lighthouse_import.confirm_import_button'),
    }))) {
        return;
    }

    var button = document.getElementById('ciImportButton');
    button.disabled = true;
    try {
        var answer = await Ytan.post('/admin/club-import', { ids: ids, public: document.getElementById('ciPublic').checked });
        ciState.selected.clear();
        showAdminToast(t('admin.club_import.import_result', {
            imported: answer.data.imported,
            linked: answer.data.linked,
            skipped: answer.data.skipped,
        }), 'success');
        ciLoadEntries();
    } catch (err) {
        showAdminToast(t('admin.club_import.import_failed', { error: translateApiError(err.data) || err.message }), 'error');
        button.disabled = false;
    }
}

/** Loads the clubs of the chosen country from OpenStreetMap into the list (can take a minute for big countries). */
async function ciLoadOsm() {
    var button = document.getElementById('ciOsmLoad');
    button.disabled = true;
    button.textContent = t('admin.club_import.osm_loading');
    try {
        var answer = await Ytan.post('/admin/club-import/osm', { country: document.getElementById('ciOsmCountry').value });
        showAdminToast(t('admin.club_import.osm_result', {
            fetched: answer.data.fetched,
            inserted: answer.data.inserted,
            updated: answer.data.updated,
            skipped: answer.data.skipped,
        }), 'success');
        ciReload(true);
    } catch (err) {
        showAdminToast(t('admin.club_import.osm_failed', { error: translateApiError(err.data) || err.message }), 'error');
    } finally {
        button.disabled = false;
        button.innerHTML = '<i class="bi bi-cloud-download"></i> ' + ciEscape(t('admin.club_import.osm_load'));
    }
}

/** Downloads every staged entry as a transfer file (read by ciLoadFile() on another system). */
async function ciExport() {
    try {
        var answer = await Ytan.get('/admin/club-import/export');
        var blob = new Blob([JSON.stringify(answer.data)], { type: 'application/json' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'club-import-' + new Date().toISOString().slice(0, 10) + '.json';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
        showAdminToast(t('admin.club_import.export_done', { count: answer.data.entries.length }), 'success');
    } catch (err) {
        showAdminToast(t('admin.club_import.export_failed', { error: err.message }), 'error');
    }
}

/** Reads a transfer file chosen by the admin and adds/refreshes its entries in the staging table. */
async function ciLoadFile(file) {
    var data;
    try {
        data = JSON.parse(await file.text());
    } catch (err) {
        showAdminToast(t('admin.club_import.file_invalid'), 'error');
        return;
    }
    var count = Array.isArray(data.entries) ? data.entries.length : 0;
    if (!(await adminConfirm({
        title: t('admin.lighthouse_import.confirm_load_file_title'),
        message: t('admin.club_import.confirm_load_file', { count: count, name: file.name }),
        confirmLabel: t('admin.lighthouse_import.confirm_load_file_button'),
    }))) {
        return;
    }

    try {
        var answer = await Ytan.post('/admin/club-import/file', data);
        showAdminToast(t('admin.club_import.file_loaded', { inserted: answer.data.inserted, updated: answer.data.updated }), 'success');
        ciReload(true);
    } catch (err) {
        showAdminToast(t('admin.club_import.file_failed', { error: translateApiError(err.data) || err.message }), 'error');
    }
}

function ciInit() {
    adminRememberTab('ciTabs', 'ytan_club_import_tab');
    document.querySelectorAll('#ciFilterForm [data-filter]').forEach(function (input) {
        input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', function () {
            clearTimeout(ciState.filterTimer);
            ciState.filterTimer = setTimeout(function () { ciReload(true); }, 300);
        });
    });
    document.getElementById('ciPageSize').addEventListener('change', function (event) {
        ciState.pageSize = parseInt(event.target.value, 10);
        ciReload(true);
    });

    document.getElementById('ciRows').addEventListener('change', function (event) {
        if (!event.target.classList.contains('ciRowCheckbox')) {
            return;
        }
        var id = Number(event.target.dataset.id);
        if (event.target.checked) {
            ciState.selected.add(id);
        } else {
            ciState.selected.delete(id);
        }
        ciUpdateSelectionUi();
    });
    document.getElementById('ciTogglePage').addEventListener('change', function (event) {
        document.querySelectorAll('.ciRowCheckbox:not(:disabled)').forEach(function (box) {
            box.checked = event.target.checked;
            var id = Number(box.dataset.id);
            if (event.target.checked) {
                ciState.selected.add(id);
            } else {
                ciState.selected.delete(id);
            }
        });
        ciUpdateSelectionUi();
    });
    document.getElementById('ciSelectAllMatches').addEventListener('click', ciSelectAllMatches);
    document.getElementById('ciSelectNone').addEventListener('click', function () {
        ciState.selected.clear();
        ciRender();
    });
    document.getElementById('ciPagination').addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button || button.disabled) {
            return;
        }
        ciState.page += button.id === 'ciNext' ? 1 : -1;
        ciLoadEntries();
    });

    document.getElementById('ciOsmForm').addEventListener('submit', ciLoadOsm);
    document.getElementById('ciExportButton').addEventListener('click', ciExport);
    document.getElementById('ciFileButton').addEventListener('click', function () {
        document.getElementById('ciFileInput').click();
    });
    document.getElementById('ciFileInput').addEventListener('change', function (event) {
        var file = event.target.files[0];
        event.target.value = '';
        if (file) {
            ciLoadFile(file);
        }
    });
    document.getElementById('ciImportButton').addEventListener('click', ciImport);

    ciLoadEntries();
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: ciInit });
