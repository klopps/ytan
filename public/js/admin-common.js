/**
 * Shared helpers for every /admin/* page (loaded by the AdminLTE shell,
 * templates/partials/admin-shell-footer.php, right after admin-auth.js).
 * Two things every admin page needs that used to be copy-pasted or simply
 * missing: a themed toast (Bootstrap's own component, not the main SPA's
 * toast.js - this area doesn't load style.css, see admin-shell-header.php's
 * doc comment) and the navbar's user-menu (display name + logout), wired
 * once here instead of by each page's own script.
 */

function showAdminToast(message, type) {
    var variant = type === 'error' ? 'danger' : (type === 'success' ? 'success' : 'info');
    var container = document.getElementById('adminToastContainer');
    if (!container) {
        return;
    }

    var toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center text-bg-' + variant + ' border-0';
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');
    toastEl.innerHTML =
        '<div class="d-flex">' +
            '<div class="toast-body"></div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
        '</div>';
    toastEl.querySelector('.toast-body').textContent = message;
    container.appendChild(toastEl);

    // Errors stay until closed: an import that fails after a minute of waiting must not vanish unseen.
    var toast = new bootstrap.Toast(toastEl, variant === 'danger' ? { autohide: false } : { delay: 4000 });
    if (variant === 'danger' && window.console) {
        console.error(message);
    }
    toastEl.addEventListener('hidden.bs.toast', function () {
        toastEl.remove();
    });
    toast.show();
}

/**
 * Asks the admin to confirm an action in a Bootstrap modal (the shell's
 * #adminConfirmModal) - the replacement for the browser's window.confirm()
 * on every admin page. Resolves to true when confirmed, false when cancelled
 * or closed. It resolves only once the modal is gone, so a dialog opened
 * next doesn't overlap this one's fade-out.
 *
 * @param {{title: string, message: string, confirmLabel: string, danger?: boolean}} options
 *        danger paints the confirm button red (deleting something)
 * @returns {Promise<boolean>}
 */
function adminConfirm(options) {
    return new Promise(function (resolve) {
        var element = document.getElementById('adminConfirmModal');
        var modal = bootstrap.Modal.getOrCreateInstance(element);
        var okButton = document.getElementById('adminConfirmOk');
        var confirmed = false;

        document.getElementById('adminConfirmTitle').textContent = options.title;
        document.getElementById('adminConfirmMessage').textContent = options.message;
        okButton.textContent = options.confirmLabel;
        okButton.className = 'btn ' + (options.danger ? 'btn-danger' : 'btn-primary');
        okButton.onclick = function () {
            confirmed = true;
            modal.hide();
        };
        element.addEventListener('hidden.bs.modal', function () { resolve(confirmed); }, { once: true });
        element.addEventListener('shown.bs.modal', function () { okButton.focus(); }, { once: true });
        modal.show();
    });
}

/**
 * Called by admin-auth.js's showContent() once a valid admin session is
 * confirmed - populates the navbar's user-menu and wires its logout
 * button. Defined here (not in admin-auth.js itself) so admin-auth.js
 * stays markup-agnostic and reusable without this shell's specific navbar.
 */
function adminOnAuthenticated(userData) {
    var nameEl = document.getElementById('adminUserDisplayName');
    if (nameEl) {
        var name = [userData.firstname, userData.lastname].filter(Boolean).join(' ') || userData.username;
        nameEl.textContent = name;
    }

    var logoutBtn = document.getElementById('adminLogoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function () {
            Ytan.setToken(null);
            location.reload();
        });
    }
}

/**
 * Loads the Google Maps script once for an admin page (the admin pages don't
 * load it otherwise); resolves when google.maps.Map is usable, rejects
 * without a key (window.YTAN_MAPS_API_KEY, set by the page's template from
 * .env's MAPS_API_KEY) or when the script fails. Used by the lighthouse
 * import's area filter and the rain radar regions in /admin/settings.
 */
var adminMapsPromise = null;

function loadAdminGoogleMaps() {
    if (adminMapsPromise) {
        return adminMapsPromise;
    }
    adminMapsPromise = new Promise(function (resolve, reject) {
        var key = window.YTAN_MAPS_API_KEY || '';
        if (key === '') {
            reject(new Error('no key'));
            return;
        }
        window.adminMapsReady = function () {
            google.maps.importLibrary('maps').then(resolve, reject);
        };
        var script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key)
            + '&loading=async&v=weekly&callback=adminMapsReady';
        script.async = true;
        script.onerror = function () { reject(new Error('script')); };
        document.head.appendChild(script);
    });
    return adminMapsPromise;
}

/**
 * Reopens the tab of a tabbed admin page the admin used last (#tabsId,
 * Bootstrap nav-tabs), remembered per page in browser storage - a
 * convenience only, the first tab is shown when storage is unavailable.
 */
function adminRememberTab(tabsId, storageKey) {
    var tabs = document.querySelectorAll('#' + tabsId + ' [data-bs-toggle="tab"]');
    tabs.forEach(function (tab) {
        tab.addEventListener('shown.bs.tab', function () {
            try { localStorage.setItem(storageKey, tab.id); } catch (e) { /* storage unavailable */ }
        });
    });
    var saved = null;
    try { saved = localStorage.getItem(storageKey); } catch (e) { /* storage unavailable */ }
    var tab = saved ? document.getElementById(saved) : null;
    if (tab && window.bootstrap) {
        bootstrap.Tab.getOrCreateInstance(tab).show();
    }
}

/** How often a page asks for the status of a background OpenStreetMap import. */
var ADMIN_OSM_JOB_POLL_MS = 4000;

