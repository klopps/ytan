/**
 * Logic for the /admin/lighthouse-duplicates tool (templates/admin-lighthouse-duplicates.php):
 * shows groups of lighthouses that lie close together side by side (one
 * column per lighthouse), lets the admin pick which ones of a group are the
 * same lighthouse, which record stays and which record each field's value is
 * taken from, and merges the picked groups ("Bereinigen").
 * Login-gate handled by admin-auth.js; ldInit() runs as its onReady callback.
 */

// What a merge can take from which record: [field key, label key]. "position"
// stands for latitude+longitude together (the server knows the same names).
var LD_FIELDS = [
    ['name', 'admin.lighthouse_duplicates.field_name'],
    ['description', 'admin.lighthouse_duplicates.field_description'],
    ['position', 'admin.lighthouse_duplicates.field_position'],
    ['characteristic', 'admin.lighthouse_duplicates.field_characteristic'],
    ['sector_characteristic', 'admin.lighthouse_duplicates.field_sector'],
    ['international_id', 'admin.lighthouse_duplicates.field_international_id'],
    ['public', 'admin.lighthouse_duplicates.field_public'],
];

// Shown for each record, never merged (they describe the record itself): [poi key, label key].
var LD_TIMES = [
    ['imported_at', 'admin.lighthouse_duplicates.field_imported_at'],
    ['updated_at', 'admin.lighthouse_duplicates.field_updated_at'],
];

var ldState = {
    page: 1,
    pageSize: 25,
    total: 0,
    distance: 50,
    groups: [],
    // key of a group => {ids: Set of the records that are the same lighthouse, keep: id, fields: {field: id}}.
    // Kept across pages; a group whose members change gets a fresh entry.
    picks: {},
};

