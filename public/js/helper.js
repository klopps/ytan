/** 
 * Helpful functions
 *
 */



/**
 * Resolves a Ytan API error (thrown by api-client.js's request()/
 * postFile()) into a user-facing message, preferring a translated
 * machine-readable error code over the raw English message when the
 * backend attached one - see i18n.js's translateApiError(), which this
 * just adapts to the shape api-client.js throws (`err.data` holds the
 * `{message, code}` error body; a network-level failure has no `.data`).
 *
 * @param {Error} err
 * @returns {string}
 */
function apiErrorMessage(err) {
    return translateApiError(err && err.data ? err.data : { message: err ? err.message : '' });
}

/**
 * Standard "show/hide password" toggle button for a Material-icon context
 * (the main SPA and its standalone pages, e.g. templates/set-password.php)
 * - paired with togglePasswordVisibility() below. The caller must still
 * wrap the actual <input> in a `.password-input-wrapper` div itself (see
 * style.css) so this button can be positioned inside it; this only builds
 * the button markup since callers otherwise differ too much (placeholder,
 * autocomplete, onkeypress, ...) for a single full-field builder to fit.
 * The AdminLTE-based /admin/* pages don't use this - they get Bootstrap's
 * own .input-group + .bi-eye/.bi-eye-slash pattern instead, hand-written
 * per call site since there are only two of them.
 */
function passwordToggleButtonHtml(inputId) {
    return '<button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility(\'' + inputId + '\', this);" aria-label="' + t('common.show_password') + '"><i class="material-icons-round">visibility</i></button>';
}

/**
 * Toggles a <input type="password"> between masked and plain text, and
 * flips the eye icon inside the button that triggered it. Auto-detects the
 * icon system in use so one function works both for the main SPA/standalone
 * pages (.material-icons-round ligature-text glyphs, see
 * passwordToggleButtonHtml() above) and the AdminLTE-based /admin/* pages
 * (Bootstrap Icons' .bi-eye/.bi-eye-slash classes) - both load this file
 * (app.php / admin-shell-footer.php's shared script stack; set-password.php
 * additionally pulls this one file in just for this pair of functions).
 */
function togglePasswordVisibility(inputId, button) {
    var input = document.getElementById(inputId);
    var icon = button.querySelector('i');
    var reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.setAttribute('aria-label', reveal ? t('common.hide_password') : t('common.show_password'));
    if (icon.classList.contains('material-icons-round')) {
        icon.textContent = reveal ? 'visibility_off' : 'visibility';
    } else {
        icon.classList.toggle('bi-eye', !reveal);
        icon.classList.toggle('bi-eye-slash', reveal);
    }
}

/**
 * Escape possible unsecure texts
 *
 * @param {string} unsafe Unescaped text
 * @returns {string} escaped text
 */
function escapeHTML(unsafe) {
    return unsafe
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
 }


const DIACRITIC_FOLD_MAP = {
    'æ': 'ae', 'œ': 'oe', 'ø': 'o', 'ß': 'ss',
    'ł': 'l', 'đ': 'd', 'ð': 'd', 'þ': 'th', 'ı': 'i'
};
// Unicode combining-marks block (accents left over after NFD decomposition)
const COMBINING_MARKS_RE = new RegExp('[\\u0300-\\u036f]', 'g');

/**
 * Case- und diakritik-unabhängige Normalisierung für Textvergleiche (Suche).
 * NFD zerlegt akzentuierte Buchstaben in Basisbuchstabe + Kombinationszeichen
 * (z.B. Umlaute, franz./skandinavische/osteuropäische Akzente), das
 * anschließende Replace entfernt Letzteres. Die Handvoll eigenständiger
 * Sonderbuchstaben ohne NFD-Zerlegung (æ, ø, ß, ł, đ, ð, þ, ı) wird vorher
 * über DIACRITIC_FOLD_MAP abgebildet.
 *
 * @param {string} str Eingabetext
 * @returns {string} kleingeschriebener, diakritik-freier Vergleichstext
 */
