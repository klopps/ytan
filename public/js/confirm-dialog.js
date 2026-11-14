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

/**
 * "Merge routes" dialog (route.js: confirmRouteMerge()) - same overlay shell
 * as above. Two radio buttons pick the route that stays (A preselected =
 * the route whose context menu started it), a name field starts with
 * opts.suggestName(keptIsA) and follows the radio choice until the user
 * types in it, a hint names the route that will be deleted.
 * Resolves {keptIsA, name}, or null if cancelled.
 *
 * @param {{nameA: string, nameB: string, suggestName: function(boolean): string}} opts
 */
function showRouteMergeDialog(opts) {
    return new Promise(function (resolve) {
        var overlay = document.createElement('div');
        overlay.className = 'confirm-dialog-overlay';

        var dialog = document.createElement('div');
        dialog.className = 'confirm-dialog confirm-dialog-default route-merge-dialog';

        var icon = document.createElement('i');
        icon.className = 'material-icons-round confirm-dialog-icon';
        icon.textContent = 'call_merge';

        var title = document.createElement('div');
        title.className = 'confirm-dialog-message';
        title.textContent = t('route.merge.dialog_title');

        var choice = document.createElement('div');
        choice.className = 'route-merge-choice';
        var radios = [];
        [[true, opts.nameA], [false, opts.nameB]].forEach(function (option, n) {
            var row = document.createElement('div');
            row.className = 'route-merge-choice-row';
            var radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'routeMergeKept';
            radio.id = 'routeMergeKept' + n;
            radio.checked = option[0];
            var label = document.createElement('label');
            label.htmlFor = radio.id;
            label.textContent = t('route.merge.keep', { name: option[1] });
            row.appendChild(radio);
            row.appendChild(label);
            choice.appendChild(row);
            radios.push(radio);
        });

        var nameLabel = document.createElement('label');
        nameLabel.className = 'route-merge-name-label';
        nameLabel.htmlFor = 'routeMergeName';
        nameLabel.textContent = t('route.merge.name');

        var input = document.createElement('input');
        input.type = 'text';
        input.id = 'routeMergeName';
        input.className = 'confirm-dialog-input route-merge-name';
        input.maxLength = 150;

        var hint = document.createElement('div');
        hint.className = 'route-merge-hint';

        var actions = document.createElement('div');
        actions.className = 'confirm-dialog-actions';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'button';
        cancelBtn.textContent = t('common.cancel');

        var confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'startbtn';
        confirmBtn.textContent = t('route.merge.confirm');

        var nameEdited = false;
        function keptIsA() {
            return radios[0].checked;
        }
        function refresh() {
            if (!nameEdited) {
                input.value = opts.suggestName(keptIsA());
            }
            hint.textContent = t('route.merge.delete_hint', { name: keptIsA() ? opts.nameB : opts.nameA });
        }

        function close(result) {
            document.removeEventListener('keydown', onKeydown);
            overlay.remove();
            resolve(result);
        }

        function submit() {
            var name = input.value.trim();
            if (name === '') {
                input.focus();
                return;
            }
            close({ keptIsA: keptIsA(), name: name });
        }

        function onKeydown(event) {
            if (event.key === 'Escape') {
                close(null);
            } else if (event.key === 'Enter' && event.target === input) {
                submit();
            }
        }

        radios.forEach(function (radio) {
            radio.addEventListener('change', refresh);
        });
        input.addEventListener('input', function () {
            nameEdited = true;
        });
        cancelBtn.addEventListener('click', function () {
            close(null);
        });
        confirmBtn.addEventListener('click', submit);

        actions.appendChild(cancelBtn);
        actions.appendChild(confirmBtn);

        dialog.appendChild(icon);
        dialog.appendChild(title);
        dialog.appendChild(choice);
        dialog.appendChild(nameLabel);
        dialog.appendChild(input);
        dialog.appendChild(hint);
        dialog.appendChild(actions);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        refresh();
        document.addEventListener('keydown', onKeydown);
        input.focus();
    });
}
