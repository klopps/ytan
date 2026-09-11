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
    var confirmLabel = opts.confirmLabel || t('confirm_dialog.ok');
    var cancelLabel = opts.cancelLabel || t('common.cancel');

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

/**
 * Same overlay/dialog shell as showConfirmDialog() above, plus a numeric
 * input - used for the "solve a simple captcha before deleting a
 * tour-linked route" flow (route.js: deleteRoute()). Resolves the entered
 * number, or null if cancelled.
 */
function showCaptchaDialog(question) {
    return new Promise(function (resolve) {
        var overlay = document.createElement('div');
        overlay.className = 'confirm-dialog-overlay';

        var dialog = document.createElement('div');
        dialog.className = 'confirm-dialog confirm-dialog-default';

        var icon = document.createElement('i');
        icon.className = 'material-icons-round confirm-dialog-icon';
        icon.textContent = 'quiz';

        var text = document.createElement('div');
        text.className = 'confirm-dialog-message';
        text.textContent = t('confirm_dialog.captcha_message', { question: question });

        var input = document.createElement('input');
        input.type = 'number';
        input.className = 'confirm-dialog-input';
        input.inputMode = 'numeric';

        var actions = document.createElement('div');
        actions.className = 'confirm-dialog-actions';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'button';
        cancelBtn.textContent = t('common.cancel');

        var confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'startbtn';
        confirmBtn.textContent = t('common.delete');

        function close(result) {
            document.removeEventListener('keydown', onKeydown);
            overlay.remove();
            resolve(result);
        }

        function submit() {
            if (input.value === '') {
                input.focus();
                return;
            }
            close(parseInt(input.value, 10));
        }

        function onKeydown(event) {
            if (event.key === 'Escape') {
                close(null);
            } else if (event.key === 'Enter') {
                submit();
            }
        }

        cancelBtn.addEventListener('click', function () {
            close(null);
        });
        confirmBtn.addEventListener('click', submit);

        actions.appendChild(cancelBtn);
        actions.appendChild(confirmBtn);

        dialog.appendChild(icon);
        dialog.appendChild(text);
        dialog.appendChild(input);
        dialog.appendChild(actions);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        document.addEventListener('keydown', onKeydown);
        input.focus();
    });
}