function foldSearchText(str) {
    return str
        .toLowerCase()
        .replace(/[æœøłđðþıß]/g, (ch) => DIACRITIC_FOLD_MAP[ch])
        .normalize('NFD')
        .replace(COMBINING_MARKS_RE, '');
}


 function rad(x) {
    return x * Math.PI / 180;
  };
  
function getDistance(p1, p2) {
    //console.log(p1);
    //console.log(p2);
    var R = 6378137; // Earth’s mean radius in meter
    var dLat = rad(p2.lat - p1.lat);
    var dLong = rad(p2.lng - p1.lng);
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(rad(p1.lat)) * Math.cos(rad(p2.lat)) *
        Math.sin(dLong / 2) * Math.sin(dLong / 2);
    var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    var d = R * c;
    return d; // returns the distance in meter
};


/**
 * Liefert einen formatierten String zur Darstellung einer Entfernung
 * 
 * @param {*} distance Entfernung in Metern
 * @param string unit  Einheit 'metric'=metrisch (m, km), 'nautic'=Seemeilen (nm)
 * @returns string formatierte Entfernung mit Einheit
 */
function formatDistance(distance, unit = 'metric') {

    if (distance == 0) {
        return unit == 'nautical' ? '0.0nm' : '0.0km';
    }

    if (unit == 'nautical') {
        // Nautical
        var nm = distance / 1852;

        if (nm >= 1) {
            return Number.parseFloat(nm).toFixed(1) + 'nm';
        } else {
            return Number.parseFloat(nm).toFixed(3) + 'nm';
        }
    } else {
        // Metric
        if (distance >= 1000) {
            return Number.parseFloat(distance / 1000).toFixed(1) + 'km';
        } else {
            return Number.parseInt(distance) + 'm';
        }
    }
}


/**
 * Standard Beaufort scale upper bounds, in km/h (WMO) - index is the
 * Beaufort number (0-11); a speed above the last entry is Bft 12.
 */
const BEAUFORT_UPPER_BOUNDS_KMH = [1, 5, 11, 19, 28, 38, 49, 61, 74, 88, 102, 117];

function windSpeedToBeaufort(speedKmh) {
    for (let bft = 0; bft < BEAUFORT_UPPER_BOUNDS_KMH.length; bft++) {
        if (speedKmh <= BEAUFORT_UPPER_BOUNDS_KMH[bft]) {
            return bft;
        }
    }
    return 12;
}

/**
 * Refines a Beaufort number with a "-"/"+" suffix marking which third of
 * that Bft level's own km/h range the speed falls into (e.g. "4-", "4",
 * "4+") - Bft 0 (a single narrow calm range) and Bft 12 (open-ended, no
 * upper bound to divide into thirds) are always shown plain.
 */
function formatBeaufort(speedKmh) {
    const bft = windSpeedToBeaufort(speedKmh);
    if (bft === 0 || bft === 12) {
        return String(bft);
    }

    const lower = BEAUFORT_UPPER_BOUNDS_KMH[bft - 1];
    const upper = BEAUFORT_UPPER_BOUNDS_KMH[bft];
    const third = (upper - lower) / 3;
    const posInRange = Math.min(Math.max(speedKmh - lower, 0), upper - lower);

    if (posInRange < third) {
        return bft + '-';
    }
    if (posInRange > 2 * third) {
        return bft + '+';
    }
    return String(bft);
}

/**
 * Liefert nur den Zahlenwert einer Windgeschwindigkeit in der gewuenschten
 * Einheit, ohne Einheiten-Suffix - fuer sehr schmale Anzeigen (z.B. die
 * farbigen Wind-/Boeen-Zellen der Wetter-Zeitleiste), wo die Einheit einmal
 * fuer die ganze Zeile/Spalte angezeigt wird statt pro Zelle (siehe
 * windUnitLabel()). formatWindSpeed() haengt dies nur noch mit der Einheit
 * zusammen, damit die Rundungsregeln pro Einheit an einer Stelle bleiben.
 *
 * @param {*} speedKmh Windgeschwindigkeit in km/h (Open-Meteo's unit)
 * @param string unit  Einheit 'bft'=Beaufort, 'ms'=m/s, 'kmh'=km/h, 'kn'=Knoten
 * @returns string nur der formatierte Zahlenwert
 */
