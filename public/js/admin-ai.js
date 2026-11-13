/**
 * Logic for /admin/ai (templates/admin-ai.php): the Routencheck's switch,
 * daily limit and text cache (PUT /settings/ai), the connection test
 * (POST /settings/ai/test), the token usage per month and user
 * (GET /admin/ai/usage; the table sorts client-side - one row per user) and
 * the traffic-light rules (PUT /settings/briefing-rules).
 */
var aiUsage = { users: [], sortKey: 'total_tokens', sortDir: -1 };

function aiNumber(value, digits) {
    if (value === null || value === undefined) {
        return '-';
    }
    return Number(value).toLocaleString(window.YTAN_LOCALE || undefined, { maximumFractionDigits: digits || 0 });
}

function aiFormatTime(iso) {
    if (!iso) {
        return '-';
    }
    return new Date(iso).toLocaleString(window.YTAN_LOCALE || undefined, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function aiMonthLabel(month) {
    var parts = month.split('-');
    return new Date(Number(parts[0]), Number(parts[1]) - 1, 1).toLocaleDateString(window.YTAN_LOCALE || undefined, { month: 'long', year: 'numeric' });
}

function saveAiSettings() {
    var btn = document.getElementById('aiSaveBtn');
    btn.disabled = true;
    Ytan.put('/settings/ai', {
        enabled: document.getElementById('aiEnabled').checked,
        daily_limit: parseInt(document.getElementById('aiDailyLimit').value, 10),
        cache_hours: parseInt(document.getElementById('aiCacheHours').value, 10),
    }).then(function () {
        showAdminToast(t('admin.ai.saved'), 'success');
    }).catch(function (err) {
        showAdminToast(err.message, 'error');
    }).finally(function () {
        btn.disabled = false;
    });
}

function testAiConnection() {
    var btn = document.getElementById('aiTestBtn');
    btn.disabled = true;
    Ytan.post('/settings/ai/test', {}).then(function (answer) {
        showAdminToast(t('admin.ai.test_ok', {
            model: answer.data.model, text: answer.data.text, input: answer.data.input_tokens, output: answer.data.output_tokens,
        }), 'success');
        loadAiUsage(document.getElementById('aiMonth').value || null);
    }).catch(function (err) {
        showAdminToast(t('admin.ai.test_failed', { error: err.message }), 'error');
    }).finally(function () {
        btn.disabled = false;
    });
}

function renderAiSummary(data) {
    function box(title, summary) {
        return '<div class="col-12 col-md-6"><div class="border rounded p-2 h-100">' +
            '<div class="small fw-bold text-secondary text-uppercase mb-1">' + escapeHTML(title) + '</div>' +
            '<div class="small">' + t('admin.ai.requests') + ': <strong>' + aiNumber(summary.requests) + '</strong></div>' +
            '<div class="small">' + t('admin.ai.avg_tokens') + ': <strong>' + aiNumber(summary.avg_total) + '</strong> ' +
                (summary.avg_total !== null ? '<span class="text-secondary">(' + t('admin.ai.avg_detail', { input: aiNumber(summary.avg_input), output: aiNumber(summary.avg_output) }) + ')</span>' : '') + '</div>' +
            '<div class="small">' + t('admin.ai.total_tokens') + ': ' + aiNumber(summary.total_tokens) + '</div>' +
            '<div class="small text-secondary">' + t('admin.ai.cache_hits') + ': ' + aiNumber(summary.cache_hits) + ' · ' +
                t('admin.ai.errors') + ': ' + aiNumber(summary.errors) + ' · ' + t('admin.ai.limited') + ': ' + aiNumber(summary.limited) + '</div>' +
            '</div></div>';
    }
    document.getElementById('aiSummary').innerHTML =
        box(aiMonthLabel(data.month), data.summary_month) + box(t('admin.ai.all_time'), data.summary_all);
}

function renderAiUsageRows() {
    var rows = aiUsage.users.slice();
    var key = aiUsage.sortKey;
    var dir = aiUsage.sortDir;
    rows.sort(function (a, b) {
        var av = a[key];
        var bv = b[key];
        if (key === 'username') {
            return dir * String(av).localeCompare(String(bv), window.YTAN_LOCALE || undefined, { sensitivity: 'base' });
        }
        return dir * ((av || 0) - (bv || 0)) || String(a.username).localeCompare(String(b.username));
    });
    var body = document.getElementById('aiUsageRows');
    if (rows.length === 0) {
        body.innerHTML = '<tr><td colspan="7" class="text-secondary small">' + t('admin.ai.no_users') + '</td></tr>';
    } else {
        body.innerHTML = rows.map(function (u) {
            var badges = u.deleted ? ' <span class="badge text-bg-secondary">' + t('admin.ai.deleted_user') + '</span>'
                : (!u.eligible ? ' <span class="badge text-bg-warning">' + t('admin.ai.no_right') + '</span>' : '');
            return '<tr>' +
                '<td class="ai-sticky">' + escapeHTML(u.username) + badges + (u.name ? '<div class="small text-secondary">' + escapeHTML(u.name) + '</div>' : '') + '</td>' +
                '<td class="text-end">' + aiNumber(u.requests) + '</td>' +
                '<td class="text-end">' + aiNumber(u.input_tokens) + '</td>' +
                '<td class="text-end">' + aiNumber(u.output_tokens) + '</td>' +
                '<td class="text-end fw-semibold">' + aiNumber(u.total_tokens) + '</td>' +
                '<td class="text-end">' + aiNumber(u.avg_total) + '</td>' +
                '<td class="small">' + aiFormatTime(u.last_used) + '</td>' +
                '</tr>';
        }).join('');
    }
    document.querySelectorAll('.ai-sort').forEach(function (btn) {
        var active = btn.dataset.sort === key;
        btn.setAttribute('aria-sort', active ? (dir > 0 ? 'ascending' : 'descending') : 'none');
        btn.textContent = btn.textContent.replace(/ [▲▼]$/, '') + (active ? (dir > 0 ? ' ▲' : ' ▼') : '');
    });
}

function sortAiUsage(key) {
    if (aiUsage.sortKey === key) {
        aiUsage.sortDir = -aiUsage.sortDir;
    } else {
        aiUsage.sortKey = key;
        aiUsage.sortDir = key === 'username' ? 1 : -1;
    }
    renderAiUsageRows();
}

function loadAiUsage(month) {
    var query = month ? '?month=' + encodeURIComponent(month) : '';
    return Ytan.get('/admin/ai/usage' + query).then(function (answer) {
        var data = answer.data;
        var select = document.getElementById('aiMonth');
        select.innerHTML = data.months.map(function (m) {
            return '<option value="' + escapeHTML(m) + '"' + (m === data.month ? ' selected' : '') + '>' + escapeHTML(aiMonthLabel(m)) + '</option>';
        }).join('');
        aiUsage.users = data.users;
        renderAiSummary(data);
        renderAiUsageRows();
    }).catch(function (err) {
        showAdminToast(err.message, 'error');
    });
}

function fillAiRules(rules) {
    document.querySelectorAll('.ai-rule').forEach(function (input) {
        var path = input.dataset.rule.split('.');
        var value = path.length === 2 ? rules[path[0]][path[1]] : rules[path[0]];
        input.value = value;
        var defaults = window.YTAN_AI_ADMIN.defaults;
        var def = path.length === 2 ? defaults[path[0]][path[1]] : defaults[path[0]];
        input.title = t('admin.ai.default_value', { value: def });
    });
}

function collectAiRules() {
    var rules = {};
    document.querySelectorAll('.ai-rule').forEach(function (input) {
        var path = input.dataset.rule.split('.');
        var value = input.value === '' ? null : Number(input.value);
        if (path.length === 2) {
            rules[path[0]] = rules[path[0]] || {};
            rules[path[0]][path[1]] = value;
        } else {
            rules[path[0]] = value;
        }
    });
    return rules;
}

function saveAiRules(reset) {
    var btn = document.getElementById(reset ? 'aiRulesResetBtn' : 'aiRulesSaveBtn');
    btn.disabled = true;
    Ytan.put('/settings/briefing-rules', reset ? { reset: true } : { rules: collectAiRules() }).then(function (answer) {
        window.YTAN_AI_ADMIN.rules = answer.data.rules;
        fillAiRules(answer.data.rules);
        showAdminToast(t('admin.ai.rules_saved'), 'success');
    }).catch(function (err) {
        showAdminToast(err.message, 'error');
    }).finally(function () {
        btn.disabled = false;
    });
}

async function resetAiRules() {
    var ok = await adminConfirm({ title: t('admin.ai.rules_reset'), message: t('admin.ai.rules_reset_confirm'), confirmLabel: t('admin.ai.rules_reset') });
    if (ok) {
        saveAiRules(true);
    }
}

function initAiPage() {
    document.getElementById('aiSaveBtn').addEventListener('click', saveAiSettings);
    document.getElementById('aiTestBtn').addEventListener('click', testAiConnection);
    document.getElementById('aiMonth').addEventListener('change', function (event) { loadAiUsage(event.target.value); });
    document.querySelectorAll('.ai-sort').forEach(function (btn) {
        btn.addEventListener('click', function () { sortAiUsage(btn.dataset.sort); });
    });
    document.getElementById('aiRulesSaveBtn').addEventListener('click', function () { saveAiRules(false); });
    document.getElementById('aiRulesResetBtn').addEventListener('click', resetAiRules);
    fillAiRules(window.YTAN_AI_ADMIN.rules);
    loadAiUsage(null);
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: initAiPage });
