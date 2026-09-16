/**
 * Logic for the /admin/translate dev tool (templates/translate.php) - the
 * i18n editor. Talks to the admin-only GET/PUT /api/v1/translations
 * endpoints (TranslationController). Login-gate handled by admin-auth.js
 * like every other admin page (this used to keep its own separate copy of
 * that logic - consolidated once this page's chrome was rebuilt on
 * AdminLTE anyway, see todo.md). Its own chrome IS translated now (unlike
 * before), but the editor's dynamic content - the translation strings
 * themselves - obviously isn't run through t() a second time.
 */

var state = {
    locales: { en: {}, de: {} },
    usage: {},
    placeholders: {}, // key -> list of variable names its call site(s) pass, e.g. "about.p1" -> ["app"]
    dirtyKeys: {}, // key -> true
};

function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

/* ------------------------------------------------------------ Loading */

async function loadTranslations() {
    document.getElementById('editorPane').innerHTML = '<div id="emptyState" class="text-center text-secondary p-5">' + t('admin.translate.loading') + '</div>';

    try {
        var answer = await Ytan.get('/translations');
        state.locales = answer.data.locales;
        state.usage = answer.data.usage;
        state.placeholders = answer.data.placeholders;
        state.dirtyKeys = {};
        updateDirtyCount();
        renderEditor();
    } catch (err) {
        document.getElementById('editorPane').innerHTML =
            '<div id="emptyState" class="text-center text-danger p-5">' + t('admin.translate.load_failed', { error: err.message }) + '</div>';
    }
}

/* ------------------------------------------------------------ Render */

function namespaceOf(key) {
    var dot = key.indexOf('.');
    return dot === -1 ? key : key.substring(0, dot);
}

function renderEditor() {
    var container = document.getElementById('editorPane');
    container.innerHTML = '';

    var allKeys = Object.keys(state.usage).sort();
    if (allKeys.length === 0) {
        container.innerHTML = '<div id="emptyState" class="text-center text-secondary p-5">' + t('admin.translate.no_keys') + '</div>';
        return;
    }

    var query = (document.getElementById('searchInput').value || '').trim().toLowerCase();
    var groups = {};
    allKeys.forEach(function (key) {
        var ns = namespaceOf(key);
        (groups[ns] = groups[ns] || []).push(key);
    });

    var namespaces = Object.keys(groups).sort();
    var anyVisible = false;

    namespaces.forEach(function (ns) {
        var keysInGroup = groups[ns].filter(function (key) {
            return matchesFilter(key, query);
        });
        if (keysInGroup.length === 0) {
            return;
        }
        anyVisible = true;

        var details = document.createElement('details');
        details.className = 'mb-3';
        details.open = query !== '';

        var summary = document.createElement('summary');
        summary.className = 'fw-bold text-primary py-1';
        summary.style.cursor = 'pointer';
        summary.textContent = ns + ' (' + keysInGroup.length + ')';
        details.appendChild(summary);

        keysInGroup.forEach(function (key) {
            details.appendChild(buildKeyRow(key));
        });

        container.appendChild(details);
    });

    if (!anyVisible) {
        container.innerHTML = '<div id="emptyState" class="text-center text-secondary p-5">' + t('admin.translate.no_keys_match') + '</div>';
    }
}

function matchesFilter(key, query) {
    if (query === '') {
        return true;
    }
    if (key.toLowerCase().indexOf(query) !== -1) {
        return true;
    }
    var en = (state.locales.en[key] || '').toLowerCase();
    var de = (state.locales.de[key] || '').toLowerCase();
    return en.indexOf(query) !== -1 || de.indexOf(query) !== -1;
}

function buildKeyRow(key) {
    var row = document.createElement('div');
    row.className = 'card card-body mb-2';
    row.dataset.key = key;
    if (state.dirtyKeys[key]) {
        row.classList.add('border-warning');
    }

    var keyName = document.createElement('div');
    keyName.className = 'font-monospace small text-secondary mb-1 text-break';
    keyName.textContent = key;
    row.appendChild(keyName);

    var placeholderNames = state.placeholders[key] || [];
    if (placeholderNames.length > 0) {
        var availableVars = document.createElement('div');
        availableVars.className = 'font-monospace small text-primary mb-2';
        availableVars.textContent = t('admin.translate.variables_prefix') + ' ' + placeholderNames.map(function (p) { return '{' + p + '}'; }).join(', ');
        row.appendChild(availableVars);
    }

    var fields = document.createElement('div');
    fields.className = 'd-flex gap-3 flex-wrap';
    fields.appendChild(buildFieldCol(key, 'en', 'EN'));
    fields.appendChild(buildFieldCol(key, 'de', 'DE'));
    row.appendChild(fields);

    var usageEntries = state.usage[key] || [];
    var usage = document.createElement('div');
    if (usageEntries.length === 0) {
        usage.className = 'small text-warning mt-2';
        usage.textContent = t('admin.translate.not_referenced');
    } else {
        usage.className = 'small text-secondary mt-2';
        usage.textContent = t('admin.translate.used_in_prefix') + ' ' + usageEntries.map(function (u) {
            return u.file + ':' + u.line;
        }).join(', ');
    }
    row.appendChild(usage);

    var warning = document.createElement('div');
    warning.className = 'small text-warning mt-1';
    warning.hidden = true;
    row.appendChild(warning);
    updatePlaceholderWarning(key, warning);

    return row;
}