function formatWindSpeedValue(speedKmh, unit = 'kmh') {
    switch (unit) {
        case 'bft':
            return formatBeaufort(speedKmh);
        case 'ms':
            return (speedKmh / 3.6).toFixed(1);
        case 'kn':
            return (speedKmh / 1.852).toFixed(1);
        case 'kmh':
        default:
            return String(Math.round(speedKmh));
    }
}

/**
 * Kurzes, sprachunabhaengiges Einheiten-Kuerzel fuer eine Windeinheit - wie
 * schon in formatWindSpeed() selbst, absichtlich nicht uebersetzt (Bft/km/h/
 * kn/m/s sind international gebraeuchliche Abkuerzungen).
 */
function windUnitLabel(unit = 'kmh') {
    switch (unit) {
        case 'bft': return 'Bft';
        case 'ms': return 'm/s';
        case 'kn': return 'kn';
        case 'kmh':
        default: return 'km/h';
    }
}

/**
 * Liefert einen formatierten String zur Darstellung einer Windgeschwindigkeit.
 *
 * @param {*} speedKmh Windgeschwindigkeit in km/h (Open-Meteo's unit)
 * @param string unit  Einheit 'bft'=Beaufort, 'ms'=m/s, 'kmh'=km/h, 'kn'=Knoten
 * @returns string formatierte Windgeschwindigkeit mit Einheit
 */
function formatWindSpeed(speedKmh, unit = 'kmh') {
    return formatWindSpeedValue(speedKmh, unit) + ' ' + windUnitLabel(unit);
}


// TODO: very dirty should be replaced
function getMiddleCoordinate(p1, p2) {
    return { lat: (p1.lat + p2.lat) / 2, lng: (p1.lng + p2.lng) / 2};
}


/**
 * Glättet einen Streckenzug (z.B. routes[i].points) mit einer zentripetalen
 * Catmull-Rom-Spline. Anders als eine uniforme Catmull-Rom-Spline (alpha=0)
 * erzeugt das bei ungleichmäßig verteilten Punkten (der Normalfall bei
 * gezeichneten/aufgezeichneten Routen) keine Schlaufen/Spitzen an kurzen
 * Segmenten neben langen. Die Kurve verläuft exakt durch jeden Original-
 * punkt - nur die Krümmung dazwischen wird interpoliert, es entsteht kein
 * neuer, vom Original abweichender Wegverlauf, es sei denn maxDeviationMeters
 * schneidet die Kurve näher an die Gerade heran (siehe dort).
 *
 * Rein für die Darstellung gedacht (Polyline-`path`) - NIE auf
 * routes[i].points selbst anwenden, das Array wird für Distanzberechnung,
 * Labels und das Editieren (measureTool) exakt gebraucht.
 *
 * Alle drei Parameter sind über die gleichnamigen ROUTE_SMOOTHING_*-
 * Konstanten in config.js voreingestellt, sodass eine Anpassung dort
 * reicht, ohne route.js anzufassen.
 *
 * @param {Array<{lat:number,lng:number}>} points Rohe Stützpunkte
 * @param {number} [segmentsPerPoint] Anzahl interpolierter Punkte je Originalsegment
 * @param {number} [alpha] Knoten-Parametrisierung der Spline (0=uniform, 0.5=zentripetal, 1=chordal)
 * @param {number} [maxDeviationMeters] Maximaler Abstand eines hinzugefügten Punkts von der Geraden zwischen den beiden echten Nachbar-Stützpunkten, in Metern (Infinity = unbegrenzt)
 * @returns {Array<{lat:number,lng:number}>} neues, geglättetes Array (Original bleibt unverändert)
 */
