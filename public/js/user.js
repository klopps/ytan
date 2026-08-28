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
                '<button id="userLogoutBtn" class="button" onClick="logoutUser()">Logout</button>&nbsp;' +
                '<button id="userCloseBtn" class="button" onClick="closeUserWindow()">Close</button>' +
            '</div>'
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
    hideRoutes();
    routes = [];
    routePaths = [];
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
        log("loginUser()", LOG_DEFAULT, answer);

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
        enablePoiButton();

        setSessionTimeout(SESSION_TIMEOUT_SECONDS);
    }).catch(err => {
        document.getElementById('userLoginUsername').disabled = false;
        document.getElementById('userLoginPassword').disabled = false;
        document.getElementById('userLoginBtn').disabled = false;
        document.getElementById('userCancelBtn').disabled = false;
        alert('Login failed: ' + err.message);
    });
}
