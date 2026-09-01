/**
 * Login/logout UI and the auth token lifecycle (JWT via Ytan.setToken()).
 */

function userInitials() {
    if (user.firstname && user.lastname) {
        return (user.firstname[0] + user.lastname[0]).toUpperCase();
    }
    if (user.username) {
        return user.username.slice(0, 2).toUpperCase();
    }
    return '?';
}

function showUserWindow() {
    if (user.id === null) {
        document.getElementById('userWindow').innerHTML =
            '<div class="nav-field">' +
                '<label for="userLoginUsername">Username or email</label>' +
                '<input id="userLoginUsername" type="text" placeholder="username" autocomplete="username" title="Enter your username or email used on registration." onkeypress="focusOnEnter(event, \'userLoginPassword\');">' +
            '</div>' +
            '<div class="nav-field">' +
                '<label for="userLoginPassword">Password</label>' +
                '<input id="userLoginPassword" type="password" placeholder="Enter your password." autocomplete="current-password" onkeypress="clickOnEnter(event, \'userLoginBtn\');">' +
            '</div>' +
            '<button id="userLoginBtn" class="nav-btn-primary" type="button" onClick="loginUser()"><i class="material-icons-round">login</i>&nbsp;Log in</button>' +
            '<span class="nav-link-small" onclick="goToForgotPassword();">Forgot password?</span>'
        ;
    } else {
        var pendingEmailNotice = user.pending_email
            ? '<div class="nav-form-message" style="color: var(--color-warning);">Email change pending confirmation for ' + user.pending_email + '. <span class="nav-link-small" onclick="cancelPendingEmailChange();">Cancel</span></div>'
            : '';

        document.getElementById('userWindow').innerHTML =
            '<div class="nav-account-head">' +
                '<div class="nav-avatar">' + userInitials() + '</div>' +
                '<div>' +
                    '<div class="nav-account-name">' + (user.firstname && user.lastname ? user.firstname + ' ' + user.lastname : user.username) + '</div>' +
                    '<div class="nav-account-email">' + user.email + '</div>' +
                '</div>' +
            '</div>' +
            pendingEmailNotice +
            '<button id="userEditProfileBtn" class="nav-btn-secondary" type="button" onClick="showEditProfileForm();"><i class="material-icons-round">edit</i>&nbsp;Edit profile</button>' +
            '<button id="userChangePasswordBtn" class="nav-btn-secondary" type="button" onClick="showChangePasswordForm();"><i class="material-icons-round">lock</i>&nbsp;Change password</button>' +
            '<button id="userLogoutBtn" class="nav-btn-secondary nav-btn-danger" type="button" onClick="logoutUser()"><i class="material-icons-round">logout</i>&nbsp;Log out</button>' +
            '<div id="userWindowSub"></div>'
        ;
    }

    navMenuGoTo('preferences');
}

function clickOnEnter(event, elementId) {
    if (event.keyCode === 13) {
        document.getElementById(elementId).click();
    }
}

function focusOnEnter(event, elementId) {
    if (event.keyCode === 13) {
        document.getElementById(elementId).focus();
    }
}

function closeUserWindow() {
    navMenuBack();
}

/**
 * "Forgot password?" leaves the SPA for the standalone /forgot-password
 * page (a real navigation, not an in-page panel) since it's a
 * pre-authentication flow that ends in the emailed /set-password link
 * also being a standalone page - keeping both steps consistent.
 */
function goToForgotPassword() {
    window.location.href = window.YTAN_API_BASE.replace(/\/api\/v1$/, '') + '/forgot-password';
}

/**
 * Self-service password change for an already logged-in user - separate
 * from the "Forgot password?" flow, which requires no active session but
 * does require access to the account's email inbox.
 */
