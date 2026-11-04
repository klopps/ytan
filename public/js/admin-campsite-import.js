/**
 * Logic for the /admin/campsite-import tool (templates/admin-campsite-import.php):
 * lists the staged campsites (server-side filtered and paginated) with their
 * possible duplicates, keeps a selection across pages and filter changes,
 * loads OpenStreetMap (one country; the server computes the distances to
 * water while loading), imports the selection as POIs and moves the staged
 * list between systems as a file. Login-gate handled by admin-auth.js.
 */

var ciState = {
    page: 1,
    pageSize: 50,
    total: 0,
    rows: [],
    selected: new Set(),
    filterTimer: null,
};

function ciEscape(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    // Quotes too: the result also goes into attributes, and the data comes from outside.
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

/** A link only when it is an http(s) URL (the data comes from outside). */
function ciLink(url) {
    if (!/^https?:\/\//i.test(url || '')) {
        return '';
    }
    return '<a href="' + ciEscape(url) + '" target="_blank" rel="noopener noreferrer">' +
        ciEscape(url.replace(/^https?:\/\/(www\.)?/i, '').replace(/\/$/, '').slice(0, 40)) + '</a>';
}

function ciMapLink(row) {
    var url = 'https://www.google.com/maps/search/?api=1&query=' + Number(row.latitude) + ',' + Number(row.longitude);
    return '<a href="' + ciEscape(url) + '" target="_blank" rel="noopener noreferrer" class="small" title="' +
        ciEscape(t('admin.shelter_import.show_on_map')) + '"><i class="bi bi-geo-alt"></i></a>';
}

/** "Meer 120 m · Fluss 3400 m · See –" (– = farther than 100 km or outside the index), or "not computed". */
function ciWaterText(row) {
    if (!row.water_checked_at) {
        return '<span class="text-secondary">' + ciEscape(t('admin.shelter_import.water_unchecked')) + '</span>';
    }
    return ['sea', 'river', 'lake'].map(function (kind) {
        var meters = row[kind + '_m'];
        return '<span class="text-nowrap">' + ciEscape(t('admin.shelter_import.water_' + kind)) + ' ' +
            ciEscape(meters === null ? '–' : t('admin.shelter_import.water_distance_value', { meters: meters })) + '</span>';
    }).join(' · ');
}

/** Tents/caravans/cabins as short words: only what OSM says yes to; "–" when nothing is tagged. */
function ciAllowedText(row) {
    var parts = ['tents', 'caravans', 'cabins'].filter(function (flag) {
        return row[flag] !== null && Number(row[flag]) === 1;
    }).map(function (flag) {
        return t('admin.campsite_import.flag_' + flag);
    });
    return parts.length === 0 ? '<span class="text-secondary">–</span>' : ciEscape(parts.join(', '));
}

/** A campsite or tent-site POI within ~220 m: a possible duplicate (badge + its name). */
function ciNeighboursText(neighbours) {
    if (!neighbours || neighbours.length === 0) {
        return '';
    }
    return '<span class="badge text-bg-warning">' + ciEscape(t('admin.shelter_import.duplicate_badge')) + '</span> ' +
        neighbours.map(function (n) {
            return '<span class="small">' + ciEscape(t('admin.shelter_import.neighbour_poi', { id: n.id }) + (n.name ? ': ' + n.name : '')) + '</span>';
        }).join('<br>');
}

function ciCategoryName(category) {
    return t('admin.campsite_import.category_' + (category === 'basic' ? 'basic' : 'campsite'));
}

async function ciLoadEntries() {
    try {
        var answer = await Ytan.get('/admin/campsite-import' + ciQuery(ciFilters(), {
            limit: ciState.pageSize,
            offset: (ciState.page - 1) * ciState.pageSize,
        }));
        ciState.rows = answer.data;
        ciState.total = answer.meta.total;
        ciFillCountries(answer.meta.countries || []);
        ciUpdateWaterInfo(answer.meta.water_index, answer.meta.unchecked);
        ciRender();
    } catch (err) {
        document.getElementById('ciRows').innerHTML =
            '<tr><td colspan="10" class="text-danger">' + ciEscape(t('admin.shelter_import.load_failed', { error: err.message })) + '</td></tr>';
    }
}

/** Which water index the server uses, and how many entries have no distances (loaded without an index). */
function ciUpdateWaterInfo(version, unchecked) {
    var info = document.getElementById('ciWaterInfo');
    var text = version === null || version === undefined
        ? t('admin.shelter_import.water_index_missing')
        : t('admin.shelter_import.water_index_version', { version: version || '?' });
    if (unchecked > 0) {
        text += ' ' + t('admin.shelter_import.water_unchecked_count', { count: unchecked });
    }
    info.textContent = text;
    info.className = 'small mb-0 ' + (version === null || version === undefined ? 'text-danger' : 'text-secondary');
}

/** Fills the country filter once per set of countries (keeps the chosen value). */
function ciFillCountries(countries) {
    var select = document.getElementById('ciFilterCountry');
    if (select.options.length - 1 === countries.length) {
        return;
    }
    var current = select.value;
    select.innerHTML = '<option value="">' + ciEscape(t('admin.shelter_import.filter_all')) + '</option>' +
        countries.map(function (code) { return '<option value="' + ciEscape(code) + '">' + ciEscape(code) + '</option>'; }).join('');
    select.value = current;
}

function ciRender() {
    var body = document.getElementById('ciRows');
    if (ciState.rows.length === 0) {
        body.innerHTML = '<tr><td colspan="10" class="text-secondary">' + ciEscape(t('admin.campsite_import.no_results')) + '</td></tr>';
    } else {
        body.innerHTML = ciState.rows.map(function (row) {
            var imported = row.poi_id !== null;
            return '<tr>' +
                '<td><input type="checkbox" class="form-check-input ciRowCheckbox" data-id="' + row.id + '"' +
                    (imported ? ' disabled' : '') + (ciState.selected.has(Number(row.id)) ? ' checked' : '') + '></td>' +
                '<td>' + ciEscape(row.name) + ' ' + ciMapLink(row) + '</td>' +
                '<td class="small text-nowrap">' + ciEscape(ciCategoryName(row.category)) + '</td>' +
                '<td class="small">' + ciEscape(row.place) + '</td>' +
                '<td class="small">' + ciEscape(row.country) + '</td>' +
                '<td class="small">' + ciWaterText(row) + '</td>' +
                '<td class="small">' + ciAllowedText(row) + '</td>' +
                '<td class="small">' + ciLink(row.website) + '</td>' +
                '<td>' + ciNeighboursText(row.neighbours) + '</td>' +
                '<td>' + (imported
                    ? '<span class="badge text-bg-success">' + ciEscape(t('admin.lighthouse_import.status_imported')) + '</span>'
                    : '<span class="badge text-bg-secondary">' + ciEscape(t('admin.lighthouse_import.status_open')) + '</span>') + '</td>' +
            '</tr>';
        }).join('');
    }

    var totalPages = Math.max(1, Math.ceil(ciState.total / ciState.pageSize));
    document.getElementById('ciPagination').innerHTML =
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="ciPrev"' + (ciState.page <= 1 ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>' +
        '<span class="small text-secondary">' + ciEscape(ciState.total === 0 ? t('admin.shelter_import.zero_results') : t('common.page_of', { page: ciState.page, total: totalPages }) + ' (' + ciState.total + ')') + '</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="ciNext"' + (ciState.page >= totalPages ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button>';

    ciUpdateSelectionUi();
}

function ciUpdateSelectionUi() {
    document.getElementById('ciSelectAllMatches').textContent = document.getElementById('ciFilterDuplicate').value === ''
        ? t('admin.campsite_import.select_all_matches')
        : t('admin.shelter_import.select_all_matches_filtered');
    document.getElementById('ciSelectedCount').textContent = t('admin.shelter_import.selected_of', { count: ciState.selected.size, total: ciState.total });
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

/**
 * Selects every open match of the current filters (e.g. "sea at most 300 m").
 * Without a choice in the duplicate filter only entries without a campsite
 * or tent-site POI nearby; with a choice exactly the filter's matches.
 */
async function ciSelectAllMatches() {
    try {
        var filters = Object.assign({ duplicate: 'no' }, ciFilters(), { status: 'open' });
        var answer = await Ytan.get('/admin/campsite-import/ids' + ciQuery(filters));
        answer.data.forEach(function (id) { ciState.selected.add(Number(id)); });
        showAdminToast(t('admin.shelter_import.selected_all_result', { count: answer.data.length }), 'success');
        ciRender();
    } catch (err) {
        showAdminToast(t('admin.shelter_import.load_failed', { error: err.message }), 'error');
    }
}

async function ciImport() {
    var ids = Array.from(ciState.selected);
    if (ids.length === 0) {
        return;
    }
    if (!(await adminConfirm({
        title: t('admin.lighthouse_import.confirm_import_title'),
        message: t('admin.campsite_import.confirm_import', { count: ids.length }),
        confirmLabel: t('admin.lighthouse_import.confirm_import_button'),
    }))) {
        return;
    }

    var button = document.getElementById('ciImportButton');
    button.disabled = true;
    try {
        var answer = await Ytan.post('/admin/campsite-import', { ids: ids, public: document.getElementById('ciPublic').checked });
        ciState.selected.clear();
        showAdminToast(t('admin.campsite_import.import_result', { imported: answer.data.imported, skipped: answer.data.skipped }), 'success');
        ciLoadEntries();
    } catch (err) {
        showAdminToast(t('admin.shelter_import.import_failed', { error: translateApiError(err.data) || err.message }), 'error');
        button.disabled = false;
    }
}

/** Loads all campsites of the chosen country from OpenStreetMap (can take minutes for big countries). */
async function ciLoadOsm() {
    var button = document.getElementById('ciOsmLoad');
    button.disabled = true;
    button.textContent = t('admin.shelter_import.loading');
    try {
        var answer = await Ytan.post('/admin/campsite-import/osm', { country: document.getElementById('ciOsmCountry').value });
        showAdminToast(t('admin.campsite_import.load_result', {
            fetched: answer.data.fetched,
            skipped: answer.data.skipped,
            inserted: answer.data.inserted,
            updated: answer.data.updated,
        }), 'success');
        ciReload(true);
    } catch (err) {
        showAdminToast(t('admin.shelter_import.load_source_failed', { error: translateApiError(err.data) || err.message }), 'error');
    } finally {
        button.disabled = false;
        button.innerHTML = '<i class="bi bi-cloud-download"></i> ' + ciEscape(t('admin.campsite_import.osm_load'));
    }
}

/** Downloads every staged entry as a transfer file (read by ciLoadFile() on another system). */
async function ciExport() {
    try {
        var answer = await Ytan.get('/admin/campsite-import/export');
        var blob = new Blob([JSON.stringify(answer.data)], { type: 'application/json' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'campsite-import-' + new Date().toISOString().slice(0, 10) + '.json';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
        showAdminToast(t('admin.shelter_import.export_done', { count: answer.data.entries.length }), 'success');
    } catch (err) {
        showAdminToast(t('admin.shelter_import.export_failed', { error: err.message }), 'error');
    }
}

/** Reads a transfer file chosen by the admin and adds/refreshes its entries in the staging table. */
async function ciLoadFile(file) {
    var data;
    try {
        data = JSON.parse(await file.text());
    } catch (err) {
        showAdminToast(t('admin.shelter_import.file_invalid'), 'error');
        return;
    }
    var count = Array.isArray(data.entries) ? data.entries.length : 0;
    if (!(await adminConfirm({
        title: t('admin.lighthouse_import.confirm_load_file_title'),
        message: t('admin.shelter_import.confirm_load_file', { count: count, name: file.name }),
        confirmLabel: t('admin.lighthouse_import.confirm_load_file_button'),
    }))) {
        return;
    }

    try {
        var answer = await Ytan.post('/admin/campsite-import/file', data);
        showAdminToast(t('admin.shelter_import.file_loaded', { inserted: answer.data.inserted, updated: answer.data.updated }), 'success');
        ciReload(true);
    } catch (err) {
        showAdminToast(t('admin.shelter_import.file_failed', { error: translateApiError(err.data) || err.message }), 'error');
    }
}

function ciInit() {
    adminRememberTab('ciTabs', 'ytan_campsite_import_tab');
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
