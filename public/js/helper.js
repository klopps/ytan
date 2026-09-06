/** 
 * Helpful functions
 *
 */



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


// TODO: very dirty should be replaced
function getMiddleCoordinate(p1, p2) {
    return { lat: (p1.lat + p2.lat) / 2, lng: (p1.lng + p2.lng) / 2};
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



function decimalToDMS(decimal) {

    var degrees = Math.floor(decimal);
    var minutesDecimal = (decimal - degrees) * 60;
    var minutes = Math.floor(minutesDecimal);
    var seconds = (minutesDecimal - minutes) * 60;

    return degrees + "° " + minutes + "' " + seconds.toFixed(2) + '"';
}


function decimalLatitudeToDMS(decimal) {
    var direction = decimal >= 0 ? "N" : "S";
    decimal = Math.abs(decimal);
    var degrees = Math.floor(decimal);
    var minutesDecimal = (decimal - degrees) * 60;
    var minutes = Math.floor(minutesDecimal);
    var seconds = (minutesDecimal - minutes) * 60;

    return degrees + "°" + minutes + "'" + seconds.toFixed(2) + '"' + direction;
}


function decimalLongitudeToDMS(decimal) {
    var direction = decimal >= 0 ? "E" : "W";
    decimal = Math.abs(decimal);
    var degrees = Math.floor(decimal);
    var minutesDecimal = (decimal - degrees) * 60;
    var minutes = Math.floor(minutesDecimal);
    var seconds = (minutesDecimal - minutes) * 60;

    return degrees + "°" + minutes + "'" + seconds.toFixed(2) + '"' + direction;
}


function decimalLatLngToDMS(decimalLat, decimalLng) {
        
    var directionLat = decimalLat >= 0 ? "N" : "S";
    decimalLat = Math.abs(decimalLat);
    var degreesLat = Math.floor(decimalLat);
    var minutesDecimalLat = (decimalLat - degreesLat) * 60;
    var minutesLat = Math.floor(minutesDecimalLat);
    var secondsLat = (minutesDecimalLat - minutesLat) * 60;

    var directionLng = decimalLng >= 0 ? "E" : "W";
    decimalLng = Math.abs(decimalLng);
    var degreesLng = Math.floor(decimalLng);
    var minutesDecimalLng = (decimalLng - degreesLng) * 60;
    var minutesLng = Math.floor(minutesDecimalLng);
    var secondsLng = (minutesDecimalLng - minutesLng) * 60;

    return degreesLat + "°" + pad(minutesLat, 2) + "'" + pad(secondsLat, 2 ,2) + '"' + directionLat + ', ' + degreesLng + "°" + pad(minutesLng, 2) + "'" + pad(secondsLng, 2, 2) + '"' + directionLng;
}


function pad(num, size, decimals = 0) {
    var  before = num.toFixed(1).slice(0,-2);
    while (before.length  < size) before = "0" + before;

    if (decimals < 1) {
        return before;
    } else {
        return before + '.' + num.toFixed(decimals).slice(-decimals);
    }
}



function midElement(array) {
        return array[Math.floor(array.length / 2)];
}

window.mobileCheck = function() {
    let check = false;
    (function(a){if(/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i.test(a)||/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(a.substr(0,4))) check = true;})(navigator.userAgent||navigator.vendor||window.opera);
    return check;
};