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

    var toast = new bootstrap.Toast(toastEl, { delay: 4000 });
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