function smoothRoutePoints(
    points,
    segmentsPerPoint = ROUTE_SMOOTHING_SEGMENTS_PER_POINT,
    alpha = ROUTE_SMOOTHING_ALPHA,
    maxDeviationMeters = ROUTE_SMOOTHING_MAX_DEVIATION_METERS
) {
    var deduped = [];
    for (var i = 0; i < points.length; i++) {
        var last = deduped[deduped.length - 1];
        if (!last || last.lat !== points[i].lat || last.lng !== points[i].lng) {
            deduped.push(points[i]);
        }
    }
    if (deduped.length < 3) {
        return deduped.slice();
    }

    // Gespiegelte Phantom-Endpunkte (2*p0 - p1), NICHT einfach dupliziert -
    // sonst flacht die Tangente an den Enden ab (die Kurve würde dort
    // "zögern" statt natürlich weiterzulaufen).
    var phantomStart = mirrorPoint(deduped[0], deduped[1]);
    var phantomEnd = mirrorPoint(deduped[deduped.length - 1], deduped[deduped.length - 2]);
    var extended = [phantomStart].concat(deduped, [phantomEnd]);

    var smoothed = [];
    for (var i = 0; i < extended.length - 3; i++) {
        var p0 = extended[i], p1 = extended[i + 1], p2 = extended[i + 2], p3 = extended[i + 3];

        var t0 = 0;
        var t1 = t0 + Math.pow(getDistance(p0, p1), alpha);
        var t2 = t1 + Math.pow(getDistance(p1, p2), alpha);
        var t3 = t2 + Math.pow(getDistance(p2, p3), alpha);

        for (var s = 0; s < segmentsPerPoint; s++) {
            var t = t1 + (t2 - t1) * (s / segmentsPerPoint);
            var curvePoint = catmullRomInterpolate(p0, p1, p2, p3, t0, t1, t2, t3, t);
            if (isFinite(maxDeviationMeters)) {
                // p1/p2 sind hier die beiden echten Nachbar-Stützpunkte (nicht
                // die Phantompunkte) - lerpPoint(p1, p2, t1, t2, t) ist also
                // die Position auf der direkten Geraden zwischen ihnen, an
                // derselben Stelle t wie curvePoint auf der Kurve.
                var straightPoint = lerpPoint(p1, p2, t1, t2, t);
                curvePoint = clampDeviation(curvePoint, straightPoint, maxDeviationMeters);
            }
            smoothed.push(curvePoint);
        }
    }
    smoothed.push(deduped[deduped.length - 1]); // exakter letzter Punkt, nicht approximiert

    return smoothed;
}

function mirrorPoint(p, neighbor) {
    return { lat: 2 * p.lat - neighbor.lat, lng: 2 * p.lng - neighbor.lng };
}

// Zieht curvePoint so weit in Richtung straightPoint zurück, dass er
// höchstens maxMeters von ihr entfernt ist - sanftes Kappen statt eines
// harten Sprungs zurück auf die Gerade, da nur der Teil oberhalb der
// Grenze abgeschnitten wird. Wird nur aufgerufen, wenn maxDeviationMeters
// endlich ist (Infinity/Default = kein Aufruf, kein Zusatzaufwand).
function clampDeviation(curvePoint, straightPoint, maxMeters) {
    var deviation = getDistance(curvePoint, straightPoint);
    if (deviation <= maxMeters) {
        return curvePoint;
    }
    return lerpPoint(straightPoint, curvePoint, 0, deviation, maxMeters);
}

// Centripetal-Catmull-Rom-Auswertung an Parameter t über wiederholte lineare
// Interpolation (De-Casteljau-artig) statt der geschlossenen Matrixform -
// gleiches Ergebnis, aber ohne Division durch (t3-t0)/(t2-t1)-Sonderfälle,
// da lerpPoint() Start==Ende bereits selbst abfängt.
function catmullRomInterpolate(p0, p1, p2, p3, t0, t1, t2, t3, t) {
    var a1 = lerpPoint(p0, p1, t0, t1, t);
    var a2 = lerpPoint(p1, p2, t1, t2, t);
    var a3 = lerpPoint(p2, p3, t2, t3, t);
    var b1 = lerpPoint(a1, a2, t0, t2, t);
    var b2 = lerpPoint(a2, a3, t1, t3, t);
    return lerpPoint(b1, b2, t1, t2, t);
}

