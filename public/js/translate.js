/**
 * Logic for the standalone /translate dev tool (templates/translate.php).
 * Loaded only there - never added to app.php's SPA script chain. Talks to
 * the admin-only GET/PUT /api/v1/translations endpoints
 * (TranslationController) via the same api-client.js the SPA uses, but is
 * otherwise fully self-contained: its own login flow (not user.js's
 * loginUser(), which depends on SPA-only globals this page never loads),
 * its own tiny render logic, no i18n.js (the tool's own chrome isn't
 * translated - it edits the translations, it doesn't consume them).
 */

var state = {
    locales: { en: {}, de: {} },
    usage: {},
    dirtyKeys: {}, // key -> true
};

function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

/* ---------------------------------------------------------------- Auth */

async function checkAuthAndInit() {
    var token = localStorage.getItem('ytan_token');
    if (!token) {
        showLogin();
        return;
    }

    try {
        var me = await Ytan.get('/auth/me');
        if (!me.data.is_admin) {
            showLogin('Signed in, but this account is not an admin.');
            return;
        }
        showTool();
        await loadTranslations();
    } catch (err) {
        showLogin();
    }
}

function showLogin(message) {
    document.getElementById('loginBox').hidden = false;
    document.getElementById('toolbar').hidden = true;
    document.getElementById('panes').hidden = true;
    if (message) {
        document.getElementById('loginMessage').textContent = message;
    }
}

function showTool() {
    document.getElementById('loginBox').hidden = true;
    document.getElementById('toolbar').hidden = false;
    document.getElementById('panes').hidden = false;
}

async function submitLogin() {
    var username = document.getElementById('loginUsername').value;
    var password = document.getElementById('loginPassword').value;
    var message = document.getElementById('loginMessage');
    message.textContent = '';

    try {
        var answer = await Ytan.post('/auth/login', { username: username, password: password });
        if (!answer.user.is_admin) {
            message.textContent = 'Signed in, but this account is not an admin.';
            return;
        }
        Ytan.setToken(answer.token);
        showTool();
        await loadTranslations();
    } catch (err) {
        message.textContent = err.message;
    }
}

/* ------------------------------------------------------------ Loading */

async function loadTranslations() {
    document.getElementById('editorPane').innerHTML = '<div id="emptyState">Loading…</div>';

    try {
        var answer = await Ytan.get('/translations');
        state.locales = answer.data.locales;
        state.usage = answer.data.usage;
        state.dirtyKeys = {};
        updateDirtyCount();
        renderEditor();
    } catch (err) {
        document.getElementById('editorPane').innerHTML =
            '<div id="emptyState">Failed to load translations: ' + escapeHtml(err.message) + '</div>';
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
        container.innerHTML = '<div id="emptyState">No translation keys found.</div>';
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
        details.className = 'namespaceGroup';
        details.open = query !== '';

        var summary = document.createElement('summary');
        summary.textContent = ns + ' (' + keysInGroup.length + ')';
        details.appendChild(summary);

        keysInGroup.forEach(function (key) {
            details.appendChild(buildKeyRow(key));
        });

        container.appendChild(details);
    });

    if (!anyVisible) {
        container.innerHTML = '<div id="emptyState">No keys match your filter.</div>';
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
    row.className = 'keyRow';
    row.dataset.key = key;
    if (state.dirtyKeys[key]) {
        row.classList.add('dirty');
    }

    var keyName = document.createElement('div');
    keyName.className = 'keyName';
    keyName.textContent = key;
    row.appendChild(keyName);

    var fields = document.createElement('div');
    fields.className = 'fields';
    fields.appendChild(buildFieldCol(key, 'en', 'EN'));
    fields.appendChild(buildFieldCol(key, 'de', 'DE'));
    row.appendChild(fields);

    var usageEntries = state.usage[key] || [];
    var usage = document.createElement('div');
    if (usageEntries.length === 0) {
        usage.className = 'usage unused';
        usage.textContent = 'Not referenced anywhere - candidate for removal.';
    } else {
        usage.className = 'usage';
        usage.textContent = 'Used in: ' + usageEntries.map(function (u) {
            return u.file + ':' + u.line;
        }).join(', ');
    }
    row.appendChild(usage);

    var warning = document.createElement('div');
    warning.className = 'placeholderWarning';
    warning.hidden = true;
    row.appendChild(warning);
    updatePlaceholderWarning(key, warning);

    return row;
}

function buildFieldCol(key, locale, label) {
    var col = document.createElement('div');
    col.className = 'fieldCol';

    var labelEl = document.createElement('label');
    labelEl.textContent = label;
    col.appendChild(labelEl);

    var textarea = document.createElement('textarea');
    textarea.rows = 2;
    textarea.value = state.locales[locale][key] || '';
    textarea.addEventListener('input', function () {
        state.locales[locale][key] = textarea.value;
        state.dirtyKeys[key] = true;
        textarea.closest('.keyRow').classList.add('dirty');
        updatePlaceholderWarning(key, textarea.closest('.keyRow').querySelector('.placeholderWarning'));
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
        parts.push('DE missing: ' + missingFromDe.map(function (p) { return '{' + p + '}'; }).join(', '));
    }
    if (missingFromEn.length > 0) {
        parts.push('EN missing: ' + missingFromEn.map(function (p) { return '{' + p + '}'; }).join(', '));
    }
    warningEl.textContent = parts.join(' — ');
    warningEl.hidden = false;
}

function applyFilter() {
    renderEditor();
}

function updateDirtyCount() {
    var count = Object.keys(state.dirtyKeys).length;
    document.getElementById('dirtyCount').textContent = count > 0 ? count + ' unsaved' : '';
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
        document.querySelectorAll('.keyRow.dirty').forEach(function (el) { el.classList.remove('dirty'); });
        reloadPreview();
    } catch (err) {
        if (err.data && err.data.code === 'translation.key_mismatch') {
            var onlyEn = (err.data.only_in_en || []).join(', ') || '(none)';
            var onlyDe = (err.data.only_in_de || []).join(', ') || '(none)';
            var proceed = window.confirm(
                'Key sets differ between EN and DE.\n\nOnly in EN: ' + onlyEn + '\nOnly in DE: ' + onlyDe +
                '\n\nSave anyway? (missing keys will simply not exist in that locale)'
            );
            if (proceed) {
                await saveAll(true);
                return;
            }
        } else {
            window.alert('Save failed: ' + err.message);
        }
    } finally {
        updateDirtyCount();
    }
}

function reloadPreview() {
    var iframe = document.getElementById('livePreview');
    iframe.src = iframe.src;
}

checkAuthAndInit();
