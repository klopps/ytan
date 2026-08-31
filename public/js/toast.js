/**
 * In-UI toast notifications, replacing native alert() for one-way status
 * messages (success/error/warning/info). Confirmation dialogs use the
 * separate showConfirmDialog() in confirm-dialog.js instead.
 */

const TOAST_ICON = {
    success: 'check_circle',
    error: 'error',
    warning: 'warning',
    info: 'info',
};

// Errors have no auto-dismiss - unlike alert(), a toast doesn't force the
// user to acknowledge it, so an error must stay until manually closed
// (or replaced) instead of risking being missed.
const TOAST_AUTO_DISMISS_MS = {
    success: 4000,
    error: null,
    warning: 6000,
    info: 4000,
};

function showToast(message, type) {
    var effectiveType = TOAST_ICON.hasOwnProperty(type) ? type : 'info';
    var container = document.getElementById('toastContainer');
    if (!container) {
        return;
    }

    var toast = document.createElement('div');
    toast.className = 'toast toast-' + effectiveType;

    var icon = document.createElement('i');
    icon.className = 'material-icons-round toast-icon';
    icon.textContent = TOAST_ICON[effectiveType];

    var text = document.createElement('span');
    text.className = 'toast-message';
    text.textContent = message;

    var close = document.createElement('i');
    close.className = 'material-icons-round toast-close';
    close.textContent = 'close';
    close.addEventListener('click', function () {
        dismissToast(toast);
    });

    toast.appendChild(icon);
    toast.appendChild(text);
    toast.appendChild(close);
    container.appendChild(toast);

    // Triggers the CSS transition (adding the class in the same frame the
    // element is inserted would skip straight to the end state instead).
    window.requestAnimationFrame(function () {
        toast.classList.add('toast-visible');
    });

    var duration = TOAST_AUTO_DISMISS_MS[effectiveType];
    if (duration !== null) {
        window.setTimeout(function () {
            dismissToast(toast);
        }, duration);
    }
}

function dismissToast(toast) {
    if (!toast.parentNode) {
        return;
    }
    toast.classList.remove('toast-visible');
    window.setTimeout(function () {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 200);
}