function lerpPoint(p, q, tStart, tEnd, t) {
    var ratio = (tEnd === tStart) ? 0 : (t - tStart) / (tEnd - tStart);
    return {
        lat: p.lat + (q.lat - p.lat) * ratio,
        lng: p.lng + (q.lng - p.lng) * ratio
    };
}


function angleFromCoordinates(p1, p2) {
    var dLng = (p2.lng - p1.lng);
    var y = Math.sin(dLng) * Math.cos(p2.lat);
    var x = Math.cos(p1.lat) * Math.sin(p2.lat2) - Math.sin(p1.lat) * Math.cos(p2.lat) * Math.cos(dLng);
    var brng = Math.atan2(y, x);

    //brng = Math.toDegrees(brng);
    brng = brng * 180 / Math.PI;
    brng = (brng + 360) % 360;
    brng = 360 - brng; // count degrees counter-clockwise - remove to make clockwise

    return brng;
}



/**
 * Formats a lat/lng pair as a single localized string in the user's chosen
 * coordinateFormat setting (DD/MM/DMS, Preferences screen) - the one shared
 * formatter for every place in the app that displays coordinates as text
 * (POI info/edit windows, the weather timeline panel title, the Preferences
 * screen's own live example).
 *
 * @param {number} lat
 * @param {number} lng
 * @returns {string}
 */
function formatCoordinates(lat, lng) {
    var format = [COORDINATE_FORMAT_DD, COORDINATE_FORMAT_MM, COORDINATE_FORMAT_DMS].includes(settings.coordinateFormat)
        ? settings.coordinateFormat
        : COORDINATE_FORMAT_DD;

    return formatCoordinatePart(lat, format, t('coordinate.north'), t('coordinate.south'))
        + ', '
        + formatCoordinatePart(lng, format, t('coordinate.east'), t('coordinate.west'));
}

function formatCoordinatePart(decimal, format, positiveDirection, negativeDirection) {
    var direction = decimal >= 0 ? positiveDirection : negativeDirection;
    decimal = Math.abs(decimal);

    if (format === COORDINATE_FORMAT_DD) {
        return decimal.toFixed(5) + '° ' + direction;
    }

    var degrees = Math.floor(decimal);
    var minutesDecimal = (decimal - degrees) * 60;

    if (format === COORDINATE_FORMAT_MM) {
        return degrees + '° ' + minutesDecimal.toFixed(3) + "' " + direction;
    }

    var minutes = Math.floor(minutesDecimal);
    var seconds = Math.round((minutesDecimal - minutes) * 60);
    // A rounded 60" carries into the next minute (and a rounded 60' into the
    // next degree) - rare, but showing e.g. 54°19'60"N would look broken.
    if (seconds === 60) {
        seconds = 0;
        minutes += 1;
    }
    if (minutes === 60) {
        minutes = 0;
        degrees += 1;
    }

    return degrees + '° ' + minutes + "' " + seconds + '" ' + direction;
}



function midElement(array) {
        return array[Math.floor(array.length / 2)];
}

/**
 * Liefert ein leeres User-Objekt zurück.
 *
 * Lives here (not in map-core.js, despite the "user" concept feeling more
 * user.js-shaped) so pages that need a `user` global but don't load the map
 * (templates/admin-users.php) can use it too - it's just an object-shape
 * factory, no map/domain logic of its own.
 *
 * @returns user object
 */
function initUser() {
    return {
        id: null,
        username: '',
        email: '',
        firstname: '',
        lastname: '',
        is_admin: false,
        tour_create: false,
        tour_publish: false,
        tour_manage: false,
        tour_copy: false,
        route_view_recording: false,
        pending_email: null,
        pending_email_expires_at: null
    }
}

window.mobileCheck = function() {
    let check = false;
    (function(a){if(/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i.test(a)||/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(a.substr(0,4))) check = true;})(navigator.userAgent||navigator.vendor||window.opera);
    return check;
};