/**
 * Starts loading from OpenStreetMap in the background (POST /admin/osm-import,
 * OsmImportJob - the import goes on when the page is left) and then shows its
 * progress in the load button (adminWatchOsmImport()). body = {tool, country}
 * or the lighthouse tool's {tool: 'lighthouse', south, west, north, east, label}.
 */
async function adminStartOsmImport(body, button, idleHtml, onFinished) {
    button.disabled = true;
    try {
        await Ytan.post('/admin/osm-import', body);
    } catch (err) {
        button.disabled = false;
        showAdminToast(t('admin.osm_job.start_failed', { error: translateApiError(err.data) || err.message }), 'error');

        return;
    }
    adminWatchOsmImport(body.tool, button, idleHtml, onFinished, true);
}

/**
 * Follows the background OpenStreetMap import (GET /admin/osm-import/status,
 * every ADMIN_OSM_JOB_POLL_MS): while one runs the load button is disabled
 * and says what is happening (this tool: country/area, tile, a pending retry;
 * another tool: that one is busy); when this tool's import ends - and the page
 * saw it running - a toast gives the result and onFinished() runs (reload the
 * list). Called on page load too, so coming back shows a running import.
 */
function adminWatchOsmImport(tool, button, idleHtml, onFinished, started) {
    var seenRunning = !!started;
    var lastTile = 0;
    async function poll() {
        var status;
        try {
            status = (await Ytan.get('/admin/osm-import/status')).data || {};
        } catch (err) {
            setTimeout(poll, ADMIN_OSM_JOB_POLL_MS); // a missed poll is no reason to stop following

            return;
        }
        // interrupted = the process was killed, tools/ytan_jobs.py (cron) resumes it within 5 minutes
        var active = status.running || status.state === 'starting' || status.state === 'interrupted';
        if (active) {
            button.disabled = true;
            if (status.tool !== tool) {
                button.textContent = t('admin.osm_job.other_running', { what: status.country || '' });
            } else {
                seenRunning = true;
                button.textContent = status.state === 'interrupted'
                    ? t('admin.osm_job.interrupted', { what: status.country || '', tile: status.tile || 1, tiles: status.tiles || 1 })
                    : status.state === 'retrying'
                    ? t('admin.osm_job.retrying', { what: status.country || '', tile: status.tile || 1, tiles: status.tiles || 1 })
                    : (status.tiles > 1
                        ? t('admin.osm_job.running_tile', { what: status.country || '', tile: status.tile || 1, tiles: status.tiles })
                        : t('admin.osm_job.running', { what: status.country || '' }));
                if (onFinished && lastTile && status.tile > lastTile) {
                    onFinished(false); // a tile was saved: show what came so far
                }
                lastTile = status.tile || lastTile;
            }
            setTimeout(poll, ADMIN_OSM_JOB_POLL_MS);

            return;
        }
        button.disabled = false;
        button.innerHTML = idleHtml;
        if (!seenRunning || status.tool !== tool) {
            return;
        }
        var sums = status.sums || {};
        var vars = {
            what: status.country || '', fetched: sums.fetched || 0, inserted: sums.inserted || 0,
            updated: sums.updated || 0, skipped: sums.skipped || 0, message: status.message || '',
        };
        if (status.state === 'done') {
            showAdminToast(t('admin.osm_job.done', vars), 'success');
        } else {
            showAdminToast(t('admin.osm_job.failed', vars), 'error');
        }
        if (onFinished) {
            onFinished(true);
        }
    }
    poll();
}


/* ---- where a scheduled job runs (JobPlacement) ------------------------ */

/**
 * The "run on the YTAN worker" switch of /admin/radar and /admin/wind
 * (templates/partials/admin-job-placement.php): data is the page's settings
 * object (external, run_requested, worker from JobPlacement::payload()), saved
 * with PUT /settings/{job}/placement; onChanged(newData) lets the page redraw.
 */
function initAdminJobPlacement(job, data, onChanged) {
    var box = document.getElementById('jobPlacementExternal');
    box.checked = !!data.external;
    box.addEventListener('change', function () {
        box.disabled = true;
        Ytan.put('/settings/' + job + '/placement', { external: box.checked }).then(function (answer) {
            showAdminToast(box.checked ? t('admin.job_placement.saved_external') : t('admin.job_placement.saved_local'), 'success');
            if (onChanged) {
                onChanged(answer.data);
            }
            renderAdminJobPlacement(answer.data);
        }).catch(function (err) {
            box.checked = !box.checked;
            showAdminToast(t('common.save_failed', { error: err.data ? translateApiError(err.data) : err.message }), 'error');
        }).finally(function () {
            box.disabled = false;
        });
    });
    renderAdminJobPlacement(data);
}

/** The worker line: last contact, host, what it did - or that it never reported / is away. */
function renderAdminJobPlacement(data) {
    var el = document.getElementById('jobPlacementWorker');
    if (!el) {
        return;
    }
    document.getElementById('jobPlacementExternal').checked = !!data.external;
    var worker = data.worker;
    var parts = [];
    if (!worker) {
        parts.push(t('admin.job_placement.worker_never'));
    } else {
        var minutes = Math.max(0, Math.round((Date.now() - new Date(worker.tick)) / 60000));
        parts.push(t('admin.job_placement.worker_contact', { min: minutes, host: worker.host || '?' }));
        if (worker.running && worker.running.job) {
            parts.push(t('admin.job_placement.worker_running', { job: worker.running.job }));
        }
        if (worker.stale) {
            parts.push(t('admin.job_placement.worker_stale'));
        }
    }
    if (data.external && data.run_requested) {
        parts.push(t('admin.job_placement.run_requested'));
    }
    el.textContent = parts.join(' ');
    el.className = worker && worker.stale && data.external ? 'text-danger' : 'text-secondary';
}
