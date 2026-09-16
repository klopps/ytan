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
    document.getElementById('cleanupContent').innerHTML = '<p>' + t('admin.image_cleanup.scanning') + '</p>';
    try {
        var answer = await Ytan.get('/admin/image-orphans');
        state.orphanedFiles = answer.data.orphaned_files;
        state.danglingRows = answer.data.dangling_rows;
        render();
    } catch (err) {
        document.getElementById('cleanupContent').innerHTML = '<p class="text-danger">' + t('admin.image_cleanup.scan_failed', { error: err.message }) + '</p>';
    }
}

function renderSection(items, kind, titleKey, hintKey, hasSize) {
    var html = '<div class="card mb-4">' +
        '<div class="card-header d-flex justify-content-between align-items-center">' +
            '<div>' +
                '<h2 class="h6 mb-1">' + t(titleKey, { count: items.length }) + '</h2>' +
                '<p class="text-secondary small mb-0">' + t(hintKey) + '</p>' +
            '</div>' +
            '<button type="button" class="btn btn-danger btn-sm" onclick="deleteSelected(\'' + kind + '\');">' + t('admin.image_cleanup.delete_selected') + '</button>' +
        '</div>' +
        '<div class="table-responsive">' +
        '<table class="table table-sm table-hover align-middle mb-0">' +
        '<thead><tr>' +
            '<th style="width:2.5rem;"><input type="checkbox" class="form-check-input" onchange="toggleAll(\'' + kind + '\', this.checked);"></th>' +
            '<th>' + t('admin.image_cleanup.col_type') + '</th>' +
            '<th>' + t('admin.image_cleanup.col_id') + '</th>' +
            '<th>' + t('admin.image_cleanup.col_filename') + '</th>' +
            (hasSize ? '<th>' + t('admin.image_cleanup.col_size') + '</th>' : '') +
        '</tr></thead><tbody>';

    items.forEach(function (item, index) {
        html += '<tr>' +
            '<td><input type="checkbox" class="form-check-input ' + kind + 'Checkbox" data-index="' + index + '"></td>' +
            '<td class="text-capitalize">' + escapeHtml(item.type) + '</td>' +
            '<td>#' + item.entity_id + '</td>' +
            '<td class="font-monospace small text-break">' + escapeHtml(item.filename) + '</td>' +
            (hasSize ? '<td class="text-nowrap">' + formatBytes(item.size_bytes) + '</td>' : '') +
        '</tr>';
    });

    html += '</tbody></table></div></div>';
    return html;
}

function render() {
    var container = document.getElementById('cleanupContent');

    if (state.orphanedFiles.length === 0 && state.danglingRows.length === 0) {
        container.innerHTML = '<p id="emptyState" class="text-secondary">' + t('admin.image_cleanup.empty') + '</p>';
        return;
    }

    var html = '';
    if (state.orphanedFiles.length > 0) {
        html += renderSection(state.orphanedFiles, 'file', 'admin.image_cleanup.orphaned_files_title', 'admin.image_cleanup.orphaned_files_hint', true);
    }
    if (state.danglingRows.length > 0) {
        html += renderSection(state.danglingRows, 'row', 'admin.image_cleanup.dangling_rows_title', 'admin.image_cleanup.dangling_rows_hint', false);
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
    if (!window.confirm(t('admin.image_cleanup.confirm_delete', { count: indexes.length }))) {
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
        showAdminToast(t('common.saved'), 'success');
    } catch (err) {
        showAdminToast(t('common.delete_failed', { error: err.message }), 'error');
    }
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: scan });
