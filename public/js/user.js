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
                '<label for="userLoginUsername">' + t('user.login.username_label') + '</label>' +
                '<input id="userLoginUsername" type="text" placeholder="' + t('user.login.username_placeholder') + '" autocomplete="username" title="' + t('user.login.username_title') + '" onkeypress="focusOnEnter(event, \'userLoginPassword\');">' +
            '</div>' +
            '<div class="nav-field">' +
                '<label for="userLoginPassword">' + t('user.login.password_label') + '</label>' +
                '<div class="password-input-wrapper">' +
                    '<input id="userLoginPassword" type="password" placeholder="' + t('user.login.password_placeholder') + '" autocomplete="current-password" onkeypress="clickOnEnter(event, \'userLoginBtn\');">' +
                    passwordToggleButtonHtml('userLoginPassword') +
                '</div>' +
            '</div>' +
            '<button id="userLoginBtn" class="nav-btn-primary" type="button" onClick="loginUser()"><i class="material-icons-round">login</i>&nbsp;' + t('user.login.button') + '</button>' +
            '<span class="nav-link-small" onclick="goToForgotPassword();">' + t('user.login.forgot_password') + '</span>'
        ;
    } else {
        var pendingEmailNotice = user.pending_email
            ? '<div class="nav-form-message" style="color: var(--color-warning);">' + t('user.pending_email_notice', { email: user.pending_email }) + ' <span class="nav-link-small" onclick="cancelPendingEmailChange();">' + t('common.cancel') + '</span></div>'
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
            '<button id="userEditProfileBtn" class="nav-btn-secondary" type="button" onClick="showEditProfileForm();"><i class="material-icons-round">edit</i>&nbsp;' + t('user.edit_profile_button') + '</button>' +
            '<button id="userChangePasswordBtn" class="nav-btn-secondary" type="button" onClick="showChangePasswordForm();"><i class="material-icons-round">lock</i>&nbsp;' + t('user.change_password_button') + '</button>' +
            '<button id="userLogoutBtn" class="nav-btn-secondary nav-btn-danger" type="button" onClick="logoutUser()"><i class="material-icons-round">logout</i>&nbsp;' + t('user.logout_button') + '</button>' +
            '<div id="userWindowSub"></div>'
        ;
    }

    navMenuGoTo('profile');
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
        '<div class="nav-field"><label for="userChangePasswordCurrent">' + t('user.current_password_label') + '</label><div class="password-input-wrapper"><input id="userChangePasswordCurrent" type="password" autocomplete="current-password">' + passwordToggleButtonHtml('userChangePasswordCurrent') + '</div></div>' +
        '<div class="nav-field"><label for="userChangePasswordNew">' + t('user.new_password_label') + '</label><div class="password-input-wrapper"><input id="userChangePasswordNew" type="password" autocomplete="new-password">' + passwordToggleButtonHtml('userChangePasswordNew') + '</div></div>' +
        '<div class="nav-field"><label for="userChangePasswordConfirm">' + t('user.confirm_new_password_label') + '</label><div class="password-input-wrapper"><input id="userChangePasswordConfirm" type="password" autocomplete="new-password">' + passwordToggleButtonHtml('userChangePasswordConfirm') + '</div></div>' +
        '<div class="nav-form-message" id="userChangePasswordMessage"></div>' +
        '<button id="userChangePasswordSaveBtn" class="nav-btn-primary" type="button" onClick="submitChangePassword();"><i class="material-icons-round">check</i>&nbsp;' + t('user.save_password_button') + '</button>' +
        '<span class="nav-link-small" onclick="document.getElementById(\'userWindowSub\').innerHTML=\'\';">' + t('common.cancel') + '</span>'
    ;
}

function submitChangePassword() {
    var current = document.getElementById('userChangePasswordCurrent').value;
    var newPassword = document.getElementById('userChangePasswordNew').value;
    var confirmPassword = document.getElementById('userChangePasswordConfirm').value;
    var message = document.getElementById('userChangePasswordMessage');

    if (newPassword !== confirmPassword) {
        message.style.color = 'var(--color-danger)';
        message.textContent = t('common.password_mismatch');
        return;
    }

    document.getElementById('userChangePasswordSaveBtn').disabled = true;

    Ytan.put('/auth/password', { current_password: current, new_password: newPassword }).then(() => {
        message.style.color = 'var(--color-success)';
        message.textContent = t('user.password_changed_success');
        document.getElementById('userChangePasswordCurrent').value = '';
        document.getElementById('userChangePasswordNew').value = '';
        document.getElementById('userChangePasswordConfirm').value = '';
        document.getElementById('userChangePasswordSaveBtn').disabled = false;
    }).catch(err => {
        message.style.color = 'var(--color-danger)';
        message.textContent = apiErrorMessage(err);
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
        '<div class="nav-field"><label for="userEditProfileFirstname">' + t('user.firstname_label') + '</label><input id="userEditProfileFirstname" type="text" value="' + user.firstname + '"></div>' +
        '<div class="nav-field"><label for="userEditProfileLastname">' + t('user.lastname_label') + '</label><input id="userEditProfileLastname" type="text" value="' + user.lastname + '"></div>' +
        '<div class="nav-field"><label for="userEditProfileEmail">' + t('user.email_label') + '</label><input id="userEditProfileEmail" type="email" value="' + user.email + '"></div>' +
        '<div class="nav-field"><label for="userEditProfileCurrentPassword">' + t('user.current_password_label') + '</label><div class="password-input-wrapper"><input id="userEditProfileCurrentPassword" type="password" autocomplete="current-password">' + passwordToggleButtonHtml('userEditProfileCurrentPassword') + '</div></div>' +
        '<div class="nav-form-message" id="userEditProfileMessage"></div>' +
        '<button id="userEditProfileSaveBtn" class="nav-btn-primary" type="button" onClick="submitEditProfile();"><i class="material-icons-round">check</i>&nbsp;' + t('user.save_profile_button') + '</button>' +
        '<span class="nav-link-small" onclick="document.getElementById(\'userWindowSub\').innerHTML=\'\';">' + t('common.cancel') + '</span>'
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
        updateProfileRowLabel();

        showToast(
            answer.email_change_pending
                ? t('user.confirmation_email_sent', { email: answer.pending_email })
                : t('user.profile_updated'),
            'success'
        );

        showUserWindow();
    }).catch(err => {
        message.style.color = 'var(--color-danger)';
        message.textContent = apiErrorMessage(err);
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
        showToast(t('user.pending_email_change_canceled'), 'info');
        showUserWindow();
    }).catch(err => {
        showToast(apiErrorMessage(err), 'error');
    });
}