function buildFieldCol(key, locale, label) {
    var col = document.createElement('div');
    col.className = 'flex-fill';
    col.style.minWidth = '220px';

    var labelEl = document.createElement('label');
    labelEl.className = 'form-label small text-secondary mb-1';
    labelEl.textContent = label;
    col.appendChild(labelEl);

    var textarea = document.createElement('textarea');
    textarea.className = 'form-control form-control-sm';
    textarea.rows = 2;
    textarea.value = state.locales[locale][key] || '';
    textarea.addEventListener('input', function () {
        state.locales[locale][key] = textarea.value;
        state.dirtyKeys[key] = true;
        textarea.closest('.card').classList.add('border-warning');
        updatePlaceholderWarning(key, textarea.closest('.card').querySelector('.text-warning.mt-1'));
        updateDirtyCount();
    });
    col.appendChild(textarea);

    return col;
}

function extractPlaceholders(value) {
    var matches = (value || '').match(/\{(\w+)\}/g) || [];
    return matches.map(function (m) { return m.slice(1, -1); });
}

function updatePlaceholderWarning(key, warningEl) {
    if (!warningEl) {
        return;
    }
    var en = extractPlaceholders(state.locales.en[key]);
    var de = extractPlaceholders(state.locales.de[key]);
    var missingFromDe = en.filter(function (p) { return de.indexOf(p) === -1; });
    var missingFromEn = de.filter(function (p) { return en.indexOf(p) === -1; });

    if (missingFromDe.length === 0 && missingFromEn.length === 0) {
        warningEl.hidden = true;
        return;
    }

    var parts = [];
    if (missingFromDe.length > 0) {
        parts.push(t('admin.translate.missing_de') + ' ' + missingFromDe.map(function (p) { return '{' + p + '}'; }).join(', '));
    }
    if (missingFromEn.length > 0) {
        parts.push(t('admin.translate.missing_en') + ' ' + missingFromEn.map(function (p) { return '{' + p + '}'; }).join(', '));
    }
    warningEl.textContent = parts.join(' — ');
    warningEl.hidden = false;
}

function applyFilter() {
    renderEditor();
}

function updateDirtyCount() {
    var count = Object.keys(state.dirtyKeys).length;
    document.getElementById('dirtyCount').textContent = count > 0 ? t('admin.translate.unsaved_count', { count: count }) : '';
    document.getElementById('saveAllBtn').disabled = count === 0;
}

/* -------------------------------------------------------------- Save */

async function saveAll(force) {
    var btn = document.getElementById('saveAllBtn');
    btn.disabled = true;

    try {
        await Ytan.put('/translations', {
            en: state.locales.en,
            de: state.locales.de,
            force: force === true,
        });
        state.dirtyKeys = {};
        updateDirtyCount();
        document.querySelectorAll('.card.border-warning').forEach(function (el) { el.classList.remove('border-warning'); });
        reloadPreview();
        showAdminToast(t('common.saved'), 'success');
    } catch (err) {
        if (err.data && err.data.code === 'translation.key_mismatch') {
            var onlyEn = (err.data.only_in_en || []).join(', ') || t('admin.translate.none');
            var onlyDe = (err.data.only_in_de || []).join(', ') || t('admin.translate.none');
            var proceed = window.confirm(
                t('admin.translate.key_mismatch_message', { only_en: onlyEn, only_de: onlyDe })
            );
            if (proceed) {
                await saveAll(true);
                return;
            }
        } else {
            showAdminToast(t('admin.translate.save_failed', { error: err.message }), 'error');
        }
    } finally {
        updateDirtyCount();
    }
}

function reloadPreview() {
    var iframe = document.getElementById('livePreview');
    iframe.src = iframe.src;
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: loadTranslations });