function showChangePasswordForm() {
    document.getElementById('userWindowSub').innerHTML =
        '<div class="nav-divider"></div>' +
        '<div class="nav-field"><label for="userChangePasswordCurrent">Current password</label><input id="userChangePasswordCurrent" type="password" autocomplete="current-password"></div>' +
        '<div class="nav-field"><label for="userChangePasswordNew">New password</label><input id="userChangePasswordNew" type="password" autocomplete="new-password"></div>' +
        '<div class="nav-field"><label for="userChangePasswordConfirm">Confirm new password</label><input id="userChangePasswordConfirm" type="password" autocomplete="new-password"></div>' +
        '<div class="nav-form-message" id="userChangePasswordMessage"></div>' +
        '<button id="userChangePasswordSaveBtn" class="nav-btn-primary" type="button" onClick="submitChangePassword();"><i class="material-icons-round">check</i>&nbsp;Save password</button>' +
        '<span class="nav-link-small" onclick="document.getElementById(\'userWindowSub\').innerHTML=\'\';">Cancel</span>'
    ;
}

function submitChangePassword() {
    var current = document.getElementById('userChangePasswordCurrent').value;
    var newPassword = document.getElementById('userChangePasswordNew').value;
    var confirmPassword = document.getElementById('userChangePasswordConfirm').value;
    var message = document.getElementById('userChangePasswordMessage');

    if (newPassword !== confirmPassword) {
        message.style.color = 'var(--color-danger)';
        message.textContent = 'The two passwords do not match.';
        return;
    }

    document.getElementById('userChangePasswordSaveBtn').disabled = true;

    Ytan.put('/auth/password', { current_password: current, new_password: newPassword }).then(() => {
        message.style.color = 'var(--color-success)';
        message.textContent = 'Password changed successfully.';
        document.getElementById('userChangePasswordCurrent').value = '';
        document.getElementById('userChangePasswordNew').value = '';
        document.getElementById('userChangePasswordConfirm').value = '';
        document.getElementById('userChangePasswordSaveBtn').disabled = false;
    }).catch(err => {
        message.style.color = 'var(--color-danger)';
        message.textContent = err.message;
        document.getElementById('userChangePasswordSaveBtn').disabled = false;
    });
}

/**
 * Self-service edit of firstname/lastname/email - requires the current
 * password, same as showChangePasswordForm(), since email doubles as the
 * account's recovery address. firstname/lastname take effect immediately;
 * a changed email only takes effect once the confirmation link mailed to
 * the new address is clicked (see AuthService::updateProfile()).
 */
function showEditProfileForm() {
    document.getElementById('userWindowSub').innerHTML =
        '<div class="nav-divider"></div>' +
        '<div class="nav-field"><label for="userEditProfileFirstname">First name</label><input id="userEditProfileFirstname" type="text" value="' + user.firstname + '"></div>' +
        '<div class="nav-field"><label for="userEditProfileLastname">Last name</label><input id="userEditProfileLastname" type="text" value="' + user.lastname + '"></div>' +
        '<div class="nav-field"><label for="userEditProfileEmail">Email</label><input id="userEditProfileEmail" type="email" value="' + user.email + '"></div>' +
        '<div class="nav-field"><label for="userEditProfileCurrentPassword">Current password</label><input id="userEditProfileCurrentPassword" type="password" autocomplete="current-password"></div>' +
        '<div class="nav-form-message" id="userEditProfileMessage"></div>' +
        '<button id="userEditProfileSaveBtn" class="nav-btn-primary" type="button" onClick="submitEditProfile();"><i class="material-icons-round">check</i>&nbsp;Save profile</button>' +
        '<span class="nav-link-small" onclick="document.getElementById(\'userWindowSub\').innerHTML=\'\';">Cancel</span>'
    ;
}

