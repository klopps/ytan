/**
 * Optional rain-radar overlay layer, toggled from the drawer's POIs screen
 * (#detailradar, next to the Wind Shelter Indicator toggle - see
 * templates/app.php). Uses RainViewer (rainviewer.com), free and keyless:
 * confirmed live (curl) that https://api.rainviewer.com/public/weather-maps.json
 * sends `Access-Control-Allow-Origin: *`, so it's fetched directly from the
 * browser - unlike weather.js/GeocodingService, this deliberately has NO
 * backend proxy, since there's no key to protect and no rate limit to
 * shield, and the actual tile images (hundreds of small PNG requests while
 * panning/zooming) would have to go straight from the browser to
 * tilecache.rainviewer.com regardless - proxying just the small metadata
 * call would only add latency for no benefit.
 *
 * Only the last ~2 hours of REAL past radar are available for free
 * (confirmed live: the public endpoint's "nowcast" array is empty and
 * undocumented as a free tier) - this is a playback of what already
 * happened, not a forecast. Loaded right after weather.js, before poi.js -
 * same slot every self-contained map-feature module gets, needs the
 * global `map` from map-core.js.
 *
 * RainViewer's free tiles only carry real data up to RADAR_MAX_ZOOM (verified
 * live via curl+md5sum: z<=7 returns distinct, real PNGs, z>=8 returns a
 * byte-identical "Zoom Level Not Supported" placeholder image for every
 * coordinate). google.maps.ImageMapType's own `maxZoom` option does NOT
 * clamp/overzoom the zoom passed into getTileUrl (verified live with an
 * instrumented layer - Maps still calls it with the real, closer-in zoom),
 * so that clamping has to happen here: getTileUrl() returns null past
 * RADAR_MAX_ZOOM (Maps shows no tile at all for that coordinate rather than
 * the placeholder), and a zoom_changed listener swaps the transport controls
 * for a "zoom out to see radar" hint while the map is too close in.
 */

const RADAR_METADATA_URL = 'https://api.rainviewer.com/public/weather-maps.json';
const RADAR_TILE_SIZE = 256;
const RADAR_COLOR_SCHEME = 2; // RainViewer's "Universal Blue" scheme - their own default
const RADAR_TILE_OPTIONS = '1_1'; // smoothing on, snow-color on
const RADAR_OPACITY = 0.75;
const RADAR_PLAY_INTERVAL_MS = 800;
const RADAR_MAX_ZOOM = 7;

let radarFrames = []; // [{time: <unix seconds>, path: <string>}], oldest first
let radarHost = '';
let radarFrameIndex = 0;
let radarLayerActive = false; // whether the ImageMapType is currently inserted into map.overlayMapTypes
let radarPlaying = false;
let radarPlayTimer = null;
let radarFetchPromise = null; // in-flight/last fetch, so rapid on/off toggling doesn't refetch
let radarZoomListener = null;

/**
 * Called from templates/app.php's #detailradar onchange. Mirrors
 * toggleRoutes()/toggleAreas() (map-core.js) - calls saveSettings() itself,
 * unlike the pre-existing (inconsistent) toggleWsiMarkers().
 */
function toggleRadarLayer(element) {
    if (element.checked) {
        showRadarLayer();
    } else {
        hideRadarLayer();
    }
    saveSettings();
}

function showRadarLayer() {
    document.getElementById('radarControls').style.display = 'flex';
    updateRadarZoomHintUI();
    if (!radarZoomListener) {
        radarZoomListener = map.addListener('zoom_changed', updateRadarZoomHintUI);
    }

    fetchRadarFrames().then(() => {
        if (radarFrames.length === 0) {
            log('showRadarLayer() got no radar frames', LOG_WARN);
            return;
        }
        // Only jump to the latest frame on a fresh load - if the layer was
        // already showing an older frame (e.g. re-enabled mid-scrub is not
        // possible today since hiding always stops playback, but keep this
        // guard cheap and correct regardless) don't override the user's pick.
        if (!radarLayerActive) {
            radarFrameIndex = radarFrames.length - 1;
        }
        setRadarFrame(radarFrameIndex);
    }).catch((err) => {
        log('showRadarLayer() failed to load radar frames', LOG_ERROR, err);
        hideRadarLayer();
        document.getElementById('detailradar').checked = false;
    });
}

function hideRadarLayer() {
    stopRadarPlayback();
    if (radarLayerActive) {
        map.overlayMapTypes.removeAt(0);
        radarLayerActive = false;
    }
    if (radarZoomListener) {
        google.maps.event.removeListener(radarZoomListener);
        radarZoomListener = null;
    }
    document.getElementById('radarControls').style.display = 'none';
}

