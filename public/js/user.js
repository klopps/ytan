/**
 * Login/logout UI and the auth token lifecycle (JWT via Ytan.setToken()).
 */

function showUserWindow() {
    if (document.getElementById('userWindow')) {
        if (document.getElementById('userWindow').style.display == 'block') {
            return;
        }
    }

    if (user.id === null) {
        document.getElementById('userWindow').innerHTML =
            '<form>' +
            '<div class="infoWindowElement">' +
                '<div class="leftCol">' +
                    '<label for="userLoginUsername">Username (or email): </label>' +
                '</div>' +
                '<div class="rightCol">' +
                    '<input id="userLoginUsername" type="text" placeholder="username" autocomplete="username" title="Enter your username or email used on registration." onkeypress="focusOnEnter(event, \'userLoginPassword\');">' +
                '</div>' +
            '</div>' +
            '<div class="infoWindowElement">' +
                '<div class="leftCol">' +
                    '<label for="userLoginPassword">Password: </label>' +
                '</div>' +
                '<div class="rightCol">' +
                    '<input id="userLoginPassword" type="password" placeholder="Enter your password." autocomplete="current-password" onkeypress="clickOnEnter(event, \'userLoginBtn\');">' +
                '</div>' +
            '</div>' +
            '<div class="infoWindowElement">' +
                '<div class="leftCol">' +
                    '&nbsp;' +
                '</div>' +
                '<div class="rightCol">' +
                    '<button id="userLoginBtn" class="button" type="button" onClick="loginUser()">Login</button>&nbsp;' +
                    '<button id="userCancelBtn"class="button" type="button" onClick="closeUserWindow()">Cancel</button>' +
                '</div>' +
            '</div>' +
            '<div class="infoWindowElement">' +
                '<div class="leftCol">' +
                    '&nbsp;' +
                '</div>' +
                '<div class="rightCol">' +
                    '<span class="forgotPasswordLink" onclick="goToForgotPassword();">Forgot password?</span>' +
                '</div>' +
            '</div>' +
            '</form>'
        ;
    } else {
        document.getElementById('userWindow').innerHTML =
            '<div class="infoWindowElement">' +
                '<div class="leftCol">' +
                    'Username:' +
                '</div>' +
                '<div class="rightCol">' +
                    user.username +
                '</div>' +
            '</div>' +
            '<div class="infoWindowElement">' +
                '<div class="leftCol">' +
                    'Email:' +
                '</div>' +
                '<div class="rightCol">' +
                    user.email +
                '</div>' +
            '</div>' +
            '<div class="infoWindowElement">' +
                '<button id="userChangePasswordBtn" class="button" onClick="showChangePasswordForm();">Change password</button>&nbsp;' +
                '<button id="userLogoutBtn" class="button" onClick="logoutUser()">Logout</button>&nbsp;' +
                '<button id="userCloseBtn" class="button" onClick="closeUserWindow()">Close</button>' +
            '</div>' +
            '<div id="userWindowSub"></div>'
        ;
    }

    document.getElementById('userWindow').style.display = 'block';
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
    document.getElementById('userWindow').style.display = 'none';
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
        '<div class="infoWindowElement">' +
            '<div class="leftCol"><label for="userChangePasswordCurrent">Current: </label></div>' +
            '<div class="rightCol"><input id="userChangePasswordCurrent" type="password" autocomplete="current-password"></div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol"><label for="userChangePasswordNew">New: </label></div>' +
            '<div class="rightCol"><input id="userChangePasswordNew" type="password" autocomplete="new-password"></div>' +
        '</div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol"><label for="userChangePasswordConfirm">Confirm: </label></div>' +
            '<div class="rightCol"><input id="userChangePasswordConfirm" type="password" autocomplete="new-password"></div>' +
        '</div>' +
        '<div id="userChangePasswordMessage"></div>' +
        '<div class="infoWindowElement">' +
            '<div class="leftCol">&nbsp;</div>' +
            '<div class="rightCol">' +
                '<button id="userChangePasswordSaveBtn" class="button" type="button" onClick="submitChangePassword();">Save</button>&nbsp;' +
                '<button class="button" type="button" onClick="document.getElementById(\'userWindowSub\').innerHTML=\'\';">Cancel</button>' +
            '</div>' +
        '</div>'
    ;
}

function submitChangePassword() {
    var current = document.getElementById('userChangePasswordCurrent').value;
    var newPassword = document.getElementById('userChangePasswordNew').value;
    var confirmPassword = document.getElementById('userChangePasswordConfirm').value;
    var message = document.getElementById('userChangePasswordMessage');

    if (newPassword !== confirmPassword) {
        message.style.color = '#b3261e';
        message.textContent = 'The two passwords do not match.';
        return;
    }

    document.getElementById('userChangePasswordSaveBtn').disabled = true;

    Ytan.put('/auth/password', { current_password: current, new_password: newPassword }).then(() => {
        message.style.color = '#1a7f37';
        message.textContent = 'Password changed successfully.';
        document.getElementById('userChangePasswordCurrent').value = '';
        document.getElementById('userChangePasswordNew').value = '';
        document.getElementById('userChangePasswordConfirm').value = '';
        document.getElementById('userChangePasswordSaveBtn').disabled = false;
    }).catch(err => {
        message.style.color = '#b3261e';
        message.textContent = err.message;
        document.getElementById('userChangePasswordSaveBtn').disabled = false;
    });
}

/**
 * Shows/hides the sidemenu's "User management" entry based on the current
 * user's is_admin flag. Called after login, logout, and the boot-time
 * /auth/me revalidation.
 */
function updateAdminMenuVisibility() {
    document.getElementById('userAdminMenuBtn').style.display = user.is_admin ? 'inline-block' : 'none';
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
    document.getElementById('userButton').classList.remove('loggedin');
    updateAdminMenuVisibility();
    deleteRoutes();
    areas = [];
    areaPolygons = [];
    getPublicPois();
    getPublicRoutes();
    getPublicAreas();
    disablePoiButton();

    closeUserWindow();
    infoWindow.close();
    closePoiEditWindow();
}

function loginUser() {
    document.getElementById('userLoginUsername').disabled = true;
    document.getElementById('userLoginPassword').disabled = true;
    document.getElementById('userLoginBtn').disabled = true;
    document.getElementById('userCancelBtn').disabled = true;

    var loginData = {
        username: document.getElementById('userLoginUsername').value,
        password: document.getElementById('userLoginPassword').value
    }

    Ytan.post('/auth/login', loginData).then(answer => {
        log("loginUser()", LOG_INFO, answer);

        document.getElementById('userLoginUsername').disabled = false;
        document.getElementById('userLoginPassword').disabled = false;
        document.getElementById('userLoginBtn').disabled = false;
        document.getElementById('userCancelBtn').disabled = false;

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
        document.getElementById('userButton').classList.add('loggedin');
        updateAdminMenuVisibility();
        enablePoiButton();
    }).catch(err => {
        document.getElementById('userLoginUsername').disabled = false;
        document.getElementById('userLoginPassword').disabled = false;
        document.getElementById('userLoginBtn').disabled = false;
        document.getElementById('userCancelBtn').disabled = false;
        alert('Login failed: ' + err.message);
    });
}
