/**
 * Logic for /admin/logs (templates/admin-logs.php, LogController): lists the
 * system's log files with size and last change, shows one (its tail, then -
 * with auto-follow on - what is appended, polled every LOGS_FOLLOW_MS and
 * scrolled to the end) and downloads one file or several as a ZIP. Files
 * are addressed by the ids the server hands out, never by path.
 */

var LOGS_FOLLOW_MS = 2000;
var LOGS_LIST_REFRESH_MS = 15000;
/** The viewer keeps at most this many characters; older text is cut at a line start. */
var LOGS_MAX_CHARS = 2000000;

var logsState = {
    files: [],
    selected: {},
    current: null,
    offset: 0,
    follow: false,
    followTimer: null,
    polling: false,
    // list view: sort column (null = the order the server sends), direction, grouping by origin
    sortKey: null,
    sortDir: 'asc',
    group: false,
};

/** The sort/group choice is a per-viewer convenience: kept in localStorage, the page works without it. */
function logsLoadViewPrefs() {
    try {
        var saved = JSON.parse(localStorage.getItem('ytan_admin_logs_view') || 'null');
        if (saved && ['name', 'size', 'modified', 'source', null].indexOf(saved.sortKey) !== -1) {
            logsState.sortKey = saved.sortKey;
            logsState.sortDir = saved.sortDir === 'desc' ? 'desc' : 'asc';
            logsState.group = saved.group === true;
        }
    } catch (e) {
        // no storage: keep the defaults
    }
}

function logsSaveViewPrefs() {
    try {
        localStorage.setItem('ytan_admin_logs_view', JSON.stringify({ sortKey: logsState.sortKey, sortDir: logsState.sortDir, group: logsState.group }));
    } catch (e) {
        // no storage: the choice just doesn't survive a reload
    }
}

// Escaping: helper.js's escapeHTML().

