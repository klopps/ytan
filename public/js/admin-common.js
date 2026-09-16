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