/**
 * RainViewer's free tiles have no real data past RADAR_MAX_ZOOM (see the
 * top-of-file doc comment) - swap the play/scrub transport for a plain hint
 * while the map is zoomed in past that, instead of showing a broken/blank
 * layer with no explanation.
 */
function updateRadarZoomHintUI() {
    const tooClose = map.getZoom() > RADAR_MAX_ZOOM;
    document.getElementById('radarPlayPauseBtn').style.display = tooClose ? 'none' : 'flex';
    document.getElementById('radarFrameSlider').style.display = tooClose ? 'none' : '';
    document.getElementById('radarControlsTime').style.display = tooClose ? 'none' : '';
    document.getElementById('radarZoomHint').style.display = tooClose ? '' : 'none';
    if (tooClose) {
        document.getElementById('radarZoomHint').textContent = t('radar.zoom_hint');
    }
}

/**
 * @returns {Promise<void>} resolves once radarFrames/radarHost are populated
 */
function fetchRadarFrames() {
    if (radarFetchPromise) {
        return radarFetchPromise;
    }
    radarFetchPromise = fetch(RADAR_METADATA_URL)
        .then((response) => response.json())
        .then((data) => {
            radarHost = data.host;
            radarFrames = (data.radar && data.radar.past) || [];
            const slider = document.getElementById('radarFrameSlider');
            slider.max = String(Math.max(0, radarFrames.length - 1));
        })
        .catch((err) => {
            radarFetchPromise = null; // allow a retry on the next toggle-on
            throw err;
        });
    return radarFetchPromise;
}

/**
 * Swaps in a brand-new ImageMapType for the given frame - Maps doesn't
 * offer a reliable way to force existing tiles to re-fetch under a changed
 * getTileUrl closure, so a fresh instance per frame (replacing the old one
 * at the same overlayMapTypes index) is the robust way to animate frames.
 */
function setRadarFrame(index) {
    if (radarFrames.length === 0) {
        return;
    }
    radarFrameIndex = Math.max(0, Math.min(radarFrames.length - 1, index));
    const frame = radarFrames[radarFrameIndex];

    const layer = new google.maps.ImageMapType({
        getTileUrl: (coord, zoom) => zoom > RADAR_MAX_ZOOM ? null : radarHost + frame.path + '/' + RADAR_TILE_SIZE + '/' + zoom + '/' + coord.x + '/' + coord.y + '/' + RADAR_COLOR_SCHEME + '/' + RADAR_TILE_OPTIONS + '.png',
        tileSize: new google.maps.Size(RADAR_TILE_SIZE, RADAR_TILE_SIZE),
        opacity: RADAR_OPACITY,
        name: 'RainViewer',
    });

    if (radarLayerActive) {
        map.overlayMapTypes.setAt(0, layer);
    } else {
        map.overlayMapTypes.insertAt(0, layer);
        radarLayerActive = true;
    }

    updateRadarControlsUI();
}

function updateRadarControlsUI() {
    const slider = document.getElementById('radarFrameSlider');
    slider.value = String(radarFrameIndex);

    const frame = radarFrames[radarFrameIndex];
    const minutesAgo = Math.round((Date.now() / 1000 - frame.time) / 60);
    document.getElementById('radarControlsTime').textContent =
        minutesAgo <= 0 ? t('radar.time_now') : t('radar.time_minutes_ago', { min: minutesAgo });
}

function onRadarFrameSliderInput(element) {
    stopRadarPlayback(); // manual scrubbing takes over from auto-play
    setRadarFrame(parseInt(element.value, 10));
}

function toggleRadarPlayback() {
    if (radarPlaying) {
        stopRadarPlayback();
    } else {
        startRadarPlayback();
    }
}

function startRadarPlayback() {
    if (radarFrames.length < 2) {
        return; // nothing to animate
    }
    radarPlaying = true;
    document.getElementById('radarPlayPauseIcon').textContent = 'pause';
    document.getElementById('radarPlayPauseBtn').setAttribute('aria-label', t('radar.pause_aria_label'));
    radarPlayTimer = setInterval(() => {
        setRadarFrame((radarFrameIndex + 1) % radarFrames.length);
    }, RADAR_PLAY_INTERVAL_MS);
}

function stopRadarPlayback() {
    if (radarPlayTimer) {
        clearInterval(radarPlayTimer);
        radarPlayTimer = null;
    }
    radarPlaying = false;
    const icon = document.getElementById('radarPlayPauseIcon');
    const btn = document.getElementById('radarPlayPauseBtn');
    if (icon) {
        icon.textContent = 'play_arrow';
    }
    if (btn) {
        btn.setAttribute('aria-label', t('radar.play_aria_label'));
    }
}