function logsFormatBytes(bytes) {
    if (bytes < 1024) {
        return bytes + ' B';
    }
    if (bytes < 1024 * 1024) {
        return (bytes / 1024).toFixed(1) + ' KB';
    }
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

function logsFormatDate(iso) {
    if (!iso) {
        return '–';
    }
    return new Date(iso).toLocaleString(document.documentElement.lang || undefined, {
        year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
}

/** Date and time as two unbreakable parts, so a phone wraps between them instead of scrolling sideways. */
function logsDateHtml(iso) {
    if (!iso) {
        return '–';
    }
    var date = new Date(iso);
    var locale = document.documentElement.lang || undefined;
    return '<span class="text-nowrap">' + escapeHTML(date.toLocaleDateString(locale, { year: 'numeric', month: '2-digit', day: '2-digit' })) + '</span> ' +
        '<span class="text-nowrap">' + escapeHTML(date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', second: '2-digit' })) + '</span>';
}

function logsSourceLabel(source) {
    if (source === 'php') {
        return t('admin.logs.source_php');
    }
    if (source === 'extra') {
        return t('admin.logs.source_extra');
    }
    if (source.indexOf('worker:') === 0) {
        // "worker:{id}" = a log of that registered worker (storage/logs/worker/{id}/)
        return t('admin.logs.source_worker') + ' ' + source.slice(7);
    }
    if (source === 'worker') {
        // loose in storage/logs/worker/: uploaded by a worker from before the registry - it has no name
        return t('admin.logs.source_worker_unnamed');
    }
    return t('admin.logs.source_storage');
}

async function logsLoadList() {
    try {
        var answer = await Ytan.get('/admin/logs');
        logsState.files = answer.data;
        var known = {};
        logsState.files.forEach(function (file) { known[file.id] = true; });
        Object.keys(logsState.selected).forEach(function (id) {
            if (!known[id]) {
                delete logsState.selected[id];
            }
        });
        logsRenderList();
        logsUpdateViewerInfo();
    } catch (err) {
        document.getElementById('logsList').innerHTML = '<p class="p-3 mb-0 text-danger">' + escapeHTML(t('admin.logs.load_failed', { error: err.message })) + '</p>';
    }
}

function logsRenderList() {
    var container = document.getElementById('logsList');
    if (logsState.files.length === 0) {
        container.innerHTML = '<p class="p-3 mb-0 text-secondary">' + escapeHTML(t('admin.logs.none')) + '</p>';
        logsUpdateSelectionButton();
        return;
    }
    var allSelected = logsState.files.every(function (file) { return logsState.selected[file.id]; });
    var html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr>' +
        '<th style="width:2.5rem;"><input type="checkbox" class="form-check-input" aria-label="' + escapeHTML(t('admin.logs.select_all')) + '"' +
            (allSelected ? ' checked' : '') + ' data-on-change="logsToggleAll(this.checked);"></th>' +
        logsSortHeader('name', t('admin.logs.col_name'), '') +
        logsSortHeader('size', t('admin.logs.col_size'), 'text-end') +
        logsSortHeader('modified', t('admin.logs.col_modified'), '') +
        logsSortHeader('source', t('admin.logs.col_source'), '') +
        '</tr></thead><tbody>';
    var lastGroup = null;
    logsViewOrder().forEach(function (index) {
        var file = logsState.files[index];
        if (logsState.group && logsSourceLabel(file.source) !== lastGroup) {
            lastGroup = logsSourceLabel(file.source);
            html += '<tr><th colspan="5" class="small fw-semibold bg-body-secondary">' + escapeHTML(lastGroup) + '</th></tr>';
        }
        var active = logsState.current === file.id;
        html += '<tr' + (active ? ' class="table-active"' : '') + '>' +
            '<td><input type="checkbox" class="form-check-input" aria-label="' + escapeHTML(file.name) + '"' +
                (logsState.selected[file.id] ? ' checked' : '') + ' data-on-change="logsToggle(' + index + ', this.checked);"></td>' +
            '<td><a href="#" title="' + escapeHTML(file.path) + '" data-on-click="logsOpen(' + index + '); return false;">' + escapeHTML(file.name) + '</a></td>' +
            '<td class="text-end text-nowrap">' + escapeHTML(logsFormatBytes(file.size)) + '</td>' +
            '<td>' + logsDateHtml(file.modified) + '</td>' +
            '<td class="text-secondary small">' + escapeHTML(logsSourceLabel(file.source)) + '</td>' +
            '</tr>';
    });
    container.innerHTML = html + '</tbody></table></div>';
    logsUpdateSelectionButton();
}

/** A column header that sorts the list: a button with the direction arrow on the sorted column. */
function logsSortHeader(key, label, cssClass) {
    var active = logsState.sortKey === key;
    var arrow = active ? (logsState.sortDir === 'asc' ? ' <i class="bi bi-caret-up-fill small"></i>' : ' <i class="bi bi-caret-down-fill small"></i>') : '';
    return '<th class="' + cssClass + '"' + (active ? ' aria-sort="' + (logsState.sortDir === 'asc' ? 'ascending' : 'descending') + '"' : '') + '>' +
        '<button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-bold text-reset" title="' + escapeHTML(t('admin.logs.sort_by', { column: label })) + '"' +
        ' data-on-click="logsSort(\'' + key + '\');">' + escapeHTML(label) + arrow + '</button></th>';
}

/**
 * The indexes into logsState.files in the order they are shown: sorted by the chosen column (ties keep the
 * server's order), and with grouping on first by origin. The rows keep addressing files by their index in
 * logsState.files, so sorting never changes which file a click opens.
 */
function logsViewOrder() {
    var order = logsState.files.map(function (file, index) { return index; });
    var label = function (index) { return logsSourceLabel(logsState.files[index].source); };
    var value = function (index) {
        var file = logsState.files[index];
        switch (logsState.sortKey) {
            case 'size':
                return Number(file.size) || 0;
            case 'modified':
                return file.modified ? new Date(file.modified).getTime() : 0;
            case 'source':
                return label(index);
            default:
                return String(file.name);
        }
    };
    var compare = function (a, b) {
        var x = value(a);
        var y = value(b);
        var result = typeof x === 'number' ? x - y : x.localeCompare(y, undefined, { numeric: true, sensitivity: 'base' });
        return logsState.sortDir === 'desc' ? -result : result;
    };
    order.sort(function (a, b) {
        if (logsState.group) {
            var byGroup = label(a).localeCompare(label(b), undefined, { numeric: true, sensitivity: 'base' });
            if (byGroup !== 0) {
                return byGroup;
            }
        }
        var result = logsState.sortKey === null ? 0 : compare(a, b);
        return result !== 0 ? result : a - b;
    });

    return order;
}

/** Click on a column header: sort by it, or reverse the direction when it is the sorted one already. */
function logsSort(key) {
    if (logsState.sortKey === key) {
        logsState.sortDir = logsState.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        logsState.sortKey = key;
        // size and time: biggest / newest first is what one wants first
        logsState.sortDir = key === 'size' || key === 'modified' ? 'desc' : 'asc';
    }
    logsSaveViewPrefs();
    logsRenderList();
}

function logsSetGroup(on) {
    logsState.group = on === true;
    logsSaveViewPrefs();
    logsRenderList();
}

function logsToggle(index, checked) {
    var file = logsState.files[index];
    if (!file) {
        return;
    }
    if (checked) {
        logsState.selected[file.id] = true;
    } else {
        delete logsState.selected[file.id];
    }
    logsRenderList();
}

function logsToggleAll(checked) {
    logsState.selected = {};
    if (checked) {
        logsState.files.forEach(function (file) { logsState.selected[file.id] = true; });
    }
    logsRenderList();
}

function logsUpdateSelectionButton() {
    document.getElementById('logsDownloadSelected').disabled = Object.keys(logsState.selected).length === 0;
}

function logsCurrentFile() {
    for (var i = 0; i < logsState.files.length; i++) {
        if (logsState.files[i].id === logsState.current) {
            return logsState.files[i];
        }
    }
    return null;
}

function logsUpdateViewerInfo() {
    var file = logsCurrentFile();
    if (!file) {
        return;
    }
    document.getElementById('logsViewerName').textContent = file.name;
    document.getElementById('logsViewerInfo').textContent = t('admin.logs.col_source') + ': ' + logsSourceLabel(file.source) + ' · ' +
        t('admin.logs.viewer_info', {
            size: logsFormatBytes(file.size),
            modified: logsFormatDate(file.modified),
        });
}

async function logsOpen(index) {
    var file = logsState.files[index];
    if (!file) {
        return;
    }
    // Packed old archives (NAME.A-B.zip, LogRotation::compact()) aren't text - download them.
    if (/\.zip$/i.test(file.name)) {
        logsDownload([file.id], file.name);
        return;
    }
    logsState.current = file.id;
    logsState.offset = 0;
    var pre = document.getElementById('logsContent');
    pre.textContent = t('admin.logs.loading');
    document.getElementById('logsViewer').hidden = false;
    logsRenderList();
    logsUpdateViewerInfo();
    try {
        var answer = await Ytan.get('/admin/logs/read?id=' + encodeURIComponent(file.id));
        if (logsState.current !== file.id) {
            return;
        }
        pre.textContent = (answer.data.truncated ? t('admin.logs.truncated') + '\n' : '') + answer.data.content;
        logsState.offset = answer.data.to;
        logsApplyReadInfo(answer.data);
        pre.scrollTop = pre.scrollHeight;
        document.getElementById('logsViewer').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (err) {
        pre.textContent = t('admin.logs.load_failed', { error: err.message });
    }
}

/** Takes size/last change from a read answer into the list entry (no extra list request). */
function logsApplyReadInfo(data) {
    var file = logsCurrentFile();
    if (file && (file.size !== data.size || file.modified !== data.modified)) {
        file.size = data.size;
        file.modified = data.modified;
        logsRenderList();
        logsUpdateViewerInfo();
    }
}

function logsSetFollow(on) {
    logsState.follow = on;
    clearInterval(logsState.followTimer);
    logsState.followTimer = null;
    if (on) {
        logsState.followTimer = setInterval(logsPoll, LOGS_FOLLOW_MS);
        var pre = document.getElementById('logsContent');
        pre.scrollTop = pre.scrollHeight;
        logsPoll();
    }
}

async function logsPoll() {
    if (!logsState.current || logsState.polling || document.hidden) {
        return;
    }
    logsState.polling = true;
    var id = logsState.current;
    try {
        var more = true;
        while (more && logsState.current === id) {
            var answer = await Ytan.get('/admin/logs/read?id=' + encodeURIComponent(id) + '&from=' + logsState.offset);
            if (logsState.current !== id) {
                break;
            }
            var data = answer.data;
            var pre = document.getElementById('logsContent');
            if (data.reset) {
                pre.textContent = t('admin.logs.rotated') + '\n' + data.content;
            } else if (data.content !== '') {
                logsAppend(pre, data.content);
            }
            logsState.offset = data.to;
            logsApplyReadInfo(data);
            if (logsState.follow && (data.reset || data.content !== '')) {
                pre.scrollTop = pre.scrollHeight;
            }
            more = data.more;
        }
    } catch (err) {
        log('admin-logs: poll failed', LOG_WARN, err);
    } finally {
        logsState.polling = false;
    }
}

function logsAppend(pre, text) {
    var content = pre.textContent + text;
    if (content.length > LOGS_MAX_CHARS) {
        content = content.slice(content.length - LOGS_MAX_CHARS);
        var newline = content.indexOf('\n');
        if (newline >= 0) {
            content = content.slice(newline + 1);
        }
    }
    pre.textContent = content;
}

function logsCloseViewer() {
    document.getElementById('logsFollow').checked = false;
    logsSetFollow(false);
    logsState.current = null;
    document.getElementById('logsViewer').hidden = true;
    document.getElementById('logsContent').textContent = '';
    logsRenderList();
}

async function logsDownload(ids, filename) {
    try {
        var blob = await Ytan.fetchBlob('/admin/logs/download?ids=' + encodeURIComponent(ids.join(',')));
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
    } catch (err) {
        showAdminToast(t('admin.logs.download_failed', { error: err.message }), 'error');
    }
}

function logsDownloadSelected() {
    var ids = Object.keys(logsState.selected);
    if (ids.length === 0) {
        return;
    }
    if (ids.length === 1) {
        var file = logsState.files.find(function (f) { return f.id === ids[0]; });
        logsDownload(ids, file ? file.name : 'log.txt');
        return;
    }
    var stamp = new Date().toISOString().slice(0, 19).replace(/[-:]/g, '').replace('T', '-');
    logsDownload(ids, 'ytan-logs-' + stamp + '.zip');
}

function logsDownloadCurrent() {
    var file = logsCurrentFile();
    if (file) {
        logsDownload([file.id], file.name);
    }
}

function logsStart() {
    logsLoadViewPrefs();
    document.getElementById('logsGroup').checked = logsState.group;
    logsLoadList();
    setInterval(function () {
        if (!document.hidden) {
            logsLoadList();
        }
    }, LOGS_LIST_REFRESH_MS);
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: logsStart });