/**
 * Shows/hides the sidemenu's "Administration" entry (links to /admin) based
 * on the current user's is_admin flag. Called after login, logout, and the
 * boot-time /auth/me revalidation.
 */
function updateAdminMenuVisibility() {
    document.getElementById('adminMenuBtn').style.display = user.is_admin ? '' : 'none';
}

/**
 * Shows/hides the sidemenu's "Pending changes" entry based on sign-in state
 * AND on offline-sync.js's pendingChangesCache actually holding something -
 * showing an always-visible row for an empty queue was itself the
 * complaint (todo.md), on top of the original signed-out case: a
 * signed-out user can't create offline changes in the first place
 * (savePoi()/saveRoute()/saveArea() all require a logged-in user before
 * queuing anything in offline-sync.js). Hidden by default in the markup,
 * same fail-closed pattern as updateAdminMenuVisibility(). Called from the
 * same places as that function, plus offline-sync.js's
 * refreshPendingChanges() (the queue can change without a login/logout in
 * between).
 */
function updatePendingChangesMenuVisibility() {
    document.getElementById('pendingChangesMenuRow').style.display = (user.id !== null && pendingChangesCache.length > 0) ? '' : 'none';
}

/**
 * Reflects the current sign-in state as the "Profile" root menu row's
 * subtitle (e.g. "Signed in as cst" / "Not signed in"). Called from the
 * same places as updateAdminMenuVisibility().
 */
function updateProfileRowLabel() {
    document.getElementById('profileRowSub').textContent = user.id !== null ? t('user.signed_in_as', { username: user.username }) : t('app.nav.not_signed_in');
}

/**
 * JWTs are stateless - there is no server-side session to invalidate, so
 * logging out just discards the locally stored token and resets the UI to
 * the public (unauthenticated) view.
 */
function logoutUser() {
    // Defense-in-depth, on top of offline-cache.js's own exact-key
    // matching (which already stops the app's UI from ever reading this
    // user's cached "mine_public" data back once someone else is logged
    // in/out): actively removes it from IndexedDB too, rather than leaving
    // it sitting there indefinitely for anyone with direct device/DevTools
    // access to the same browser profile.
    clearOfflineCacheForUser(user.id);

    Ytan.setToken(null);
    user = initUser();
    sessionStorage.removeItem('user');
    closeUserWindow();
    cancelEditRoute();
    closePoiEditWindow();
    document.getElementById('routeButton').classList.remove('active');
    updateProfileRowLabel();
    updateAdminMenuVisibility();
    updatePendingChangesMenuVisibility();
    updateGoogleSearchAllowed();
    resetSearchState();
    deleteRoutes();
    areas = [];
    areaPolygons = [];
    getPublicPois();
    getPublicRoutes();
    getPublicAreas();
    // Pre-existing gap: unlike POIs/routes/areas above, tours[] was never
    // reset/reloaded here - the previous user's tour list kept showing
    // until a full page reload. Fixed alongside this change since it's the
    // same "don't leak/strand a previous user's data" concern.
    getPublicTours();
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
        user.tour_create  = !!answer.user.tour_create;
        user.tour_publish = !!answer.user.tour_publish;
        user.tour_manage  = !!answer.user.tour_manage;
        user.tour_copy    = !!answer.user.tour_copy;
        user.route_view_recording = !!answer.user.route_view_recording;

        sessionStorage.setItem('user', JSON.stringify(user));
        closeUserWindow();
        getPoisByUserId(user.id);
        getRoutesByUserId(user.id);
        updateProfileRowLabel();
        updateAdminMenuVisibility();
        updatePendingChangesMenuVisibility();
        updateGoogleSearchAllowed();
        enablePoiButton();
    }).catch(err => {
        document.getElementById('userLoginUsername').disabled = false;
        document.getElementById('userLoginPassword').disabled = false;
        document.getElementById('userLoginBtn').disabled = false;
        showToast(t('user.login_failed', { error: apiErrorMessage(err) }), 'error');
    });
}
