/**
 * Map bootstrap, global state, geolocation and top-level map settings.
 *
 * @author Christoph Steindorff
 */

const ICONSET = 'mapicons'; // [google, mapicons]
const METRIC = 'metric';
const NAUTICAL = 'nautical';
const THEME_LIGHT = 'light';
const THEME_DARK = 'dark';
const SEARCH_GOOGLE_MIN_LENGTH = 3; // avoid firing a billed Autocomplete call for very short, unspecific input

class RouteTool extends MeasureTool {
    setIndex(index) {
        this.index = index;
    }
}

// Lights
let light = new LightCharacteristic();

// URL
let ytanUrl = new URL(window.location.href);

// Zoom einstellen
let initialZoom = 8;
let zoomParam = ytanUrl.searchParams.get("z");
if (zoomParam !== null) {
    if (!isNaN(parseInt(zoomParam))) {
        initialZoom = parseInt(zoomParam);
    }
}

// Haben wir ein Center?
let initialLat = parseFloat(ytanUrl.searchParams.get("lat"));
let initialLng = parseFloat(ytanUrl.searchParams.get("lng"));
let initialLatLng = null;
if (!(isNaN(initialLat) || isNaN(initialLng))) {
    initialLatLng = {
        lat: initialLat,
        lng: initialLng
    };
}

let map;
let mapInitialized = false; // set true at the end of initMap() - app.php's /auth/me callback checks this to tell whether it lost the race against Google Maps loading (see initMap()'s user.id !== null branch)
let googleMapsScriptIsInjected = false;
let measureTool;
let infoWindow;
let poiEditWindow;
let routeInfoWindow;
let routeEditWindow;
let contextMenu;
let areaEditWindow;
let areaInfoWindow;
let poiMarker;
let myPositionMarker;
let autocompleteService;
let placesService;
let searchMarker;
let searchInfoWindow;
let googlePredictions = [];
let ownPoiMatches = [];
let searchMode = 'google';
let searchDebounceTimer = null;
let searchSessionToken = null;
let lastQueriedGoogleText = null;
let googleSearchAllowed = true; // kept in sync with login state by updateGoogleSearchAllowed()

let pois = [];
let portagePaths = [];
let markers = [];
let poiClusterMarkers = [];
let wsiMarkers = [];
let routes = [];
let routePaths = [];
let tours = [];
let areas = [];
let areaPolygons = [];
let poiInfoWindows = [];
let sectorLights = []; // Array mit den derzeit sichtbaren Sector Lights
let selectedTour = null; // aktuell ausgwewählte Tour oder null

// route.js's "Add to tour" popup (routeInfoWindow's #addToTourMenu, toggled
// by toggleAddToTourMenu()/routeContextMenuAddToTour()): which routes[]
// index it's for, plus its own search/length-filter state, mirroring
// tour-admin.js's list view (tourAdminAllTours's search/tourAdminLengthFilter).
let addToTourMenuRouteIndex = null;
let addToTourMenuSearchQuery = '';
let addToTourMenuLengthFilter = { min: '', max: '' };
let contextMenuLastLatLng = null; // captured by showRouteContextMenu() so routeContextMenuAddToTour() can reposition routeInfoWindow

let mapClickListener;
let maxZoomService;
/**
 * Manual long-press-to-contextmenu fallback for POI markers/route
 * polylines/area polygons, alongside (not instead of) their existing
 * 'contextmenu' listener. Google Maps' own 'contextmenu' overlay event is
 * documented to also fire from a long-press on touch devices, piggybacking
 * on the browser's native touch-and-hold-generates-a-contextmenu-DOM-event
 * behavior - but that didn't actually happen on a real phone (confirmed).
 *
 * A first attempt drove this off Maps' own translated 'mousedown'/'mouseup'
 * overlay events instead - also confirmed broken on a real phone ("a long
 * click behaves the same as a short one"): Maps apparently doesn't fire its
 * synthetic 'mousedown' for a touch until it has finished classifying the
 * whole gesture (so it doesn't mistake the start of a pan/pinch for a
 * click) - in practice that means 'mousedown' and 'mouseup' both arrive
 * together right as the finger lifts, however long it was actually held,
 * so a timer started on 'mousedown' never gets the time it needs.
 *
 * A second attempt tried binding raw DOM 'touchstart'/'touchend' straight to
 * the overlay via google.maps.event.addDomListener() - that only works for
 * Marker (POIs), which really does wrap a DOM node; Polyline/Polygon
 * (routes/areas) are painted onto a shared canvas/SVG surface with no
 * corresponding per-instance DOM element to listen on.
 *
 * A third attempt measured real elapsed time at the map-container level
 * (map.getDiv()'s own 'touchstart'/'touchend', which DO reflect true
 * physical hold duration) but still relied on the overlay's 'mousedown'
 * MapMouseEvent - whenever it fires - to learn *which* overlay/handler was
 * pressed. Real-device testing then proved 'mousedown' doesn't fire AT ALL
 * for a touch-originated press on at least one real phone/Maps build - a
 * genuine 3+ second hold still left it unset. So none of the three timing
 * strategies above ever had a chance: all of them depend on identifying the
 * target via Maps' 'mousedown', which this device's Maps build simply never
 * sends for touch (confirmed: 'click' still fires normally on release, just
 * not the separate 'mousedown'/'mouseup' pair around it).
 *
 * This version drops that dependency entirely and does its own hit test
 * from the raw touch coordinates (findLongPressTarget()) against every
 * marker/polyline/polygon registered via attachLongPressContextMenu()
 * (longPressCandidates), using an OverlayView purely to get pixel<->LatLng
 * conversion (overlayProjection, set in initMap()). Identification now
 * happens synchronously at 'touchstart', independent of whatever Maps' own
 * gesture recognizer does afterward.
 */
