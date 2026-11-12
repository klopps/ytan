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
};

function logsEscape(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

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
    return '<span class="text-nowrap">' + logsEscape(date.toLocaleDateString(locale, { year: 'numeric', month: '2-digit', day: '2-digit' })) + '</span> ' +
        '<span class="text-nowrap">' + logsEscape(date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', second: '2-digit' })) + '</span>';
}

function logsSourceLabel(source) {
    if (source === 'php') {
        return t('admin.logs.source_php');
    }
    if (source === 'extra') {
        return t('admin.logs.source_extra');
    }
    if (source === 'worker') {
        return t('admin.logs.source_worker');
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
        document.getElementById('logsList').innerHTML = '<p class="p-3 mb-0 text-danger">' + logsEscape(t('admin.logs.load_failed', { error: err.message })) + '</p>';
    }
}

function logsRenderList() {
    var container = document.getElementById('logsList');
    if (logsState.files.length === 0) {
        container.innerHTML = '<p class="p-3 mb-0 text-secondary">' + logsEscape(t('admin.logs.none')) + '</p>';
        logsUpdateSelectionButton();
        return;
    }
    var allSelected = logsState.files.every(function (file) { return logsState.selected[file.id]; });
    var html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr>' +
        '<th style="width:2.5rem;"><input type="checkbox" class="form-check-input" aria-label="' + logsEscape(t('admin.logs.select_all')) + '"' +
            (allSelected ? ' checked' : '') + ' data-on-change="logsToggleAll(this.checked);"></th>' +
        '<th>' + logsEscape(t('admin.logs.col_name')) + '</th>' +
        '<th class="text-end">' + logsEscape(t('admin.logs.col_size')) + '</th>' +
        '<th>' + logsEscape(t('admin.logs.col_modified')) + '</th>' +
        '<th class="d-none d-md-table-cell">' + logsEscape(t('admin.logs.col_source')) + '</th>' +
        '</tr></thead><tbody>';
    logsState.files.forEach(function (file, index) {
        var active = logsState.current === file.id;
        html += '<tr' + (active ? ' class="table-active"' : '') + '>' +
            '<td><input type="checkbox" class="form-check-input" aria-label="' + logsEscape(file.name) + '"' +
                (logsState.selected[file.id] ? ' checked' : '') + ' data-on-change="logsToggle(' + index + ', this.checked);"></td>' +
            '<td><a href="#" title="' + logsEscape(file.path) + '" data-on-click="logsOpen(' + index + '); return false;">' + logsEscape(file.name) + '</a></td>' +
            '<td class="text-end text-nowrap">' + logsEscape(logsFormatBytes(file.size)) + '</td>' +
            '<td>' + logsDateHtml(file.modified) + '</td>' +
            '<td class="d-none d-md-table-cell text-secondary small">' + logsEscape(logsSourceLabel(file.source)) + '</td>' +
            '</tr>';
    });
    container.innerHTML = html + '</tbody></table></div>';
    logsUpdateSelectionButton();
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
    document.getElementById('logsViewerInfo').textContent = t('admin.logs.viewer_info', {
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
    logsLoadList();
    setInterval(function () {
        if (!document.hidden) {
            logsLoadList();
        }
    }, LOGS_LIST_REFRESH_MS);
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: logsStart });