function submitEditProfile() {
    var firstname = document.getElementById('userEditProfileFirstname').value.trim();
    var lastname = document.getElementById('userEditProfileLastname').value.trim();
    var email = document.getElementById('userEditProfileEmail').value.trim();
    var currentPassword = document.getElementById('userEditProfileCurrentPassword').value;
    var message = document.getElementById('userEditProfileMessage');

    document.getElementById('userEditProfileSaveBtn').disabled = true;

    Ytan.put('/auth/profile', {
        firstname: firstname,
        lastname: lastname,
        email: email,
        current_password: currentPassword
    }).then(answer => {
        Ytan.setToken(answer.token);

        user.firstname = answer.user.firstname;
        user.lastname = answer.user.lastname;
        user.email = answer.user.email;
        user.pending_email = answer.email_change_pending ? answer.pending_email : null;

        sessionStorage.setItem('user', JSON.stringify(user));
        updatePreferencesRowLabel();

        showToast(
            answer.email_change_pending
                ? 'Confirmation email sent to ' + answer.pending_email + '.'
                : 'Profile updated.',
            'success'
        );

        showUserWindow();
    }).catch(err => {
        message.style.color = 'var(--color-danger)';
        message.textContent = err.message;
        document.getElementById('userEditProfileSaveBtn').disabled = false;
    });
}

/**
 * Cancels a pending email-change request from the persistent notice shown
 * in showUserWindow() - does not touch the current (unconfirmed) address.
 */
function cancelPendingEmailChange() {
    Ytan.del('/auth/email-change').then(() => {
        user.pending_email = null;
        sessionStorage.setItem('user', JSON.stringify(user));
        showToast('Pending email change canceled.', 'info');
        showUserWindow();
    }).catch(err => {
        showToast(err.message, 'error');
    });
}

/**
 * Shows/hides the sidemenu's "Site Settings" entry based on the current
 * user's is_admin flag. Called after login, logout, and the boot-time
 * /auth/me revalidation.
 */
function updateAdminMenuVisibility() {
    document.getElementById('userAdminMenuBtn').style.display = user.is_admin ? '' : 'none';
}

/**
 * Reflects the current sign-in state as the "Preferences" root menu row's
 * subtitle (e.g. "Signed in as cst" / "Not signed in"). Called from the
 * same places as updateAdminMenuVisibility().
 */
function updatePreferencesRowLabel() {
    document.getElementById('preferencesRowSub').textContent = user.id !== null ? ('Signed in as ' + user.username) : 'Not signed in';
}

/**
 * JWTs are stateless - there is no server-side session to invalidate, so
 * logging out just discards the locally stored token and resets the UI to
 * the public (unauthenticated) view.
 */
function logoutUser() {
    Ytan.setToken(null);
    user = initUser();
    sessionStorage.removeItem('user');
    closeUserWindow();
    cancelEditRoute();
    closePoiEditWindow();
    document.getElementById('routeButton').classList.remove('active');
    updatePreferencesRowLabel();
    updateAdminMenuVisibility();
    deleteRoutes();
    areas = [];
    areaPolygons = [];
    getPublicPois();
    getPublicRoutes();
    getPublicAreas();
    disablePoiButton();
    infoWindow.close();
}

function loginUser() {
    document.getElementById('userLoginUsername').disabled = true;
    document.getElementById('userLoginPassword').disabled = true;
    document.getElementById('userLoginBtn').disabled = true;

    var loginData = {
        username: document.getElementById('userLoginUsername').value,
        password: document.getElementById('userLoginPassword').value
    }

    Ytan.post('/auth/login', loginData).then(answer => {
        log("loginUser()", LOG_INFO, answer);

        document.getElementById('userLoginUsername').disabled = false;
        document.getElementById('userLoginPassword').disabled = false;
        document.getElementById('userLoginBtn').disabled = false;

        Ytan.setToken(answer.token);

        user.id         = answer.user.id;
        user.username   = answer.user.username;
        user.email      = answer.user.email;
        user.firstname  = answer.user.firstname;
        user.lastname   = answer.user.lastname;
        user.is_admin   = !!answer.user.is_admin;

        sessionStorage.setItem('user', JSON.stringify(user));
        closeUserWindow();
        getPoisByUserId(user.id);
        getRoutesByUserId(user.id);
        updatePreferencesRowLabel();
        updateAdminMenuVisibility();
        enablePoiButton();
    }).catch(err => {
        document.getElementById('userLoginUsername').disabled = false;
        document.getElementById('userLoginPassword').disabled = false;
        document.getElementById('userLoginBtn').disabled = false;
        showToast('Login failed: ' + err.message, 'error');
    });
}