const LONG_PRESS_DURATION_MS = 550;
const LONG_PRESS_MOVE_TOLERANCE_PX = 10;
const LONG_PRESS_HIT_TEST_TOLERANCE_PX = 20; // line width/area edge tolerance for findLongPressTarget()'s polyline/polygon checks
const LONG_PRESS_TOUCH_MARGIN_PX = 14; // extra slack around a marker's actual on-screen icon box, for finger imprecision
const POI_ICON_WIDTH = 32; // public/markers/poi_*_mapicons.png are all this size
const POI_ICON_HEIGHT = 37;
let longPressTouchStartTime = 0;
let longPressTouchStartPos = null;
let longPressCancelled = false; // module-level (not just a closure in initLongPressTouchTracking()) so shouldSuppressClick() can read it too
let longPressPendingOverlay = null; // {handler, event} - set by findLongPressTarget() at 'touchstart'
let longPressCandidates = []; // every {overlay, handler} registered via attachLongPressContextMenu(), for findLongPressTarget()
let overlayProjection; // OverlayView set in initMap(), exposes getProjection() for pixel<->LatLng conversion

/**
 * Generic "right-click / long-press on an empty point on the map" context
 * menu - as opposed to poi.js/route.js/area.js's own per-overlay context
 * menus (a click on one of THEIR markers/polylines/polygons is handled
 * entirely separately, before this one ever gets a chance - see
 * findLongPressTarget()'s "nothing hit" fallback below and the map-level
 * 'rightclick' listener in initMap()). Feature files register their own
 * item via registerMapContextMenuItem() (e.g. weather.js's "Weather data
 * for this location") instead of this file needing to know about them -
 * meant to grow over time, per the same "just push into a shared array"
 * extensibility already used for longPressCandidates above.
 */
let mapContextMenuItems = []; // { icon, labelKey, handler(latLng) }
let mapContextMenuLatLng = null; // the location the currently-open menu was opened for, read by invokeMapContextMenuItem()

function registerMapContextMenuItem(iconName, labelKey, handler) {
    mapContextMenuItems.push({ icon: iconName, labelKey: labelKey, handler: handler });
}

function invokeMapContextMenuItem(index) {
    closeContextMenu();
    if (mapContextMenuItems[index] && mapContextMenuLatLng) {
        mapContextMenuItems[index].handler(mapContextMenuLatLng);
    }
}

function showMapContextMenu(latLng) {
    if (mapContextMenuItems.length === 0) {
        return;
    }
    mapContextMenuLatLng = latLng;

    var content = '';
    for (let i = 0; i < mapContextMenuItems.length; i++) {
        content += '<div class="contextMenuItem" onclick="invokeMapContextMenuItem(' + i + ');"><i class="material-icons-round">' + mapContextMenuItems[i].icon + '</i>' + t(mapContextMenuItems[i].labelKey) + '</div>';
    }
    content += '<div class="contextMenuItem" onclick="closeContextMenu();"><i class="material-icons-round">close</i>' + t('common.cancel') + '</div>';

    contextMenu.setPosition(latLng);
    contextMenu.setContent(content);
    contextMenu.open(map);
}

/**
 * Registers overlay/handler with findLongPressTarget()'s hit-test registry
 * - that plus the touch handling in initLongPressTouchTracking() is the
 * whole mechanism now. An earlier version also armed a plain
 * mousedown->550ms-timer->mouseup-cancels-it pair here as a "desktop"
 * fallback, on the assumption that a real mouse's mousedown/mouseup aren't
 * synthesized the way touch's are - but Maps can fire a synthetic
 * 'mousedown' for a touch WITHOUT a reliably-following 'mouseup' to cancel
 * it (confirmed: this fired the context menu for plain short taps too, the
 * timer having nothing else to stop it). Real desktop users already have
 * native right-click, handled by the separate, pre-existing 'contextmenu'
 * listener each of poi.js/route.js/area.js keeps on these same overlays -
 * this function's whole purpose is the touch fallback.
 */
function attachLongPressContextMenu(overlay, handler) {
    longPressCandidates.push({ overlay: overlay, handler: handler });
}

/**
 * Runs a long-press's resolution - either a specific overlay's handler
 * (POI/route/area), or, when findLongPressTarget() hit nothing, the
 * generic map-level context menu (showMapContextMenu() above) at the
 * long-pressed location. Every call site that can trigger a long-press
 * (the touch paths in initLongPressTouchTracking()) calls this instead of
 * invoking a handler directly, purely so there's one place documenting the
 * click-suppression problem below (fireLongPress() itself does nothing
 * beyond that dispatch - shouldSuppressClick() is what actually solves it).
 *
 * @param {{handler: ?function, event: {latLng: google.maps.LatLng}}} pending
 */
function fireLongPress(pending) {
    if (pending.handler) {
        pending.handler(pending.event);
    } else {
        showMapContextMenu(pending.event.latLng);
    }
}

