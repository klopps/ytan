/**
 * Logic for the /admin/shelter-import tool (templates/admin-shelter-import.php):
 * lists the staged shelters (server-side filtered and paginated) with their
 * possible duplicates, keeps a selection across pages and filter changes,
 * loads OpenStreetMap (one country) or an official list (the server computes
 * the distances to water while loading), imports the selection as POIs and
 * moves the staged list
 * between systems as a file. Login-gate handled by admin-auth.js.
 */

var siState = {
    page: 1,
    pageSize: 50,
    total: 0,
    open: 0, // matches that are not imported yet
    rows: [],
    selected: new Set(),
    filterTimer: null,
};

function siEscape(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    // Quotes too: the result also goes into attributes, and the data comes from outside.
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function siFilters() {
    var filters = {};
    document.querySelectorAll('#siFilterForm [data-filter]').forEach(function (input) {
        var value = input.value.trim();
        if (value !== '') {
            filters[input.dataset.filter] = value;
        }
    });
    return filters;
}

function siQuery(filters, extra) {
    var text = new URLSearchParams(Object.assign({}, filters, extra || {})).toString();
    return text === '' ? '' : '?' + text;
}

/** A link only when it is an http(s) URL (the data comes from outside). */
function siLink(url) {
    if (!/^https?:\/\//i.test(url || '')) {
        return '';
    }
    return '<a href="' + siEscape(url) + '" target="_blank" rel="noopener noreferrer">' +
        siEscape(url.replace(/^https?:\/\/(www\.)?/i, '').replace(/\/$/, '').slice(0, 40)) + '</a>';
}

function siMapLink(row) {
    var url = 'https://www.google.com/maps/search/?api=1&query=' + Number(row.latitude) + ',' + Number(row.longitude);
    return '<a href="' + siEscape(url) + '" target="_blank" rel="noopener noreferrer" class="small" title="' +
        siEscape(t('admin.shelter_import.show_on_map')) + '"><i class="bi bi-geo-alt"></i></a>';
}

/** "Meer 120 m · Fluss 3400 m · See –" (– = farther than 100 km), or "not computed". */
function siWaterText(row) {
    if (!row.water_checked_at) {
        return '<span class="text-secondary">' + siEscape(t('admin.shelter_import.water_unchecked')) + '</span>';
    }
    return ['sea', 'river', 'lake'].map(function (kind) {
        var meters = row[kind + '_m'];
        return '<span class="text-nowrap">' + siEscape(t('admin.shelter_import.water_' + kind)) + ' ' +
            siEscape(meters === null ? '–' : t('admin.shelter_import.water_distance_value', { meters: meters })) + '</span>';
    }).join(' · ');
}

/**
 * The neighbours within ~33 m: a shelter POI there is a possible duplicate
 * (badge); other sources' entries are only a hint ("also in ...").
 */
function siNeighboursText(neighbours) {
    if (!neighbours || neighbours.length === 0) {
        return '';
    }
    var pois = neighbours.filter(function (n) { return n.kind === 'poi'; });
    var staged = neighbours.filter(function (n) { return n.kind !== 'poi'; });
    var parts = [];
    if (pois.length > 0) {
        parts.push('<span class="badge text-bg-warning">' + siEscape(t('admin.shelter_import.duplicate_badge')) + '</span> ' +
            pois.map(function (n) {
                return '<span class="small">' + siEscape(t('admin.shelter_import.neighbour_poi', { id: n.id }) + (n.name ? ': ' + n.name : '')) + '</span>';
            }).join('<br>'));
    }
    if (staged.length > 0) {
        parts.push('<span class="small text-secondary">' + siEscape(t('admin.shelter_import.also_in')) + ' ' +
            staged.map(function (n) {
                return siEscape(t('admin.shelter_import.source_short_' + n.source) + (n.name ? ': ' + n.name : '') +
                    (n.imported ? ' (' + t('admin.lighthouse_import.status_imported') + ')' : ''));
            }).join(', ') + '</span>');
    }
    return parts.join('<br>');
}

function siKindName(kind) {
    return t('admin.shelter_import.kind_' + (kind === 'hut' ? 'hut' : 'shelter'));
}

async function siLoadEntries() {
    try {
        var answer = await Ytan.get('/admin/shelter-import' + siQuery(siFilters(), {
            limit: siState.pageSize,
            offset: (siState.page - 1) * siState.pageSize,
        }));
        siState.rows = answer.data;
        siState.total = answer.meta.total;
        siState.open = answer.meta.open;
        siFillCountries(answer.meta.countries || []);
        siUpdateWaterInfo(answer.meta.water_index, answer.meta.unchecked);
        siRender();
    } catch (err) {
        document.getElementById('siRows').innerHTML =
            '<tr><td colspan="9" class="text-danger">' + siEscape(t('admin.shelter_import.load_failed', { error: err.message })) + '</td></tr>';
    }
}

/** Which water index the server uses, and how many entries have no distances (loaded without an index). */
function siUpdateWaterInfo(version, unchecked) {
    var info = document.getElementById('siWaterInfo');
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
function siFillCountries(countries) {
    var select = document.getElementById('siFilterCountry');
    if (select.options.length - 1 === countries.length) {
        return;
    }
    var current = select.value;
    select.innerHTML = '<option value="">' + siEscape(t('admin.shelter_import.filter_all')) + '</option>' +
        countries.map(function (code) { return '<option value="' + siEscape(code) + '">' + siEscape(code) + '</option>'; }).join('');
    select.value = current;
}

function siRender() {
    var body = document.getElementById('siRows');
    if (siState.rows.length === 0) {
        body.innerHTML = '<tr><td colspan="9" class="text-secondary">' + siEscape(t('admin.shelter_import.no_results')) + '</td></tr>';
    } else {
        body.innerHTML = siState.rows.map(function (row) {
            var imported = row.poi_id !== null;
            var selectable = !imported;
            return '<tr>' +
                '<td><input type="checkbox" class="form-check-input siRowCheckbox" data-id="' + row.id + '"' +
                    (selectable ? '' : ' disabled') + (siState.selected.has(Number(row.id)) ? ' checked' : '') + '></td>' +
                '<td>' + siEscape(row.display_name || t('admin.shelter_import.no_name')) + ' ' + siMapLink(row) + '</td>' +
                '<td class="small text-nowrap">' + siEscape(siKindName(row.kind)) + '</td>' +
                '<td class="small">' + siEscape(row.country) + '</td>' +
                '<td class="small">' + siWaterText(row) + '</td>' +
                '<td class="small text-nowrap">' + siEscape(t('admin.shelter_import.source_short_' + row.source)) + '</td>' +
                '<td class="small">' + siLink(row.website) + '</td>' +
                '<td>' + siNeighboursText(row.neighbours) + '</td>' +
                '<td>' + (imported
                    ? '<span class="badge text-bg-success">' + siEscape(t('admin.lighthouse_import.status_imported')) + '</span>'
                    : '<span class="badge text-bg-secondary">' + siEscape(t('admin.lighthouse_import.status_open')) + '</span>') + '</td>' +
            '</tr>';
        }).join('');
    }

    var totalPages = Math.max(1, Math.ceil(siState.total / siState.pageSize));
    document.getElementById('siPagination').innerHTML =
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="siPrev"' + (siState.page <= 1 ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>' +
        '<span class="small text-secondary">' + siEscape(siState.total === 0 ? t('admin.shelter_import.zero_results') : t('common.page_of', { page: siState.page, total: totalPages }) + ' (' + siState.total + ')') + '</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="siNext"' + (siState.page >= totalPages ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button>';

    siUpdateSelectionUi();
}

function siUpdateSelectionUi() {
    document.getElementById('siSelectAllMatches').textContent = document.getElementById('siFilterDuplicate').value === ''
        ? t('admin.shelter_import.select_all_matches')
        : t('admin.shelter_import.select_all_matches_filtered');
    document.getElementById('siSelectedCount').textContent = t('admin.shelter_import.selected_of', { count: siState.selected.size, total: siState.total });
    document.getElementById('siImportButton').disabled = siState.selected.size === 0;

    var boxes = Array.prototype.slice.call(document.querySelectorAll('.siRowCheckbox:not(:disabled)'));
    document.getElementById('siTogglePage').checked = boxes.length > 0 && boxes.every(function (box) { return box.checked; });
}

function siReload(resetPage) {
    if (resetPage) {
        siState.page = 1;
    }
    siLoadEntries();
}

/**
 * Selects every open match of the current filters (e.g. "sea at most 300 m").
 * Without a choice in the duplicate filter only entries without a shelter POI
 * nearby (a possible duplicate - already imported or drawn by hand); with a
 * choice exactly the filter's matches.
 */
async function siSelectAllMatches() {
    try {
        var filters = Object.assign({ duplicate: 'no' }, siFilters(), { status: 'open' });
        var answer = await Ytan.get('/admin/shelter-import/ids' + siQuery(filters));
        answer.data.forEach(function (id) { siState.selected.add(Number(id)); });
        showAdminToast(t('admin.shelter_import.selected_all_result', { count: answer.data.length }), 'success');
        siRender();
    } catch (err) {
        showAdminToast(t('admin.shelter_import.load_failed', { error: err.message }), 'error');
    }
}

async function siImport() {
    var ids = Array.from(siState.selected);
    if (ids.length === 0) {
        return;
    }
    if (!(await adminConfirm({
        title: t('admin.lighthouse_import.confirm_import_title'),
        message: t('admin.shelter_import.confirm_import', { count: ids.length }),
        confirmLabel: t('admin.lighthouse_import.confirm_import_button'),
    }))) {
        return;
    }

    var button = document.getElementById('siImportButton');
    button.disabled = true;
    try {
        var answer = await Ytan.post('/admin/shelter-import', { ids: ids, public: document.getElementById('siPublic').checked });
        siState.selected.clear();
        showAdminToast(t('admin.shelter_import.import_result', { imported: answer.data.imported, skipped: answer.data.skipped }), 'success');
        siLoadEntries();
    } catch (err) {
        showAdminToast(t('admin.shelter_import.import_failed', { error: translateApiError(err.data) || err.message }), 'error');
        button.disabled = false;
    }
}

function siLoadResultToast(data) {
    showAdminToast(t('admin.shelter_import.load_result', {
        fetched: data.fetched,
        inserted: data.inserted,
        updated: data.updated,
    }), 'success');
}

/** Loads all shelters of the chosen country from OpenStreetMap (can take minutes for big countries). */
async function siLoadOsm() {
    var button = document.getElementById('siOsmLoad');
    button.disabled = true;
    button.textContent = t('admin.shelter_import.loading');
    try {
        var answer = await Ytan.post('/admin/shelter-import/osm', { country: document.getElementById('siOsmCountry').value });
        siLoadResultToast(answer.data);
        siReload(true);
    } catch (err) {
        showAdminToast(t('admin.shelter_import.load_source_failed', { error: translateApiError(err.data) || err.message }), 'error');
    } finally {
        button.disabled = false;
        button.innerHTML = '<i class="bi bi-cloud-download"></i> ' + siEscape(t('admin.shelter_import.osm_load'));
    }
}

/** Loads one official list completely. */
async function siLoadSource(button) {
    var label = button.innerHTML;
    button.disabled = true;
    button.textContent = t('admin.shelter_import.loading');
    try {
        var answer = await Ytan.post('/admin/shelter-import/source', { source: button.dataset.source });
        siLoadResultToast(answer.data);
        siReload(true);
    } catch (err) {
        showAdminToast(t('admin.shelter_import.load_source_failed', { error: translateApiError(err.data) || err.message }), 'error');
    } finally {
        button.disabled = false;
        button.innerHTML = label;
    }
}

/** Downloads every staged entry as a transfer file (read by siLoadFile() on another system). */
async function siExport() {
    try {
        var answer = await Ytan.get('/admin/shelter-import/export');
        var blob = new Blob([JSON.stringify(answer.data)], { type: 'application/json' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'shelter-import-' + new Date().toISOString().slice(0, 10) + '.json';
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
async function siLoadFile(file) {
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
        var answer = await Ytan.post('/admin/shelter-import/file', data);
        showAdminToast(t('admin.shelter_import.file_loaded', { inserted: answer.data.inserted, updated: answer.data.updated }), 'success');
        siReload(true);
    } catch (err) {
        showAdminToast(t('admin.shelter_import.file_failed', { error: translateApiError(err.data) || err.message }), 'error');
    }
}

function siInit() {
    adminRememberTab('siTabs', 'ytan_shelter_import_tab');
    document.querySelectorAll('#siFilterForm [data-filter]').forEach(function (input) {
        input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', function () {
            clearTimeout(siState.filterTimer);
            siState.filterTimer = setTimeout(function () { siReload(true); }, 300);
        });
    });
    document.getElementById('siPageSize').addEventListener('change', function (event) {
        siState.pageSize = parseInt(event.target.value, 10);
        siReload(true);
    });

    document.getElementById('siRows').addEventListener('change', function (event) {
        if (!event.target.classList.contains('siRowCheckbox')) {
            return;
        }
        var id = Number(event.target.dataset.id);
        if (event.target.checked) {
            siState.selected.add(id);
        } else {
            siState.selected.delete(id);
        }
        siUpdateSelectionUi();
    });
    document.getElementById('siTogglePage').addEventListener('change', function (event) {
        document.querySelectorAll('.siRowCheckbox:not(:disabled)').forEach(function (box) {
            box.checked = event.target.checked;
            var id = Number(box.dataset.id);
            if (event.target.checked) {
                siState.selected.add(id);
            } else {
                siState.selected.delete(id);
            }
        });
        siUpdateSelectionUi();
    });
    document.getElementById('siSelectAllMatches').addEventListener('click', siSelectAllMatches);
    document.getElementById('siSelectNone').addEventListener('click', function () {
        siState.selected.clear();
        siRender();
    });
    document.getElementById('siPagination').addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button || button.disabled) {
            return;
        }
        siState.page += button.id === 'siNext' ? 1 : -1;
        siLoadEntries();
    });

    document.getElementById('siOsmForm').addEventListener('submit', siLoadOsm);
    document.querySelectorAll('.siSourceLoad').forEach(function (button) {
        button.addEventListener('click', function () { siLoadSource(button); });
    });
    document.getElementById('siExportButton').addEventListener('click', siExport);
    document.getElementById('siFileButton').addEventListener('click', function () {
        document.getElementById('siFileInput').click();
    });
    document.getElementById('siFileInput').addEventListener('change', function (event) {
        var file = event.target.files[0];
        event.target.value = '';
        if (file) {
            siLoadFile(file);
        }
    });
    document.getElementById('siImportButton').addEventListener('click', siImport);

    siLoadEntries();
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: siInit });
