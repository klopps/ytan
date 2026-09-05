/**
 * Admin-only user management panel (list / create / edit / delete users,
 * plus per-user password actions).
 *
 * A full-screen slide-in panel (the .cookiemenu pattern also used by the
 * cookie/legal menus, see ui.js: openCookieMenu/openLegalMenu) rather than
 * a nested nav-menu.js drawer screen - the account table needs more width
 * than the ~320px drawer can offer. Triggered from the "Site Settings"
 * drawer screen (nav-menu.js), which is closed first for a clean transition.
 * New users get no password field in the create form - they always set
 * their own password through the emailed invite link (see
 * UserController::create() on the backend, which sends that email on
 * POST /api/v1/users). Admins can still set a password directly for an
 * existing user (setUserPasswordRow) or trigger the same reset email a
 * "Forgot password?" request would send (sendResetEmailRow).
 */

function openUserAdminMenu() {
    closeMenu();
    document.getElementById("useradminmenu").style.width = "100%";
    document.getElementById("useradminmenu-close-btn").style.display = "flex";
    panelOpened();
    closeUserForm();
    loadUserList();
}

function closeUserAdminMenu() {
    document.getElementById("useradminmenu").style.width = "0%";
    document.getElementById("useradminmenu-close-btn").style.display = "none";
    panelClosed();
}

function loadUserList() {
    Ytan.get('/users').then(answer => {
        log('loadUserList()', LOG_INFO, answer);
        renderUserTable(answer.data);
    }).catch(err => {
        log('loadUserList() failed', LOG_ERROR, err);
        showToast('Loading users failed: ' + err.message, 'error');
    });
}

function iconButton(icon, title, onclick) {
    // onclick is JS source (may itself contain a JSON.stringify()'d string
    // argument, i.e. embedded double quotes) being placed inside a
    // double-quoted HTML attribute, so it must be HTML-escaped here -
    // the browser HTML-attribute-decodes it before running it as JS.
    return '<span class="icon-btn" title="' + escapeHTML(title) + '" onclick="' + escapeHTML(onclick) + '">' +
        '<i class="material-icons-round">' + icon + '</i>' +
        '</span>';
}

function renderUserTable(users) {
    var html = '<div class="admin-panel-header">' +
        '<div class="startbtn" onclick="showUserCreateForm();"><i class="material-icons-round">person_add</i>&nbsp;New user</div>' +
        '</div>';

    html += '<div class="admin-user-table-wrap"><table class="admin-user-table"><thead><tr>' +
        '<th>Username</th><th>Email</th><th>Name</th><th></th><th class="actions">Actions</th>' +
        '</tr></thead><tbody>';

    for (let i = 0; i < users.length; i++) {
        var u = users[i];
        if (u.username !== "system")  { // hide user "system"
            var name = [u.firstname, u.lastname].filter(Boolean).map(escapeHTML).join(' ');
            html += '<tr>' +
                '<td data-label="Username">' + escapeHTML(u.username) + '</td>' +
                '<td data-label="Email">' + escapeHTML(u.email || '') + '</td>' +
                '<td data-label="Name">' + name + '</td>' +
                '<td data-label="Role">' + (u.is_admin ? '<span class="admin-badge">Admin</span>' : '') + '</td>' +
                '<td class="actions" data-label="Actions">' +
                    iconButton('edit', 'Edit', 'editUserRow(' + u.id + ');') +
                    iconButton('vpn_key', 'Set password', 'showSetPasswordForm(' + u.id + ', ' + JSON.stringify(u.username) + ');') +
                    iconButton('forward_to_inbox', 'Send password reset email', 'sendResetEmailRow(' + u.id + ');') +
                    iconButton('delete', 'Delete', 'deleteUserRow(' + u.id + ');') +
                '</td>' +
                '</tr>';
        }
    }

    html += '</tbody></table></div>';

    document.getElementById('useradminmenu-list').innerHTML = html;
}

function userFormHtml(u) {
    u = u || { id: null, username: '', email: '', firstname: '', lastname: '', is_admin: false };

    var saveCall = u.id === null ? 'saveNewUser()' : 'saveEditedUser(' + u.id + ')';
    var hint = u.id === null
        ? '<p class="hint">The user will receive an email with a link to set their own password.</p>'
        : '';

    return '<div class="admin-user-form">' +
        '<h3>' + (u.id === null ? 'New user' : 'Edit user') + '</h3>' +
        hint +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="userFormUsername">Username: </label></div>' +
            '<div class="adminFormField"><input id="userFormUsername" type="text" value="' + escapeHTML(u.username) + '"></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="userFormEmail">Email: </label></div>' +
            '<div class="adminFormField"><input id="userFormEmail" type="email" value="' + escapeHTML(u.email || '') + '"></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="userFormFirstname">First name: </label></div>' +
            '<div class="adminFormField"><input id="userFormFirstname" type="text" value="' + escapeHTML(u.firstname || '') + '"></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="userFormLastname">Last name: </label></div>' +
            '<div class="adminFormField"><input id="userFormLastname" type="text" value="' + escapeHTML(u.lastname || '') + '"></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="userFormIsAdmin">Admin: </label></div>' +
            '<div class="adminFormField"><input id="userFormIsAdmin" type="checkbox"' + (u.is_admin ? ' checked' : '') + '><label for="userFormIsAdmin"><span></span></label></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel">&nbsp;</div>' +
            '<div class="adminFormField">' +
                '<button id="userFormSaveBtn" class="button" type="button" onclick="' + saveCall + '">Save</button>&nbsp;' +
                '<button class="button" type="button" onclick="closeUserForm();">Cancel</button>' +
            '</div>' +
        '</div>' +
        '</div>';
}