/**
 * poi.js/route.js/area.js's own 'click' listeners (which open the normal
 * info window) call this first and skip opening anything if it returns
 * true. Maps still fires a plain 'click' MapMouseEvent when the finger
 * actually lifts, regardless of how long it was held - confirmed on a real
 * device: a genuinely successful ~2.6s long-press DID open the context
 * menu, but the release's ordinary click then opened the marker's normal
 * info window right on top of it, making the whole thing look like it
 * never worked.
 *
 * A first fix tried a "just fired" flag set inside fireLongPress() - broken
 * by a race: that flag gets set only after our own (deliberately delayed,
 * see resolveLongPress()) resolution runs, but Maps' 'click' for the same
 * release can fire synchronously before that, so the flag wasn't armed yet
 * when the click handler actually checked it. This version sidesteps the
 * race entirely by not depending on our own resolution's timing at all -
 * it recomputes "was the touch that's ending right now held long enough to
 * be a long press" directly from longPressTouchStartTime at the moment the
 * click itself fires, which needs no prior step to have already run.
 */
function shouldSuppressClick() {
    if (longPressCancelled || !longPressTouchStartTime) {
        return false;
    }
    return (Date.now() - longPressTouchStartTime) >= LONG_PRESS_DURATION_MS;
}

/**
 * Finds which registered marker/polyline/polygon (if any) is under the
 * given screen coordinates - see the big comment above for why this exists
 * instead of trusting Maps' 'mousedown' MapMouseEvent to say so. Markers
 * are checked first (closest within tolerance wins), then polylines, then
 * polygons - matching visual stacking (a point drawn over a line/area
 * should win). Returns null if nothing is close enough.
 *
 * Markers use a bounding-box test around the icon's actual on-screen
 * rectangle, not a small radius around marker.getPosition()'s pixel -
 * confirmed on a real device that a straightforward tap on a visible POI
 * icon still reported hit=false. Root cause: these icons (32x37, see
 * POI_ICON_WIDTH/HEIGHT) get no explicit `icon.anchor` for most POI types,
 * so Maps defaults to bottom-center - meaning getPosition()'s pixel is at
 * the icon's bottom edge, ~18px below where a user naturally taps its
 * visual center. A 20px radius from that bottom point left barely any
 * margin for real finger imprecision on top of that offset.
 */
function findLongPressTarget(clientX, clientY) {
    if (!overlayProjection || !overlayProjection.getProjection()) {
        return null;
    }
    const projection = overlayProjection.getProjection();
    const rect = map.getDiv().getBoundingClientRect();
    const touchPoint = new google.maps.Point(clientX - rect.left, clientY - rect.top);
    const touchLatLng = projection.fromContainerPixelToLatLng(touchPoint);
    const eventForHandler = { latLng: touchLatLng };

    let closestMarker = null;
    let closestMarkerDist = Infinity;

    for (const candidate of longPressCandidates) {
        const overlay = candidate.overlay;
        if (!(overlay instanceof google.maps.Marker) || !overlay.getMap() || !overlay.getPosition()) {
            continue;
        }
        const anchorPixel = projection.fromLatLngToContainerPixel(overlay.getPosition());
        const icon = overlay.getIcon();
        // Maps' own default anchor when none is set: bottom-center of the icon.
        let anchorX = POI_ICON_WIDTH / 2;
        let anchorY = POI_ICON_HEIGHT;
        if (icon && typeof icon === 'object' && icon.anchor) {
            anchorX = icon.anchor.x;
            anchorY = icon.anchor.y;
        }
        const left = anchorPixel.x - anchorX - LONG_PRESS_TOUCH_MARGIN_PX;
        const right = anchorPixel.x + (POI_ICON_WIDTH - anchorX) + LONG_PRESS_TOUCH_MARGIN_PX;
        const top = anchorPixel.y - anchorY - LONG_PRESS_TOUCH_MARGIN_PX;
        const bottom = anchorPixel.y + (POI_ICON_HEIGHT - anchorY) + LONG_PRESS_TOUCH_MARGIN_PX;
        if (touchPoint.x < left || touchPoint.x > right || touchPoint.y < top || touchPoint.y > bottom) {
            continue;
        }
        const centerX = (left + right) / 2;
        const centerY = (top + bottom) / 2;
        const dist = Math.hypot(centerX - touchPoint.x, centerY - touchPoint.y);
        if (dist < closestMarkerDist) {
            closestMarkerDist = dist;
            closestMarker = candidate;
        }
    }
    if (closestMarker) {
        return { handler: closestMarker.handler, event: eventForHandler };
    }

    // isLocationOnEdge()'s tolerance is in degrees, not pixels - convert our
    // pixel tolerance via the standard Web Mercator meters-per-pixel formula.
    const metersPerPixel = 156543.03392 * Math.cos(touchLatLng.lat() * Math.PI / 180) / Math.pow(2, map.getZoom());
    const toleranceDegrees = (LONG_PRESS_HIT_TEST_TOLERANCE_PX * metersPerPixel) / 111320;

    for (const candidate of longPressCandidates) {
        const overlay = candidate.overlay;
        if (overlay instanceof google.maps.Polyline && overlay.getMap() &&
            google.maps.geometry.poly.isLocationOnEdge(touchLatLng, overlay, toleranceDegrees)) {
            return { handler: candidate.handler, event: eventForHandler };
        }
    }

    for (const candidate of longPressCandidates) {
        const overlay = candidate.overlay;
        if (overlay instanceof google.maps.Polygon && overlay.getMap() &&
            google.maps.geometry.poly.containsLocation(touchLatLng, overlay)) {
            return { handler: candidate.handler, event: eventForHandler };
        }
    }

    // Nothing registered (POI/route/area) is under this point - still
    // return the computed location, with a null handler, so callers can
    // fall back to the generic map-level context menu (showMapContextMenu())
    // instead of just discarding the long-press. Only the "no projection
    // available yet" case above returns a bare null - that one genuinely
    // has no location to offer.
    return { handler: null, event: eventForHandler };
}

