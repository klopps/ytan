/**
 * In-UI confirmation dialog, replacing native confirm() for delete/action
 * confirmations. Unlike confirm(), this doesn't block synchronously - it
 * returns a Promise<boolean> that callers must await.
 */

const CONFIRM_DIALOG_ICON = {
    danger: 'warning',
    default: 'info',
};

function showConfirmDialog(message, options) {
    var opts = options || {};
    var type = CONFIRM_DIALOG_ICON.hasOwnProperty(opts.type) ? opts.type : 'default';
    var confirmLabel = opts.confirmLabel || 'OK';
    var cancelLabel = opts.cancelLabel || 'Cancel';

    return new Promise(function (resolve) {
        var overlay = document.createElement('div');
        overlay.className = 'confirm-dialog-overlay';

        var dialog = document.createElement('div');
        dialog.className = 'confirm-dialog confirm-dialog-' + type;

        var icon = document.createElement('i');
        icon.className = 'material-icons-round confirm-dialog-icon';
        icon.textContent = CONFIRM_DIALOG_ICON[type];

        var text = document.createElement('div');
        text.className = 'confirm-dialog-message';
        text.textContent = message;

        var actions = document.createElement('div');
        actions.className = 'confirm-dialog-actions';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'button';
        cancelBtn.textContent = cancelLabel;

        var confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'startbtn';
        confirmBtn.textContent = confirmLabel;

        function close(result) {
            document.removeEventListener('keydown', onKeydown);
            overlay.remove();
            resolve(result);
        }

        // Escape mirrors native confirm()'s cancel-on-Escape behaviour.
        // Enter is intentionally NOT wired to the confirm button - Cancel
        // gets default focus below, so pressing Enter without deliberately
        // tabbing to "Delete" cancels instead of confirming a destructive
        // action.
        function onKeydown(event) {
            if (event.key === 'Escape') {
                close(false);
            }
        }

        cancelBtn.addEventListener('click', function () {
            close(false);
        });
        confirmBtn.addEventListener('click', function () {
            close(true);
        });

        actions.appendChild(cancelBtn);
        actions.appendChild(confirmBtn);

        dialog.appendChild(icon);
        dialog.appendChild(text);
        dialog.appendChild(actions);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        document.addEventListener('keydown', onKeydown);
        cancelBtn.focus();
    });
}
