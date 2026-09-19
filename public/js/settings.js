/**
 * Cookie handling, persisted map settings, session timeout and logging.
 */

// Moved here from map-core.js so applyStoredTheme()/loadSettings() work on
// map-less pages (templates/admin-users.php) that load this file but not
// map-core.js - map-core.js still uses them too, it just loads later.
const THEME_LIGHT = 'light';
const THEME_DARK = 'dark';

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
    settings['detail16'] = document.getElementById('detail16').checked;
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

    settings['windunit1'] = document.getElementById('windunit1').checked;
    settings['windunit2'] = document.getElementById('windunit2').checked;
    settings['windunit3'] = document.getElementById('windunit3').checked;
    settings['windunit4'] = document.getElementById('windunit4').checked;
    if (settings['windunit1']) {
        settings['windUnit'] = WIND_UNIT_BFT;
    } else if (settings['windunit2']) {
        settings['windUnit'] = WIND_UNIT_MS;
    } else if (settings['windunit4']) {
        settings['windUnit'] = WIND_UNIT_KN;
    } else {
        settings['windUnit'] = WIND_UNIT_KMH;
    }

    settings['coordformat1'] = document.getElementById('coordformat1').checked;
    settings['coordformat2'] = document.getElementById('coordformat2').checked;
    settings['coordformat3'] = document.getElementById('coordformat3').checked;
    if (settings['coordformat2']) {
        settings['coordinateFormat'] = COORDINATE_FORMAT_MM;
    } else if (settings['coordformat3']) {
        settings['coordinateFormat'] = COORDINATE_FORMAT_DMS;
    } else {
        settings['coordinateFormat'] = COORDINATE_FORMAT_DD;
    }

    settings['routesmoothing1'] = document.getElementById('routesmoothing1').checked;
    settings['routesmoothing2'] = document.getElementById('routesmoothing2').checked;
    settings['smoothRoutes'] = settings['routesmoothing1'] ? true : false;

    settings['theme'] = [THEME_LIGHT, THEME_DARK].includes(settings['theme']) ? settings['theme'] : THEME_LIGHT;

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
        document.getElementById('detail16').checked = settings['detail16'];
        document.getElementById('detailwsi').checked = settings['detailwsi'];
        document.getElementById('detailroutes').checked = settings['detailroutes'];
        document.getElementById('detailareas').checked = settings['detailareas'];

        document.getElementById('maptype1').checked = settings['maptype1'];
        document.getElementById('maptype2').checked = settings['maptype2'];
        document.getElementById('maptype3').checked = settings['maptype3'];

        document.getElementById('unit1').checked = settings['unit1'];
        document.getElementById('unit2').checked = settings['unit2'];
        updateUnitExample();

        document.getElementById('windunit1').checked = settings['windunit1'];
        document.getElementById('windunit2').checked = settings['windunit2'];
        document.getElementById('windunit3').checked = settings['windunit3'];
        document.getElementById('windunit4').checked = settings['windunit4'];
        // A settings cookie saved before this preference existed has none of
        // the 4 fields above - fall back to the km/h default rather than
        // leaving the whole segmented control looking unselected.
        if (!settings['windunit1'] && !settings['windunit2'] && !settings['windunit3'] && !settings['windunit4']) {
            document.getElementById('windunit3').checked = true;
        }

        document.getElementById('coordformat1').checked = settings['coordformat1'];
        document.getElementById('coordformat2').checked = settings['coordformat2'];
        document.getElementById('coordformat3').checked = settings['coordformat3'];
        // Same fallback pattern as windunit1-4 above - a cookie saved before
        // this preference existed has none of the 3 fields, default to DD.
        if (!settings['coordformat1'] && !settings['coordformat2'] && !settings['coordformat3']) {
            document.getElementById('coordformat1').checked = true;
        }
        updateCoordinateFormatExample();

        document.getElementById('routesmoothing1').checked = settings['routesmoothing1'];
        document.getElementById('routesmoothing2').checked = settings['routesmoothing2'];
        // Alter Cookie ohne dieses Feld - Default geglättet (wie routesmoothing1s
        // eigener checked-Default im Markup), gleiches Fallback-Muster wie windunit3.
        if (!settings['routesmoothing1'] && !settings['routesmoothing2']) {
            document.getElementById('routesmoothing1').checked = true;
        }

        if (settings['theme'] === THEME_DARK) {
            document.documentElement.dataset.theme = THEME_DARK;
        } else {
            delete document.documentElement.dataset.theme;
        }
    }
}

/**
 * Applies a previously saved Light/Dark preference as early as possible on
 * boot (before Google Maps/initMap() have even started loading), so a
 * returning dark-mode user doesn't see a flash of the light theme while the
 * map script loads. loadSettings() (called later, from initMap()) re-applies
 * the same attribute once the full settings object is authoritative - this
 * is just a fast, defensive early read of the same cookie.
 */
function applyStoredTheme() {
    var settingsString = getCookie('settings');
    if (settingsString == '') {
        return;
    }
    try {
        var stored = JSON.parse(settingsString);
        if (stored.theme === THEME_DARK) {
            document.documentElement.dataset.theme = THEME_DARK;
        }
    } catch (err) {
        log('applyStoredTheme() failed', LOG_WARN, err);
    }
}

const LOG_CONSOLE_METHOD = {
    [LOG_ERROR]: 'error',
    [LOG_WARN]: 'warn',
    [LOG_INFO]: 'info',
    [LOG_DEBUG]: 'debug',
};

/**
 * Schreibt Logdaten in die Console, abhängig vom gesetzten Debug-Level.
 * Die Console-Methode richtet sich nach dem Schweregrad (LOG_ERROR/WARN/INFO/DEBUG),
 * damit Fehler in den DevTools rot erscheinen, filterbar sind und - falls obj ein
 * Error-Objekt ist - mit vollem Stacktrace statt nur mit obj.message geloggt werden.
 *
 * @param {string} msg Log-Nachricht
 * @param {int} level Log-Level (LOG_ERROR/LOG_WARN/LOG_INFO/LOG_DEBUG)
 * @param {object} obj ein Object oder Error (optional)
 */
function log(msg, level, obj) {
    if (level > logLevel) {
        return;
    }
    var method = LOG_CONSOLE_METHOD[level] || 'log';
    if (obj instanceof Error) {
        console[method](msg, obj);
        return;
    }
    console[method](msg);
    if ((obj !== null) && (typeof obj !== "undefined")) {
        console.table(obj);
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
      log('Copying text command was ' + msg, LOG_DEBUG);
    } catch (err) {
      log('Oops, unable to copy', LOG_ERROR, err);
    }

    document.body.removeChild(textArea);
}