/**
 * One-time setup (called from initMap()) for the touch-timing half of
 * attachLongPressContextMenu() above - see that function's comment.
 *
 * A real device apparently doesn't get this far via 'touchend' at all
 * (confirmed: still didn't work there even though this exact mechanism
 * tested correctly under CDP touch emulation) - Chrome's own built-in
 * long-press gesture detector likely intercepts a genuinely-held touch at
 * around the same ~500ms mark and hands it off to native context-menu
 * handling, which cancels the in-progress touch sequence (a 'touchcancel'
 * instead of a 'touchend' reaching the page) and/or dispatches a native DOM
 * 'contextmenu' event - something CDP's synthetic touch injection doesn't
 * reproduce, which is why the emulated test didn't catch this. Two more
 * paths are added below, independent of one another and of 'touchend', so
 * whichever one the real device actually takes still ends up resolving the
 * long-press.
 */
function initLongPressTouchTracking() {
    const mapDiv = map.getDiv();

    // A second finger touching down mid-gesture (pinch-zoom) also fires its
    // own 'touchstart' on the same element, and lifting fingers one at a
    // time also fires 'touchend'/'touchcancel' per finger, each time with
    // however many touches remain - naively resetting/resolving on every
    // such event let an earlier pinch/pan (while finding a POI to test on)
    // leave a stale longPressTouchStartTime that a later, unrelated touchend
    // then measured against, producing wildly-too-long durations (confirmed:
    // a real test reported duration=4711 for what was actually a ~1s press).
    // longPressCancelled (module-level, see top of file - also read by
    // shouldSuppressClick()) tracks "this is no longer a plain single-finger
    // touch, ignore it" for the whole gesture.

    mapDiv.addEventListener('touchstart', function (domEvent) {
        if (domEvent.touches.length !== 1) {
            // a 2nd+ finger joined an already-tracked touch - no longer a
            // candidate long press
            longPressCancelled = true;
            return;
        }
        const touch = domEvent.touches[0];
        longPressTouchStartTime = Date.now();
        longPressTouchStartPos = { x: touch.clientX, y: touch.clientY };
        longPressCancelled = false;
        // Don't wait for Maps' 'mousedown' MapMouseEvent - confirmed it may
        // never fire for a touch at all (see the big comment above). Hit-test
        // right now, synchronously, from the real touch coordinates instead.
        longPressPendingOverlay = findLongPressTarget(touch.clientX, touch.clientY);
    }, { passive: true });

    mapDiv.addEventListener('touchmove', function (domEvent) {
        if (longPressCancelled || !longPressTouchStartPos) {
            return;
        }
        if (domEvent.touches.length !== 1) {
            longPressCancelled = true;
            return;
        }
        const touch = domEvent.touches[0];
        if (Math.abs(touch.clientX - longPressTouchStartPos.x) > LONG_PRESS_MOVE_TOLERANCE_PX ||
            Math.abs(touch.clientY - longPressTouchStartPos.y) > LONG_PRESS_MOVE_TOLERANCE_PX) {
            longPressCancelled = true;
        }
    }, { passive: true });

    function resolveLongPress(source, domEvent) {
        if (domEvent.touches.length !== 0) {
            // another finger is still down - not the real end of this touch yet
            return;
        }
        const duration = Date.now() - longPressTouchStartTime;

        // Small grace delay before the check, mostly harmless now that
        // identification happens synchronously at 'touchstart' rather than
        // waiting on Maps.
        setTimeout(function () {
            if (longPressCancelled || duration < LONG_PRESS_DURATION_MS || !longPressPendingOverlay) {
                return;
            }
            const pending = longPressPendingOverlay;
            longPressPendingOverlay = null;
            fireLongPress(pending);
        }, 50);
    }

    mapDiv.addEventListener('touchend', function (domEvent) { resolveLongPress('touchend', domEvent); }, { passive: true });
    mapDiv.addEventListener('touchcancel', function (domEvent) { resolveLongPress('touchcancel', domEvent); }, { passive: true });

    // Second, independent path: the real native long-press-to-contextmenu
    // gesture (see comment above) - if it does fire, use it directly rather
    // than the (confirmed unreliable on a real device) per-overlay
    // 'contextmenu' MapMouseEvent translation. Capture phase so this runs
    // before Maps' own handling might stop the event.
    document.addEventListener('contextmenu', function (domEvent) {
        const heldFor = Date.now() - longPressTouchStartTime;
        // Don't just trust that the browser only fires this after its own
        // ~500ms long-press threshold - confirmed unreliable under test
        // tooling (CDP fired it for a plain 150ms tap); require our own
        // duration check too, same as the touchend/touchcancel path.
        if (!mapDiv.contains(domEvent.target) || !longPressPendingOverlay ||
            longPressCancelled || heldFor < LONG_PRESS_DURATION_MS) {
            return;
        }
        domEvent.preventDefault();
        const pending = longPressPendingOverlay;
        longPressPendingOverlay = null;
        fireLongPress(pending);
    }, true);
}

var settings = { // muss wegen JSON.stringify() ein Objekt sein
    detail0: true, // POI
    detail1: true, // Camps
    detail2: true, // Landing sites
    detail3: true, // Drinking water
    detail4: true, // Toilets
    detail5: true, // Historic Sites
    detail6: true, // Danger Zones
    detail7: true, // Natural Sights
    detail8: true, // Shopping
    detail9: true, // Portages
    detail10: true, // Shelter
    detail11: true, // Campsite (commercial)
    detail12: true, // Medical Care
    detail13: true, // Clubs / Institutions
    detail14: true, // Lights (Lighthouses, Sea Marks)
    detail15: true, // Parking
    detail16: true, // Fishing
    detailroutes: true, // Routes
    detailareas: true,  // Areas
    detailwsi: false,  // Wind Shelter Indicators
    maptype: "hybrid",
    center: null,
    zoom: null,
    unit: METRIC,
    theme: THEME_LIGHT
};

