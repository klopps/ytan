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

let mapClickListener;
let maxZoomService;

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

    map.setClickableIcons(true);

    myPositionMarker = new google.maps.Marker({
        position: {lat: 0, lng: 0},
        icon: new google.maps.MarkerImage('markers/location.svg', null, null, null, new google.maps.Size(30,30)),
        map: map
    });
    myPositionMarker.setVisible(false);
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
        ? pois.filter(poi => poi.name && poi.name.toLowerCase().includes(query.toLowerCase())).slice(0, 8)
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
            item.textContent = poi.name;
            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                selectSearchPoi(poi.id);
            });
            list.appendChild(item);
        });
    } else {
        googlePredictions.forEach(prediction => {
            const item = document.createElement('li');
            item.textContent = prediction.description;
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
        '<div class="search-remove-badge" onclick="clearSearchMarker()" title="Entfernen"><i class="material-icons-round">close</i></div>'
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
 * Keeps the live example distance in the "Map Settings" screen (nav-menu.js)
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
            text: 'Check out this map on YTAN',
            url: mapUrl,
        })
            .then(() => log('shareMap() - successfull', LOG_INFO))
            .catch((error) => log('shareMap() - error', LOG_ERROR, error));
    } else {
        copyTextToClipboard(mapUrl);
        showToast('Link copied to clipboard', 'success');
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
        pending_email: null,
        pending_email_expires_at: null
    }
}
