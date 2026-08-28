/**
 * Cookie handling, persisted map settings, session timeout and logging.
 */

function setCookie(cname, cvalue, exdays) {
    var d = new Date();
    d.setTime(d.getTime() + (exdays * 24 * 60 * 60 * 1000));
    var expires = "expires="+d.toUTCString();
    document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
}

function getCookie(cname) {
    var name = cname + "=";
    var ca = document.cookie.split(';');
    for(var i = 0; i < ca.length; i++) {
      var c = ca[i];
      while (c.charAt(0) == ' ') {
        c = c.substring(1);
      }
      if (c.indexOf(name) == 0) {
        return c.substring(name.length, c.length);
      }
    }
    return "";
}

function deleteCookie(cname) {
    document.cookie = cname + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
}


/**
 * Speichert die derzeitigen Einstellungen in einem Cookie
 */
function saveSettings() {

    settings["detail0"] = document.getElementById('detail0').checked;
    settings["detail1"] = document.getElementById('detail1').checked;
    settings['detail2'] = document.getElementById('detail2').checked;
    settings['detail3'] = document.getElementById('detail3').checked;
    settings['detail4'] = document.getElementById('detail4').checked;
    settings['detail5'] = document.getElementById('detail5').checked;
    settings['detail6'] = document.getElementById('detail6').checked;
    settings['detail7'] = document.getElementById('detail7').checked;
    settings['detail8'] = document.getElementById('detail8').checked;
    settings['detail9'] = document.getElementById('detail9').checked;
    settings['detail10'] = document.getElementById('detail10').checked;
    settings['detail11'] = document.getElementById('detail11').checked;
    settings['detail12'] = document.getElementById('detail12').checked;
    settings['detail13'] = document.getElementById('detail13').checked;
    settings['detail14'] = document.getElementById('detail14').checked;
    settings['detail15'] = document.getElementById('detail15').checked;
    settings['detailwsi'] = document.getElementById('detailwsi').checked;
    settings['detailroutes'] = document.getElementById('detailroutes').checked;
    settings['detailareas'] = document.getElementById('detailareas').checked;

    settings['maptype1'] = document.getElementById('maptype1').checked;
    settings['maptype2'] = document.getElementById('maptype2').checked;
    settings['maptype3'] = document.getElementById('maptype3').checked;

    if (settings['maptype1']) {
        settings['maptype'] = 'hybrid';
    } else if (settings['maptype2']) {
        settings['maptype'] = 'terrain';
    } else {
        settings['maptype'] = 'satellite';
    }

    settings['unit1'] = document.getElementById('unit1').checked;
    settings['unit2'] = document.getElementById('unit2').checked;
    if (settings['unit1']) {
        settings['unit'] = METRIC;
    } else {
        settings['unit'] = NAUTICAL;
    }

    settings['zoom'] = map.getZoom();
    settings['center'] = map.getCenter();

    setCookie('settings', JSON.stringify(settings), 365);
}

/**
 * Lädt die Einstellungen aus einem Cookie
 */
function loadSettings() {
    var settingsString = getCookie('settings');

    if (settingsString != '') {
        settings = Object.assign({}, settings, JSON.parse(settingsString));

        document.getElementById('detail0').checked = settings['detail0'];
        document.getElementById('detail1').checked = settings['detail1'];
        document.getElementById('detail2').checked = settings['detail2'];
        document.getElementById('detail3').checked = settings['detail3'];
        document.getElementById('detail4').checked = settings['detail4'];
        document.getElementById('detail5').checked = settings['detail5'];
        document.getElementById('detail6').checked = settings['detail6'];
        document.getElementById('detail7').checked = settings['detail7'];
        document.getElementById('detail8').checked = settings['detail8'];
        document.getElementById('detail9').checked = settings['detail9'];
        document.getElementById('detail10').checked = settings['detail10'];
        document.getElementById('detail11').checked = settings['detail11'];
        document.getElementById('detail12').checked = settings['detail12'];
        document.getElementById('detail13').checked = settings['detail13'];
        document.getElementById('detail14').checked = settings['detail14'];
        document.getElementById('detail15').checked = settings['detail15'];
        document.getElementById('detailwsi').checked = settings['detailwsi'];
        document.getElementById('detailroutes').checked = settings['detailroutes'];
        document.getElementById('detailareas').checked = settings['detailareas'];

        document.getElementById('maptype1').checked = settings['maptype1'];
        document.getElementById('maptype2').checked = settings['maptype2'];
        document.getElementById('maptype3').checked = settings['maptype3'];

        document.getElementById('unit1').checked = settings['unit1'];
        document.getElementById('unit2').checked = settings['unit2'];
    }
}

/**
 * Starts (or restarts) the client-side inactivity timer that logs the user
 * out once the JWT is expected to have expired. Unlike the legacy PHP
 * session model, there is no server-managed session cookie to read the
 * timeout from any more - the timeout is always driven by the JWT's own
 * lifetime (JWT_TTL_SECONDS on the server, mirrored here as SESSION_TIMEOUT_SECONDS).
 */
function setSessionTimeout(timeoutInSeconds) {
    var effectiveTimeout = (typeof timeoutInSeconds === 'number' && timeoutInSeconds > 0)
        ? timeoutInSeconds
        : SESSION_TIMEOUT_SECONDS;

    if (window.sessionTimeoutHandle) {
        clearTimeout(window.sessionTimeoutHandle);
    }

    window.sessionTimeoutHandle = window.setTimeout(
        handleSessionTimeout,
        (effectiveTimeout - 5) * 1000
    );
}

function handleSessionTimeout() {
    if (Ytan.isLoggedIn()) {
        logoutUser();
        alert('You are logged out.');
    }
}


/**
 * Schreibt Logdaten in die Console, abhängig vom gesetzten Debug-Level
 *
 * @param {string} msg Log-Nachricht
 * @param {int} level Log-Level
 * @param {object} obj ein Object (optional)
 */
function log(msg, level, obj) {
    if (level <= logLevel) {
        console.log(msg);
        if ((obj !== null) && (typeof obj !== "undefined")) {
            console.table(obj);
        }
    }
}


function copyTextToClipboard(text) {
    var textArea = document.createElement("textarea");

    textArea.style.position = 'fixed';
    textArea.style.top = 0;
    textArea.style.left = 0;
    textArea.style.width = '2em';
    textArea.style.height = '2em';
    textArea.style.padding = 0;
    textArea.style.border = 'none';
    textArea.style.outline = 'none';
    textArea.style.boxShadow = 'none';
    textArea.style.background = 'transparent';

    textArea.value = text;

    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
      var successful = document.execCommand('copy');
      var msg = successful ? 'successful' : 'unsuccessful';
      console.log('Copying text command was ' + msg);
    } catch (err) {
      console.log('Oops, unable to copy');
    }

    document.body.removeChild(textArea);
}