let language = window.navigator.userLanguage || window.navigator.language;

const geolocationOptions = {
    enableHighAccuracy: true,
    timeout: 5000,
    maximumAge: 0
};


/**
 * Load Google Maps dynamically
 * @param {string} APIKey Your Google Maps API keys
 */
function loadGoogleMaps(APIKey) {
    injectGoogleMapsApiScript({
        key: APIKey,
        callback: 'initMap',
        libraries: 'geometry,places',
        v: 'weekly',
        loading: 'async'
    });

    document.getElementById("gdpr").style.display = "none";
    document.getElementById("map").style.display = "block";
    showToolbar();
    document.getElementById("sidemenu-toggle").style.display = "flex";
    document.getElementById("mapSearchWrapper").style.display = "block";
    document.getElementById("gotomylocation").style.display = "block";

    setCookie("gdpr_accepted", "yes", 365);
}

const injectGoogleMapsApiScript = (options = {}) => {
    if (googleMapsScriptIsInjected) {
        throw new Error('Google Maps Api is already loaded.');
    }

    const optionsQuery = Object.keys(options)
        .map(k => `${encodeURIComponent(k)}=${encodeURIComponent(options[k])}`)
        .join('&');

    const url = `https://maps.googleapis.com/maps/api/js?${optionsQuery}`;

    const script = document.createElement('script');
    script.setAttribute('src', url);
    script.async = true;
    document.head.appendChild(script);

    googleMapsScriptIsInjected = true;
};

function injectAdditionalScripts() {
    if (!googleMapsScriptIsInjected) {
        throw new Error('Google Maps Api is not already loaded.');
    }

    for (const scriptPath of injectScripts) {
        var script = document.createElement("script");
        script.setAttribute('src', scriptPath);
        document.head.appendChild(script);
        log(scriptPath + ' injected', LOG_DEBUG);
    }
}

function initMap() {
    infoWindow = new google.maps.InfoWindow();
    poiEditWindow = new google.maps.InfoWindow();
    routeEditWindow = new google.maps.InfoWindow();
    areaEditWindow = new google.maps.InfoWindow();
    routeInfoWindow = new google.maps.InfoWindow();
    contextMenu = new google.maps.InfoWindow();
    areaInfoWindow = new google.maps.InfoWindow();

    maxZoomService = new google.maps.MaxZoomService();

    loadSettings();

    updateGoogleSearchAllowed();

    if (user.id !== null) {
        getPoisByUserId(user.id);
        getRoutesByUserId(user.id);
        getAreasByUserId(user.id);
        getToursByUserId(user.id);
        enablePoiButton();
    } else {
        getPublicPois();
        getPublicRoutes();
        getPublicAreas();
        getPublicTours();
    }

    mapInitialized = true;

    google.maps.InfoWindow.prototype.isOpen = function() {
        var m = this.getMap();
        return (m !== null && typeof m !== "undefined");
    }

    map = new google.maps.Map(document.getElementById("map"), {
        zoom: initialZoom,
        mapTypeId: settings.maptype,
        zoomControl: false,
        cameraControl: false,
        mapTypeControl: false,
        scaleControl: false,
        streetViewControl: true,
        rotateControl: false,
        fullscreenControl: false,
        clickableIcons: true,
        mapTypeControlOptions: {
            style: google.maps.MapTypeControlStyle.DROPDOWN_MENU,
            position: google.maps.ControlPosition.TOP_LEFT,
            mapTypeIds: ["hybrid", "terrain", "satellite"],
        },
        mapId: "DEMO_MAP_ID", // ggfs. eigene MapID generieren (https://developers.google.com/maps/documentation/get-map-id?hl=de)
    });

    // A pending long-press (attachLongPressContextMenu() above) should not
    // fire if the user is actually panning the map, not holding still on
    // the marker/route/area it started on.
    google.maps.event.addListener(map, 'dragstart', function () {
        longPressPendingOverlay = null;
        longPressCancelled = true;
    });

    // A plain, invisible OverlayView whose only purpose is to expose
    // getProjection() once Maps has attached it - findLongPressTarget()
    // uses it to convert between screen pixels and LatLng.
    overlayProjection = new google.maps.OverlayView();
    overlayProjection.draw = function () {};
    overlayProjection.setMap(map);

    initLongPressTouchTracking();

    autocompleteService = new google.maps.places.AutocompleteService();
    placesService = new google.maps.places.PlacesService(map);
    searchInfoWindow = new google.maps.InfoWindow({
        pixelOffset: new google.maps.Size(16, 16) // nudge the remove-X to the marker's upper right instead of straight above it
    });
    searchInfoWindow.addListener('closeclick', clearSearchMarker);

    const searchInput = document.getElementById('mapSearchInput');
    searchInput.addEventListener('input', handleSearchInput);
    searchInput.addEventListener('focus', handleSearchInput);
    searchInput.addEventListener('blur', () => setTimeout(hideSearchDropdown, 150));

    // Clicking a mode tab moves focus away from the input, which would
    // otherwise queue the blur handler's hideSearchDropdown() and wipe out
    // the tab switch a moment later. preventDefault on mousedown stops the
    // input from ever blurring for this click, same as the list items.
    document.querySelectorAll('#mapSearchModeToggle .map-search-mode-btn').forEach((btn) => {
        btn.addEventListener('mousedown', (event) => event.preventDefault());
    });

    injectAdditionalScripts();

    measureTool = new RouteTool(map, {
        contextMenu: false,
        unit: MeasureTool.UnitTypeId.METRIC,
        index: null
    });

    if (zoomParam == null) {
        if (Number.isInteger(settings['zoom'])) {
            map.setZoom(settings['zoom']);
        }
    } else {
        map.setZoom(initialZoom);
    }

    if (initialLatLng == null) {
        if (settings['center'] !== null) {
            map.setCenter(settings['center']);
        } else {
            fitToPoiBounds();
        }
    } else {
        map.setCenter(initialLatLng);
    }

    google.maps.event.addListener(map.getStreetView(), 'visible_changed', function(){
        if(this.getVisible() == true) {
            hideMenu();
            hideEditToolbar();
        } else {
            unhideMenu();
            unhideEditToolbar();
        }
    });

    map.addListener('center_changed', () => {
        log('center changed: ' + map.getCenter(), LOG_DEBUG);
        saveSettings();
    });

    map.addListener('zoom_changed', () => {
        log('zoom changed: ' + map.getZoom(), LOG_DEBUG);
        saveSettings();
        renewVisibleRouteLabels();
        updatePoiClustering();
    });

    // Desktop equivalent of findLongPressTarget()'s touch fallback below -
    // Maps only fires 'rightclick' on the map itself when the click isn't
    // on a marker/polyline/polygon (those have their own 'rightclick'/
    // 'contextmenu' listeners, wired up separately by poi.js/route.js/
    // area.js), so this needs no hit-testing of its own.
    map.addListener('rightclick', (event) => {
        showMapContextMenu(event.latLng);
    });

    map.setClickableIcons(true);

    myPositionMarker = new google.maps.Marker({
        position: {lat: 0, lng: 0},
        icon: new google.maps.MarkerImage('markers/location.svg', null, null, null, new google.maps.Size(30,30)),
        map: map
    });
    myPositionMarker.setVisible(false);

    initWeatherWidget(); // weather.js - registers its "Weather data for this location" map context menu item
}

function panToGeolocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const pos = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                };

                myPositionMarker.setPosition(pos);
                myPositionMarker.setVisible(true);

                map.setCenter(pos);
            },
            () => {
                handleLocationError(true, infoWindow, map.getCenter());
            },
            geolocationOptions
        );
    } else {
        handleLocationError(false, infoWindow, map.getCenter());
    }
}

function handleLocationError(browserHasGeolocation, infoWindow, pos) {
    infoWindow.setPosition(pos);
    infoWindow.setContent(
      browserHasGeolocation
        ? "Error: The Geolocation service failed."
        : "Error: Your browser doesn't support geolocation."
    );
    infoWindow.open(map);
}

/**
 * Re-evaluates whether Google search is allowed for the current session.
 * Called from initMap() and again from loginUser()/logoutUser() (user.js),
 * since a "Google search requires login" admin restriction (site-settings)
 * must take effect immediately on logout, not just on next page load.
 */
function updateGoogleSearchAllowed() {
    googleSearchAllowed = !window.YTAN_GOOGLE_SEARCH_REQUIRES_LOGIN || user.id !== null;
}

/**
 * Search dropdown combining own-POI matches (filtered client-side from the
 * already-loaded `pois` array) and Google Places predictions, fetched via
 * the data-only AutocompleteService rather than the SearchBox widget so we
 * can render both lists ourselves behind a single mode toggle instead of
 * fighting Google's self-positioned pac-container for screen space.
 */
function handleSearchInput() {
    const query = document.getElementById('mapSearchInput').value.trim();

    ownPoiMatches = query.length >= 2
        ? pois.filter(poi => poi.name && foldSearchText(poi.name).includes(foldSearchText(query))).slice(0, 8)
        : [];

    clearTimeout(searchDebounceTimer);

    if (!googleSearchAllowed || query.length < SEARCH_GOOGLE_MIN_LENGTH) {
        googlePredictions = [];
        lastQueriedGoogleText = null;
        renderSearchDropdown();
        return;
    }

    if (query === lastQueriedGoogleText) {
        // Same text as the last successful Google query - e.g. refocusing
        // the input without having typed anything new. Reuse the cached
        // predictions instead of spending another billed Autocomplete call.
        renderSearchDropdown();
        return;
    }

    searchDebounceTimer = setTimeout(() => {
        // A session token bundles every Autocomplete call for this query
        // plus the eventual Place Details call into a single billed
        // session (Google's recommended way to cut Places costs), instead
        // of billing each keystroke and the details call separately. It's
        // created lazily here (once per search session) and cleared again
        // once the session ends - see resetSearchState().
        if (!searchSessionToken) {
            searchSessionToken = new google.maps.places.AutocompleteSessionToken();
        }

        autocompleteService.getPlacePredictions(
            { input: query, bounds: map.getBounds(), sessionToken: searchSessionToken },
            (predictions, status) => {
                googlePredictions = (status === google.maps.places.PlacesServiceStatus.OK && predictions) ? predictions : [];
                lastQueriedGoogleText = query;
                renderSearchDropdown();
            }
        );
    }, 200);
}

function setSearchMode(mode) {
    searchMode = mode;
    renderSearchDropdown();
}

