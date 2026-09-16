/**
 * Logic for the standalone /admin/image-cleanup tool (templates/admin-
 * image-cleanup.php) - scans storage/{tour,poi,route,area}-images against
 * their DB tables (AdminController/ImageReconciliationService) and lets an
 * admin delete whatever's left over on either side. Login-gate handled by
 * admin-auth.js; scan() runs once as its onReady callback.
 */

var state = {
    orphanedFiles: [],
    danglingRows: [],
};

function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

function formatBytes(bytes) {
    if (bytes < 1024) {
        return bytes + ' B';
    }
    if (bytes < 1024 * 1024) {
        return (bytes / 1024).toFixed(1) + ' KB';
    }
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

async function scan() {
    document.getElementById('cleanupContent').innerHTML = '<p>Scanning…</p>';
    try {
        var answer = await Ytan.get('/admin/image-orphans');
        state.orphanedFiles = answer.data.orphaned_files;
        state.danglingRows = answer.data.dangling_rows;
        render();
    } catch (err) {
        document.getElementById('cleanupContent').innerHTML = '<p class="errorText">Scan failed: ' + escapeHtml(err.message) + '</p>';
    }
}

function render() {
    var container = document.getElementById('cleanupContent');

    if (state.orphanedFiles.length === 0 && state.danglingRows.length === 0) {
        container.innerHTML = '<p id="emptyState">No orphaned images found.</p>';
        return;
    }

    var totalBytes = state.orphanedFiles.reduce(function (sum, f) { return sum + f.size_bytes; }, 0);
    var html = '';

    if (state.orphanedFiles.length > 0) {
        html += '<section class="cleanupSection">' +
            '<h2>Orphaned files (' + state.orphanedFiles.length + ', ' + formatBytes(totalBytes) + ')</h2>' +
            '<p class="cleanupHint">Files on disk with no matching database row - safe to delete, nothing references them.</p>' +
            '<div class="cleanupActions">' +
                '<label><input type="checkbox" onchange="toggleAll(\'file\', this.checked);"> Select all</label>' +
                '<button type="button" class="button" onclick="deleteSelected(\'file\');">Delete selected</button>' +
            '</div>' +
            '<ul class="cleanupList">';
        state.orphanedFiles.forEach(function (item, index) {
            html += '<li>' +
                '<label>' +
                    '<input type="checkbox" class="fileCheckbox" data-index="' + index + '">' +
                    '<span class="cleanupType">' + escapeHtml(item.type) + '</span>' +
                    '<span class="cleanupId">#' + item.entity_id + '</span>' +
                    '<span class="cleanupFilename">' + escapeHtml(item.filename) + '</span>' +
                    '<span class="cleanupSize">' + formatBytes(item.size_bytes) + '</span>' +
                '</label>' +
            '</li>';
        });
        html += '</ul></section>';
    }

    if (state.danglingRows.length > 0) {
        html += '<section class="cleanupSection">' +
            '<h2>Dangling DB rows (' + state.danglingRows.length + ')</h2>' +
            '<p class="cleanupHint">Database rows whose file is missing on disk - would only ever 404 if served.</p>' +
            '<div class="cleanupActions">' +
                '<label><input type="checkbox" onchange="toggleAll(\'row\', this.checked);"> Select all</label>' +
                '<button type="button" class="button" onclick="deleteSelected(\'row\');">Delete selected</button>' +
            '</div>' +
            '<ul class="cleanupList">';
        state.danglingRows.forEach(function (item, index) {
            html += '<li>' +
                '<label>' +
                    '<input type="checkbox" class="rowCheckbox" data-index="' + index + '">' +
                    '<span class="cleanupType">' + escapeHtml(item.type) + '</span>' +
                    '<span class="cleanupId">#' + item.entity_id + '</span>' +
                    '<span class="cleanupFilename">' + escapeHtml(item.filename) + '</span>' +
                '</label>' +
            '</li>';
        });
        html += '</ul></section>';
    }

    container.innerHTML = html;
}

function toggleAll(kind, checked) {
    var selector = kind === 'file' ? '.fileCheckbox' : '.rowCheckbox';
    document.querySelectorAll(selector).forEach(function (cb) { cb.checked = checked; });
}

async function deleteSelected(kind) {
    var selector = kind === 'file' ? '.fileCheckbox' : '.rowCheckbox';
    var indexes = Array.prototype.slice.call(document.querySelectorAll(selector))
        .filter(function (cb) { return cb.checked; })
        .map(function (cb) { return parseInt(cb.dataset.index, 10); });

    if (indexes.length === 0) {
        return;
    }
    var noun = kind === 'file' ? 'orphaned file(s)' : 'dangling row(s)';
    if (!window.confirm('Delete ' + indexes.length + ' ' + noun + '? This cannot be undone.')) {
        return;
    }

    var source = kind === 'file' ? state.orphanedFiles : state.danglingRows;
    var items = indexes.map(function (i) {
        var entry = source[i];
        return kind === 'file'
            ? { type: entry.type, kind: 'file', entity_id: entry.entity_id, filename: entry.filename }
            : { type: entry.type, kind: 'row', entity_id: entry.entity_id, image_id: entry.image_id };
    });

    try {
        var answer = await Ytan.post('/admin/image-orphans/delete', { items: items });
        state.orphanedFiles = answer.data.orphaned_files;
        state.danglingRows = answer.data.dangling_rows;
        render();
    } catch (err) {
        window.alert('Delete failed: ' + err.message);
    }
}

initAdminAuth({ contentId: 'cleanupPage', onReady: scan });
