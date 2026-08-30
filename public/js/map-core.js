/**
 * Map bootstrap, global state, geolocation and top-level map settings.
 *
 * @author Christoph Steindorff
 */

const ICONSET = 'mapicons'; // [google, mapicons]
const METRIC = 'metric';
const NAUTICAL = 'nautical';
const SESSION_TIMEOUT_SECONDS = 43200; // 12 Stunden

var sessionTimeoutHandle = null;

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
    detailroutes: true, // Routes
    detailareas: true,  // Areas
    detailwsi: false,  // Wind Shelter Indicators
    maptype: "hybrid",
    center: null,
    zoom: null,
    unit: METRIC
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
        libraries: 'geometry',
        v: 'weekly'
    });

    document.getElementById("gdpr").style.display = "none";
    document.getElementById("map").style.display = "block";
    showToolbar();
    document.getElementById("sidemenu-toggle").style.display = "block";

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

function editMapType(element) {
    if (['hybrid','terrain','satellite'].includes(element.value)) {
        map.setMapTypeId(element.value);
    }
    saveSettings();
}

function editUnit(element) {
    if ([METRIC, NAUTICAL].includes(element.value)) {
        settings.unit = element.value;
    }
    renewVisibleRouteLabels();
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
        alert(mapUrl); // Fallback
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
        is_admin: false
    }
}