function renderSearchDropdown() {
    const dropdown = document.getElementById('mapSearchDropdown');
    const tabBar = document.getElementById('mapSearchModeToggle');
    const list = document.getElementById('mapSearchResultsList');

    const hasOwn = ownPoiMatches.length > 0;
    const hasGoogle = googlePredictions.length > 0;

    if (!hasOwn && !hasGoogle) {
        dropdown.hidden = true;
        searchMode = 'google';
        return;
    }

    // The tab bar only makes sense as a choice between two non-empty lists
    // - with only one side populated (e.g. Google search disabled for this
    // session, or the query is too short for Google), just show that list
    // directly instead of a toggle with an empty option on the other side.
    const showTabBar = hasOwn && hasGoogle;

    if (!showTabBar) {
        searchMode = hasOwn ? 'own' : 'google';
    }

    tabBar.hidden = !showTabBar;

    const googleBtn = tabBar.querySelector('[data-mode="google"]');
    const ownBtn = tabBar.querySelector('[data-mode="own"]');
    googleBtn.classList.toggle('active', searchMode === 'google');
    ownBtn.classList.toggle('active', searchMode === 'own');
    ownBtn.textContent = 'POIs (' + ownPoiMatches.length + ')';

    list.innerHTML = '';

    if (searchMode === 'own') {
        ownPoiMatches.forEach(poi => {
            const item = document.createElement('li');

            const icon = document.createElement('img');
            icon.className = 'map-search-result-icon';
            icon.src = 'markers/poi_' + poi.poitype_id + '_mapicons_square16.png';
            icon.alt = '';
            item.appendChild(icon);

            const label = document.createElement('span');
            label.textContent = poi.name;
            item.appendChild(label);

            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                selectSearchPoi(poi.id);
            });
            list.appendChild(item);
        });
    } else {
        googlePredictions.forEach(prediction => {
            const item = document.createElement('li');

            const label = document.createElement('span');
            label.textContent = prediction.description;
            item.appendChild(label);

            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                selectGooglePrediction(prediction.place_id, prediction.description);
            });
            list.appendChild(item);
        });
    }

    dropdown.hidden = false;
}

/**
 * Purely visual: hides the dropdown without discarding the current
 * results/session state. Used for the input's blur handler, where the box
 * is only briefly unfocused (e.g. clicking a mode tab uses mousedown
 * preventDefault so it never even blurs) - keeping the cached predictions
 * and session token around lets a quick refocus with the same text skip
 * another billed Autocomplete call. Full session teardown is
 * resetSearchState().
 */
function hideSearchDropdown() {
    document.getElementById('mapSearchDropdown').hidden = true;
    document.getElementById('mapSearchResultsList').innerHTML = '';
}

/**
 * Ends the current search session: clears both result lists, the Google
 * query cache and the Autocomplete session token, so the next keystroke
 * starts a genuinely new (and separately billed) session rather than
 * reusing a stale one. Called when the search box is actually closed or a
 * result has been picked - not on a mere blur.
 */
function resetSearchState() {
    hideSearchDropdown();
    googlePredictions = [];
    ownPoiMatches = [];
    searchMode = 'google';
    searchSessionToken = null;
    lastQueriedGoogleText = null;
}

function selectGooglePrediction(placeId, description) {
    const sessionToken = searchSessionToken;

    resetSearchState();
    document.getElementById('mapSearchInput').value = description;

    placesService.getDetails({ placeId: placeId, fields: ['name', 'geometry'], sessionToken: sessionToken }, (place, status) => {
        if (status !== google.maps.places.PlacesServiceStatus.OK || !place || !place.geometry) return;
        showSearchResultOnMap(place);
    });
}

function showSearchResultOnMap(place) {
    clearSearchMarker();

    searchMarker = new google.maps.Marker({
        position: place.geometry.location,
        map: map,
        title: place.name
    });

    searchInfoWindow.setContent(
        '<div class="search-remove-badge" onclick="clearSearchMarker()" title="' + t('common.remove') + '"><i class="material-icons-round">close</i></div>'
    );
    searchInfoWindow.open(map, searchMarker);

    if (place.geometry.viewport) {
        map.fitBounds(place.geometry.viewport);
    } else {
        map.setCenter(place.geometry.location);
        map.setZoom(17);
    }
}

function clearSearchMarker() {
    if (searchMarker) {
        searchMarker.setMap(null);
        searchMarker = null;
    }
    searchInfoWindow.close();
}

function selectSearchPoi(poiId) {
    const i = pois.findIndex(poi => poi.id === poiId);
    if (i === -1) return;

    resetSearchState();
    document.getElementById('mapSearchInput').value = pois[i].name;

    zoomToMaxAndPan({ latLng: new google.maps.LatLng(pois[i].latitude, pois[i].longitude) });
    showPoiInfoWindow(null, i, markers[pois[i].id]);
}

function setMapOnAllMarkers(map) {
    markers.forEach(marker => marker.setMap(map));
}

function setMapOnAllWsiMarkers(map) {
    for (let i = 0; i < wsiMarkers.length; i++) {
        if (typeof wsiMarkers[i] !== "undefined") {
            wsiMarkers[i].setMap(map);
        }
    }
}

function setMapOnAllPortages(map) {
    for (let i = 0; i < portagePaths.length; i++) {
        if (typeof portagePaths[i] !== "undefined") {
            portagePaths[i].setMap(map);
        }
    }
}

function clearMarkers() {
    setMapOnAllMarkers(null);
}

function clearWsiMarkers() {
    setMapOnAllWsiMarkers(null);
}

function clearPortages() {
    setMapOnAllPortages(null);
}

function showWsiMarkers() {
    setMapOnAllWsiMarkers(map);
}

function toggleWsiMarkers(element) {
    if (element.checked) {
        showWsiMarkers();
    } else {
        clearWsiMarkers();
    }
}

function toggleRoutes(element) {
    if (element.checked) {
        showRoutes();
    } else {
        hideRoutes();
    }
    saveSettings();
}