function ldEscape(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    // innerHTML escapes only &, < and > - quotes too, for attribute values.
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function ldValue(poi, field) {
    switch (field) {
        case 'position':
            return Number(poi.latitude).toFixed(5) + ', ' + Number(poi.longitude).toFixed(5);
        case 'public':
            return Number(poi.public) === 1 ? t('admin.lighthouse_duplicates.yes') : t('admin.lighthouse_duplicates.no');
        default:
            return poi[field] == null ? '' : String(poi[field]);
    }
}

function ldIsEmpty(poi, field) {
    return field === 'position' ? false : ldValue(poi, field).trim() === '';
}

/** The picks of a group, created on first use. */
function ldPick(group) {
    var pick = ldState.picks[group.key];
    if (!pick) {
        pick = { ids: new Set(), keep: null, fields: {} };
        ldState.picks[group.key] = pick;
    }
    return pick;
}

/** Hand-drawn records come first in a group, so defaults prefer them (their positions are usually the better ones). */
function ldSelectedPois(group, pick) {
    return group.pois.filter(function (poi) { return pick.ids.has(Number(poi.id)); });
}

/** Fills in "keep" and the field sources for the chosen records wherever they aren't valid any more. */
function ldApplyDefaults(group) {
    var pick = ldPick(group);
    var selected = ldSelectedPois(group, pick);
    if (selected.length === 0) {
        return;
    }
    if (pick.keep === null || !pick.ids.has(pick.keep)) {
        pick.keep = Number(selected[0].id);
    }
    LD_FIELDS.forEach(function (entry) {
        var field = entry[0];
        var current = pick.fields[field];
        if (current !== undefined && pick.ids.has(current)) {
            return;
        }
        // The first chosen record that has a value for the field, else the kept one.
        var source = selected.filter(function (poi) { return !ldIsEmpty(poi, field); })[0] || selected[0];
        pick.fields[field] = Number(source.id);
    });
}

function ldPoiHeader(group, poi, pick) {
    var id = Number(poi.id);
    var checked = pick.ids.has(id) ? ' checked' : '';
    var origin = poi.manual
        ? '<span class="badge text-bg-primary">' + ldEscape(t('admin.lighthouse_duplicates.origin_manual')) + '</span>'
        : '<span class="badge text-bg-secondary">' + ldEscape(t('admin.lighthouse_duplicates.origin_imported')) + '</span>';
    var keep = pick.ids.has(id)
        ? '<div class="form-check mt-1"><input class="form-check-input ldKeep" type="radio" name="keep-' + group.key + '" id="keep-' + group.key + '-' + id + '" data-group="' + group.key + '" data-id="' + id + '"' + (pick.keep === id ? ' checked' : '') + '>' +
            '<label class="form-check-label small" for="keep-' + group.key + '-' + id + '">' + ldEscape(t('admin.lighthouse_duplicates.keep_label')) + '</label></div>'
        : '';
    return '<th class="align-top" style="min-width:13rem;">' +
        '<div class="form-check"><input class="form-check-input ldMember" type="checkbox" id="member-' + group.key + '-' + id + '" data-group="' + group.key + '" data-id="' + id + '"' + checked + '>' +
            '<label class="form-check-label" for="member-' + group.key + '-' + id + '">' + ldEscape(t('admin.lighthouse_duplicates.merge_check')) + '</label></div>' +
        '<div class="small fw-normal mt-1">#' + id + ' ' + origin + '</div>' +
        '<div class="small fw-normal text-secondary">' + ldEscape(poi.username || '') + '</div>' +
        '<div class="small fw-normal text-secondary">' + ldEscape(t('admin.lighthouse_duplicates.distance_m', { distance: poi.distance_m })) + '</div>' +
        keep +
    '</th>';
}

function ldFieldCell(group, poi, field, pick) {
    var id = Number(poi.id);
    var value = ldValue(poi, field);
    var shown = field === 'sector_characteristic' || field === 'description'
        ? '<div class="small" style="white-space:pre-line; max-height:7rem; overflow:auto;' + (field === 'sector_characteristic' ? ' font-family:monospace;' : '') + '">' + ldEscape(value) + '</div>'
        : '<div class="small">' + ldEscape(value) + '</div>';
    var radio = pick.ids.has(id)
        ? '<input class="form-check-input ldField me-1 float-start" type="radio" name="field-' + group.key + '-' + field + '" data-group="' + group.key + '" data-field="' + field + '" data-id="' + id + '"' + (pick.fields[field] === id ? ' checked' : '') + ' title="' + ldEscape(t('admin.lighthouse_duplicates.take_from_this')) + '">'
        : '';
    return '<td class="' + (pick.ids.has(id) && pick.fields[field] === id ? 'table-success' : '') + '">' + radio + shown + '</td>';
}

function ldRenderGroup(group, index) {
    var pick = ldPick(group);
    ldApplyDefaults(group);

    var header = group.pois.map(function (poi) { return ldPoiHeader(group, poi, pick); }).join('');
    var rows = LD_FIELDS.map(function (entry) {
        return '<tr><th scope="row" class="small text-nowrap">' + ldEscape(t(entry[1])) + '</th>' +
            group.pois.map(function (poi) { return ldFieldCell(group, poi, entry[0], pick); }).join('') + '</tr>';
    }).join('');
    rows += '<tr><th scope="row" class="small text-nowrap">' + ldEscape(t('admin.lighthouse_duplicates.field_photos')) + '</th>' +
        group.pois.map(function (poi) { return '<td class="small">' + Number(poi.image_count) + '</td>'; }).join('') + '</tr>';
    LD_TIMES.forEach(function (entry) {
        rows += '<tr><th scope="row" class="small text-nowrap">' + ldEscape(t(entry[1])) + '</th>' +
            group.pois.map(function (poi) {
                var time = poi[entry[0]];
                return '<td class="small text-nowrap' + (time ? '' : ' text-secondary') + '">' + ldEscape(time || t('admin.lighthouse_duplicates.never')) + '</td>';
            }).join('') + '</tr>';
    });

    var ready = pick.ids.size >= 2;
    return '<div class="card mb-3' + (ready ? ' border-danger' : '') + '">' +
        '<div class="card-header d-flex flex-wrap align-items-center gap-2">' +
            '<strong>' + ldEscape(t('admin.lighthouse_duplicates.group_title', {
                number: (ldState.page - 1) * ldState.pageSize + index + 1,
                count: group.pois.length,
                distance: group.max_distance,
            })) + '</strong>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm ms-auto ldAll" data-group="' + group.key + '">' + ldEscape(t('admin.lighthouse_duplicates.select_group')) + '</button>' +
        '</div>' +
        '<div class="table-responsive"><table class="table table-sm align-middle mb-0">' +
            '<thead><tr><th></th>' + header + '</tr></thead><tbody>' + rows + '</tbody>' +
        '</table></div>' +
    '</div>';
}

function ldRender() {
    var container = document.getElementById('ldGroups');
    if (ldState.groups.length === 0) {
        container.innerHTML = '<p class="text-secondary">' + ldEscape(t('admin.lighthouse_duplicates.no_groups')) + '</p>';
    } else {
        container.innerHTML = ldState.groups.map(ldRenderGroup).join('');
    }

    var totalPages = Math.max(1, Math.ceil(ldState.total / ldState.pageSize));
    document.getElementById('ldPagination').innerHTML = ldState.total === 0 ? '' :
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="ldPrev"' + (ldState.page <= 1 ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>' +
        '<span class="small text-secondary">' + ldEscape(t('common.page_of', { page: ldState.page, total: totalPages })) + '</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" id="ldNext"' + (ldState.page >= totalPages ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button>';

    document.getElementById('ldSummary').textContent = t('admin.lighthouse_duplicates.summary', { count: ldState.total, distance: ldState.distance });
    ldUpdateSelection();
}

/** Groups on any page the admin has picked at least two records in. */
function ldReadyMerges() {
    var merges = [];
    Object.keys(ldState.picks).forEach(function (key) {
        var pick = ldState.picks[key];
        if (pick.ids.size >= 2 && pick.keep !== null) {
            merges.push({ key: key, keep_id: pick.keep, ids: Array.from(pick.ids), fields: pick.fields });
        }
    });
    return merges;
}

function ldUpdateSelection() {
    var merges = ldReadyMerges();
    document.getElementById('ldSelected').textContent = t('admin.lighthouse_duplicates.selected_count', { count: merges.length });
    document.getElementById('ldMergeButton').disabled = merges.length === 0;
}

async function ldLoad() {
    var distance = parseFloat(document.getElementById('ldDistance').value);
    if (isNaN(distance) || distance <= 0) {
        showAdminToast(t('admin.lighthouse_duplicates.distance_invalid'), 'error');
        return;
    }
    try {
        var answer = await Ytan.get('/admin/lighthouse-duplicates?distance=' + encodeURIComponent(distance) +
            '&hide_different_ids=' + (document.getElementById('ldHideDifferentIds').checked ? '1' : '0') +
            '&only_with_manual=' + (document.getElementById('ldOnlyWithManual').checked ? '1' : '0') +
            '&limit=' + ldState.pageSize + '&offset=' + ((ldState.page - 1) * ldState.pageSize));
        ldState.groups = answer.data;
        ldState.total = answer.meta.total;
        ldState.distance = answer.meta.distance;
        ldRender();
    } catch (err) {
        document.getElementById('ldGroups').innerHTML =
            '<p class="text-danger">' + ldEscape(t('admin.lighthouse_duplicates.load_failed', { error: err.message })) + '</p>';
    }
}

function ldFindGroup(key) {
    return ldState.groups.filter(function (group) { return group.key === key; })[0];
}

async function ldMerge() {
    var merges = ldReadyMerges();
    if (merges.length === 0) {
        return;
    }
    var deleted = merges.reduce(function (sum, merge) { return sum + merge.ids.length - 1; }, 0);
    if (!(await adminConfirm({
        title: t('admin.lighthouse_duplicates.confirm_title'),
        message: t('admin.lighthouse_duplicates.confirm_message', { rows: merges.length, deleted: deleted }),
        confirmLabel: t('admin.lighthouse_duplicates.confirm_button'),
        danger: true,
    }))) {
        return;
    }

    try {
        var answer = await Ytan.post('/admin/lighthouse-duplicates/merge', {
            merges: merges.map(function (merge) { return { keep_id: merge.keep_id, ids: merge.ids, fields: merge.fields }; }),
        });
        merges.forEach(function (merge) { delete ldState.picks[merge.key]; });
        showAdminToast(t('admin.lighthouse_duplicates.merged_result', {
            merged: answer.data.merged,
            deleted: answer.data.deleted,
            moved: answer.data.images_moved,
            dropped: answer.data.images_dropped,
        }), 'success');
        ldLoad();
    } catch (err) {
        showAdminToast(t('admin.lighthouse_duplicates.merge_failed', { error: err.message }), 'error');
    }
}

function ldInit() {
    document.getElementById('ldForm').addEventListener('submit', function () {
        ldState.page = 1;
        ldState.picks = {};
        ldLoad();
    });
    ['ldHideDifferentIds', 'ldOnlyWithManual'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', function () {
            ldState.page = 1;
            ldState.picks = {};
            ldLoad();
        });
    });
    document.getElementById('ldPageSize').addEventListener('change', function (event) {
        ldState.pageSize = parseInt(event.target.value, 10);
        ldState.page = 1;
        ldLoad();
    });

    var groups = document.getElementById('ldGroups');
    groups.addEventListener('change', function (event) {
        var target = event.target;
        var group = ldFindGroup(target.dataset.group);
        if (!group) {
            return;
        }
        var pick = ldPick(group);
        var id = Number(target.dataset.id);

        if (target.classList.contains('ldMember')) {
            if (target.checked) {
                pick.ids.add(id);
            } else {
                pick.ids.delete(id);
            }
        } else if (target.classList.contains('ldKeep')) {
            pick.keep = id;
        } else if (target.classList.contains('ldField')) {
            pick.fields[target.dataset.field] = id;
        } else {
            return;
        }
        ldRender();
    });
    groups.addEventListener('click', function (event) {
        var button = event.target.closest('.ldAll');
        if (!button) {
            return;
        }
        var group = ldFindGroup(button.dataset.group);
        if (group) {
            var pick = ldPick(group);
            group.pois.forEach(function (poi) { pick.ids.add(Number(poi.id)); });
            ldRender();
        }
    });

    document.getElementById('ldPagination').addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button || button.disabled) {
            return;
        }
        ldState.page += button.id === 'ldNext' ? 1 : -1;
        ldLoad();
    });
    document.getElementById('ldMergeButton').addEventListener('click', ldMerge);

    ldLoad();
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: ldInit });
