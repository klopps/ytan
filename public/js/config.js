/**
 * YTAN Configuration
 */

const LOG_OFF = 0;
const LOG_DEFAULT = 1
const LOG_VERBOSE = 2;
const LOG_DEBUG = 3;

const ZINDEX_ROUTE = 10;
const ZINDEX_POI = 20;


 // additionl scripts to be injected after Google Maps is loaded
 const injectScripts = [
    //'./lib/map-label/maplabel.js',
    './lib/markerWithLabel/markerwithlabel.min.js',
    './lib/map-label-rotated/rotated-label.js',
    './lib/sectorlight-overlay/sectorlight-overlay.js'
];

// minimum length of route section in meters to show the distance label on different zoom levels
const ROUTELABEL_ZOOM_VISIBILITY = [];
ROUTELABEL_ZOOM_VISIBILITY[1]  = 5_000_000;
ROUTELABEL_ZOOM_VISIBILITY[2]  = 2_500_000;
ROUTELABEL_ZOOM_VISIBILITY[3]  = 1_250_000;
ROUTELABEL_ZOOM_VISIBILITY[4]  =   600_000;
ROUTELABEL_ZOOM_VISIBILITY[5]  =   300_000;
ROUTELABEL_ZOOM_VISIBILITY[6]  =   150_000;
ROUTELABEL_ZOOM_VISIBILITY[7]  =    75_000;
ROUTELABEL_ZOOM_VISIBILITY[8]  =    40_000;
ROUTELABEL_ZOOM_VISIBILITY[9]  =    20_000;
ROUTELABEL_ZOOM_VISIBILITY[10] =    10_000;
ROUTELABEL_ZOOM_VISIBILITY[11] =     5_000;
ROUTELABEL_ZOOM_VISIBILITY[12] =     2_000;
ROUTELABEL_ZOOM_VISIBILITY[13] =     1_000;
ROUTELABEL_ZOOM_VISIBILITY[14] =       500;
ROUTELABEL_ZOOM_VISIBILITY[15] =       250;
ROUTELABEL_ZOOM_VISIBILITY[16] =       125;
ROUTELABEL_ZOOM_VISIBILITY[17] =        75;
ROUTELABEL_ZOOM_VISIBILITY[18] =        35;
ROUTELABEL_ZOOM_VISIBILITY[19] =        15;