function toggleAreas(element) {
    if (element.checked) {
        showAreas();
    } else {
        hideAreas();
    }
    saveSettings();
}

function toggleMarkerType(poitype_id) {
    for (let i = 0; i < pois.length; i++) {
        if (typeof pois[i] !== "undefined") {
            if (pois[i]['poitype_id'] == poitype_id) {
                if (markers[pois[i]['id']].getMap() === null) {
                    markers[pois[i]['id']].setMap(map);
                } else {
                    markers[pois[i]['id']].setMap(null);
                }
            }
        }
    }
}

function toggleMarkerByType(element, poitype_id) {
    for (let i = 0; i < pois.length; i++) {
        if (typeof pois[i] !== "undefined") {
            if (pois[i]['poitype_id'] == poitype_id) {
                if (element.checked) {
                    markers[pois[i]['id']].setMap(map);
                } else {
                    markers[pois[i]['id']].setMap(null);
                }
            }
        }
    }

    if (poitype_id == 9) {
        if (element.checked) {
            setMapOnAllPortages(map);
        } else {
            setMapOnAllPortages(null);
        }
    }

    saveSettings();
    updatePoiClustering();
}

/**
 * "Select all"/"Select none" quick actions above the POI-type list
 * (nav-menu.js "pois" screen) - only the 17 POI type toggles, not the
 * Routes/Areas/Windshelter Indicators toggles below them.
 */
function selectAllPois() {
    for (let i = 0; i <= 16; i++) {
        var element = document.getElementById('detail' + i);
        if (element && !element.checked) {
            element.checked = true;
            toggleMarkerByType(element, i);
        }
    }
}

function selectNonePois() {
    for (let i = 0; i <= 16; i++) {
        var element = document.getElementById('detail' + i);
        if (element && element.checked) {
            element.checked = false;
            toggleMarkerByType(element, i);
        }
    }
}

function editMapType(element) {
    if (['hybrid','terrain','satellite'].includes(element.value)) {
        map.setMapTypeId(element.value);
    }
    saveSettings();
}

const UNIT_EXAMPLE_DISTANCE_METERS = 12400;

function editUnit(element) {
    if ([METRIC, NAUTICAL].includes(element.value)) {
        settings.unit = element.value;
    }
    renewVisibleRouteLabels();
    updateUnitExample();
    saveSettings();
}

/**
 * Keeps the live example distance in the "Preferences" screen (nav-menu.js)
 * in sync with the current unit - called on toggle and once at boot.
 */
function updateUnitExample() {
    var el = document.getElementById('unitExampleValue');
    if (el) {
        el.textContent = formatDistance(UNIT_EXAMPLE_DISTANCE_METERS, settings.unit);
    }
}

/**
 * Switches the app's own Light/Dark appearance (Preferences submenu) -
 * independent of the operating system's color scheme, since the app has no
 * "follow system" mode. Applied via a data-theme attribute on <html> so
 * every CSS custom property in style.css re-resolves through the matching
 * :root[data-theme="dark"] block.
 */
function setTheme(theme) {
    if (![THEME_LIGHT, THEME_DARK].includes(theme)) {
        return;
    }
    settings.theme = theme;
    if (theme === THEME_DARK) {
        document.documentElement.dataset.theme = THEME_DARK;
    } else {
        delete document.documentElement.dataset.theme;
    }
    saveSettings();
}

/**
 * Unlike setTheme() (pure CSS, applied instantly via a data-theme
 * attribute), translations are baked server-side into the initial render
 * and the one window.YTAN_TRANSLATIONS blob (see templates/app.php,
 * src/Service/Translator.php) - re-translating every already-open panel
 * in place isn't attempted, a reload is simplest and safest (same
 * reload-based approach ui.js's revokeConsent() already uses).
 */
function setLanguage(lang) {
    if (!['en', 'de'].includes(lang)) {
        return;
    }
    settings.language = lang;
    saveSettings();
    window.location.reload();
}

function zoomToMaxAndPan(event) {
    maxZoomService.getMaxZoomAtLatLng(event.latLng, (result) => {
        if (result.status !== "OK") {
          return false;
        } else {
            map.panTo(event.latLng);
            map.setZoom(result.zoom);
        }
    });
}

/**
 * Disable certain map controls an clickable icons while editing routes, areas, pois etc.
 *
 * @param {boolean} mode
 */
function editMode(mode) {
    if (mode === true) {
        log('editMode ON', LOG_INFO);
        map.setOptions({
            streetViewControl: false,
            clickableIcons: false
        });
    } else {
        log('editMode OFF', LOG_INFO);
        map.setOptions({
            streetViewControl: true,
            clickableIcons: true
        });
    }
}

/**
 * Zum Teilen der Karte (aktueller Zoomlevel und die Mitte der Karte)
 */
function shareMap() {
    var zoom = map.getZoom();
    var center = map.getCenter();

    var mapUrl = window.location.protocol + '//' + window.location.hostname + window.location.pathname + '?z=' + zoom + '&lat=' + center.lat() + '&lng=' + center.lng();

    if (navigator.share) {
        navigator.share({
            title: 'YTAN',
            text: t('map.share_text'),
            url: mapUrl,
        })
            .then(() => log('shareMap() - successfull', LOG_INFO))
            .catch((error) => log('shareMap() - error', LOG_ERROR, error));
    } else {
        copyTextToClipboard(mapUrl);
        showToast(t('map.link_copied'), 'success');
    }
}

/**
 * Liefert ein leeres User-Objekt zurück.
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
        pending_email: null,
        pending_email_expires_at: null
    }
}
