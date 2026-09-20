/**
 * YTAN Configuration
 */

const LOG_ERROR = 0;
const LOG_WARN = 1;
const LOG_INFO = 2;
const LOG_DEBUG = 3;

const ZINDEX_ROUTE = 10;
const ZINDEX_POI = 20;

// Photo upload size/compression tuning - shared by tour-admin.js's
// stageTourFormPhoto()/compressTourPhoto() (tours) and photo-upload.js's
// stagePhotoUpload()/compressPhotoUpload() (POIs/routes/areas). Must match
// the backend's own independent limit (ImageStorageService::MAX_BYTES,
// src/Service/ImageStorageService.php - one instance per entity type, same
// 5 MB cap for all of them) so a client-side "compressed enough" result is
// never rejected server-side anyway.
const TOUR_PHOTO_MAX_BYTES = 5 * 1024 * 1024;
// Longest side a re-encoded photo is scaled down to before compression is
// even attempted - generous for a gallery/lightbox view, but keeps a huge
// (e.g. 12000px) source image from needing many quality-reduction rounds to
// reach TOUR_PHOTO_MAX_BYTES.
const TOUR_PHOTO_MAX_DIMENSION_PX = 2560;
// JPEG quality steps tried in order until the result fits under
// TOUR_PHOTO_MAX_BYTES - if even the lowest still doesn't fit (very large/
// busy source image), the canvas itself is halved and the same steps are
// retried, up to TOUR_PHOTO_MAX_DOWNSCALE_ROUNDS times.
const TOUR_PHOTO_QUALITY_STEPS = [0.92, 0.85, 0.75, 0.65, 0.55, 0.45, 0.35];
const TOUR_PHOTO_MAX_DOWNSCALE_ROUNDS = 3;

// Max photos per entity - each mirrors that entity's own controller
// constant (TourController::MAX_IMAGES_PER_TOUR, PoiController::
// MAX_IMAGES_PER_POI, RouteController::MAX_IMAGES_PER_ROUTE,
// AreaController::MAX_IMAGES_PER_AREA), kept in sync manually, there being
// no shared config layer between PHP and JS. An entity that already has
// more images than this (e.g. after lowering the value) never loses any of
// them: the count only gates new uploads (each controller's uploadImage()
// count check), nothing ever re-validates or purges existing rows against
// it. POI/Route/Area default lower than Tour's - they're edited in a
// ~280-320px-wide Google Maps InfoWindow, not a full-screen panel.
const TOUR_PHOTO_MAX_COUNT = 9;
const POI_PHOTO_MAX_COUNT = 5;
const ROUTE_PHOTO_MAX_COUNT = 5;
const AREA_PHOTO_MAX_COUNT = 5;

// Tuning for smoothRoutePoints() (helper.js) - the centripetal Catmull-Rom
// curve drawn for a route's display Polyline when settings.smoothRoutes is
// on. Purely a display detail, never affects routes[i].points itself. All
// three are read as defaults by smoothRoutePoints()'s own parameters, so
// changing a value here takes effect everywhere without touching route.js.
//
// - SEGMENTS_PER_POINT: how many interpolated points are inserted between
//   each pair of original vertices. Higher = smoother-looking curve, more
//   points on the map (cheap at the ~100-point route sizes this app sees -
//   see track-recorder.js's TRACK_SIMPLIFY_MAX_POINTS).
// - ALPHA: the spline's knot-parametrization exponent. 0.5 (centripetal) is
//   the standard safe default - avoids the loops/cusps a uniform spline
//   (alpha 0) produces on the unevenly-spaced points routes actually have.
//   1 (chordal) hugs the original straight segments more tightly, 0 curves
//   more aggressively but risks self-intersecting loops on sharp corners.
// - MAX_DEVIATION_METERS: caps how far the curve may bulge away from the
//   straight line between two original vertices (Infinity = no cap, the
//   default this feature shipped with). Lower it to keep sharp corners
//   closer to their real straight-line path - useful on a narrow waterway,
//   where an uncapped curve could visually bulge across a riverbank on a
//   tight bend even though the underlying route data never left the water.
const ROUTE_SMOOTHING_SEGMENTS_PER_POINT = 15;
const ROUTE_SMOOTHING_ALPHA = 0.5;
const ROUTE_SMOOTHING_MAX_DEVIATION_METERS = 15; // Default: Infinity

// Page-size choices offered by the admin panels' "rows per page" dropdowns
// (admin-user.js's user table, tour-admin.js's My/Public Tours sections),
// and which one is selected by default whenever a panel is (re)opened.
const ADMIN_LIST_PAGE_SIZES = [5, 10, 50, 100];
const ADMIN_LIST_DEFAULT_PAGE_SIZE = 10;

// Max rows rendered in route.js's small "Add to tour" popup (opened from a
// route's context menu / InfoWindow) - unlike the admin Tours panel, this
// popup has no pagination controls of its own, so an unbounded match list
// would just keep growing as a user's tour count grows. Matches the same
// cap map-core.js's own-POI search dropdown already uses.
const ADD_TO_TOUR_MENU_RESULT_CAP = 8;

// Below this zoom level, individual POI markers are replaced by clustered
// count indicators (grouped by screen proximity); at this zoom level and
// above, individual POI markers are shown as usual.
const POI_CLUSTER_ZOOM_THRESHOLD = 11;

// Two POIs are never merged into the same cluster indicator if they are
// further apart than this (in meters), even if they happen to be close on
// screen at very low zoom levels. Without this cap, zooming out far enough
// to see e.g. both Scandinavia and the Mediterranean at once could combine
// touring regions from opposite ends of the map into one misleading
// indicator, whose "fit bounds on click" would then jump somewhere
// unexpected instead of staying within the region the indicator visually
// appears to represent.
const POI_CLUSTER_MAX_MERGE_DISTANCE_METERS = 150_000;

// Browser-only "cold start" splash screen (splashscreen.js) - shown when the
// page loads after the app hasn't been used for at least this many seconds
// (tracked in localStorage), hidden again after
// SPLASHSCREEN_DISPLAY_DURATION_MS regardless of load progress. Skipped
// entirely inside the native Capacitor Android shell, which already shows
// its own native splash screen before this page even starts loading.
const SPLASHSCREEN_IDLE_THRESHOLD_SECONDS = 300;
const SPLASHSCREEN_DISPLAY_DURATION_MS = 1500;


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