function showUserCreateForm() {
    document.getElementById('useradminmenu-form').innerHTML = userFormHtml(null);
}

function editUserRow(id) {
    Ytan.get('/users/' + id).then(answer => {
        document.getElementById('useradminmenu-form').innerHTML = userFormHtml(answer.data);
    }).catch(err => {
        showToast('Loading user failed: ' + err.message, 'error');
    });
}

function closeUserForm() {
    document.getElementById('useradminmenu-form').innerHTML = '';
}

function readUserForm() {
    return {
        username: document.getElementById('userFormUsername').value,
        email: document.getElementById('userFormEmail').value,
        firstname: document.getElementById('userFormFirstname').value,
        lastname: document.getElementById('userFormLastname').value,
        is_admin: document.getElementById('userFormIsAdmin').checked,
    };
}

function saveNewUser() {
    document.getElementById('userFormSaveBtn').disabled = true;

    Ytan.post('/users', readUserForm()).then(() => {
        closeUserForm();
        loadUserList();
    }).catch(err => {
        document.getElementById('userFormSaveBtn').disabled = false;
        showToast('Save failed: ' + err.message, 'error');
    });
}

function saveEditedUser(id) {
    document.getElementById('userFormSaveBtn').disabled = true;

    Ytan.put('/users/' + id, readUserForm()).then(() => {
        closeUserForm();
        loadUserList();
    }).catch(err => {
        document.getElementById('userFormSaveBtn').disabled = false;
        showToast('Save failed: ' + err.message, 'error');
    });
}

async function deleteUserRow(id) {
    if (!(await showConfirmDialog('Do you really want to delete this user?', { type: 'danger', confirmLabel: 'Delete' }))) {
        return;
    }

    Ytan.del('/users/' + id).then(() => {
        loadUserList();
    }).catch(err => {
        showToast('Delete failed: ' + err.message, 'error');
    });
}

/**
 * Admin override - sets a user's password directly, without needing (or
 * sending) a reset email. Useful e.g. when a user is locked out and can't
 * receive mail themselves.
 */
function showSetPasswordForm(id, username) {
    document.getElementById('useradminmenu-form').innerHTML =
        '<div class="admin-user-form">' +
        '<h3>Set password for ' + escapeHTML(username) + '</h3>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="setPasswordNew">New password: </label></div>' +
            '<div class="adminFormField"><input id="setPasswordNew" type="password" autocomplete="new-password"></div>' +
        '</div>' +
        '<div id="setPasswordMessage"></div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel">&nbsp;</div>' +
            '<div class="adminFormField">' +
                '<button id="setPasswordSaveBtn" class="button" type="button" onclick="submitSetPassword(' + id + ');">Save</button>&nbsp;' +
                '<button class="button" type="button" onclick="closeUserForm();">Cancel</button>' +
            '</div>' +
        '</div>' +
        '</div>'
    ;
}

function submitSetPassword(id) {
    var password = document.getElementById('setPasswordNew').value;
    var message = document.getElementById('setPasswordMessage');
    document.getElementById('setPasswordSaveBtn').disabled = true;

    Ytan.put('/users/' + id + '/password', { password: password }).then(() => {
        closeUserForm();
        loadUserList();
    }).catch(err => {
        document.getElementById('setPasswordSaveBtn').disabled = false;
        message.style.color = '#b3261e';
        message.textContent = err.message;
    });
}

async function sendResetEmailRow(id) {
    if (!(await showConfirmDialog('Send a password reset email to this user?', { type: 'default', confirmLabel: 'Send' }))) {
        return;
    }

    Ytan.post('/users/' + id + '/send-reset').then(() => {
        showToast('Password reset email sent.', 'success');
    }).catch(err => {
        showToast('Sending the reset email failed: ' + err.message, 'error');
    });
}

/**
 * "Google search requires login" toggle (Site Settings drawer screen).
 * Persists the site-wide flag via PUT /api/v1/settings/... (admin-only,
 * enforced server-side too) and updates this session's own
 * googleSearchAllowed (map-core.js) immediately, so the admin sees the
 * effect without reloading.
 */
function toggleGoogleSearchRequiresLogin(checkbox) {
    const enabled = checkbox.checked;

    Ytan.put('/settings/google-search-requires-login', { enabled: enabled }).then(() => {
        window.YTAN_GOOGLE_SEARCH_REQUIRES_LOGIN = enabled;
        googleSearchAllowed = !enabled || user.id !== null;
        showToast('Saved.', 'success');
    }).catch(err => {
        checkbox.checked = !enabled;
        showToast('Save failed: ' + err.message, 'error');
    });
}
