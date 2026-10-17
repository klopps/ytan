/**
 * Rain radar overlay with nowcast, switched on/off from the map context menu
 * ("Show/hide rain radar") and off by the x on its panel; the state is
 * settings.detailradar (saved with the other settings, no drawer switch).
 * Frames and tiles come from our own backend
 * (RadarController: GET /radar/manifest, /radar/tiles/{frame}/{z}/{x}/{y}.png),
 * produced every 5 minutes by the Python job in tools/radar/ from EUMETNET
 * OPERA composites - observations of the last 60 minutes plus an
 * extrapolation forecast up to +120 minutes (see wetterradar.md).
 *
 * The first attempt (RainViewer, removed 2026-09-14) only had data up to
 * zoom 7; our tiles go to zoom 8 and the backend enlarges them beyond.
 *
 * Animation without flicker: every frame is its own ImageMapType, switched
 * by opacity. Only the current frame and the next RADAR_PRELOAD ones exist
 * as layers at any time (a phone would otherwise hold ~37 x 15 decoded
 * tiles); the immutable Cache-Control on tiles makes the second loop
 * come from the HTTP cache.
 */

const RADAR_OPACITY = 0.65;
const RADAR_PLAY_INTERVAL_MS = 700;
const RADAR_PRELOAD = 3;
const RADAR_REFRESH_MS = 5 * 60 * 1000;
const RADAR_STALE_MINUTES = 30;

let radarManifest = null;
let radarFrames = []; // manifest.frames: {id, time, type, lead_minutes?}
let radarFrameIndex = 0;
let radarLayers = new Map(); // frame id -> ImageMapType currently in map.overlayMapTypes
let radarLoaded = new Set(); // frame ids whose layer has loaded its tiles at least once
let radarShownId = null; // frame id whose layer is visible right now
let radarShowTimer = null;
let radarActive = false;
let radarPlayTimer = null;
let radarRefreshTimer = null;
let radarMapListeners = [];

function initRadar() {
    registerMapContextMenuItem('radar', 'radar.context_menu.toggle_item', radarContextMenuToggle);
    window.addEventListener('online', updateRadarOnlineState);
    window.addEventListener('offline', updateRadarOnlineState);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopRadarPlayback();
        } else if (radarActive) {
            loadRadarManifest();
        }
    });
    if (settings.detailradar && navigator.onLine) {
        showRadarLayer();
    }
}

/** Switch the radar on/off and remember it (settings cookie). */
function setRadarEnabled(enabled) {
    settings.detailradar = enabled;
    if (enabled) {
        showRadarLayer();
    } else {
        hideRadarLayer();
    }
    saveSettings();
}

function radarContextMenuToggle() {
    if (!settings.detailradar && !navigator.onLine) {
        showToast(t('radar.offline'));
        return;
    }
    setRadarEnabled(!settings.detailradar);
}

/** The x in the radar panel. */
function closeRadarLayer() {
    setRadarEnabled(false);
}

/** Offline: hide the layer but keep it switched on, it comes back online. */
function updateRadarOnlineState() {
    if (!navigator.onLine && radarActive) {
        hideRadarLayer();
    } else if (navigator.onLine && settings.detailradar && !radarActive) {
        showRadarLayer();
    }
}

function showRadarLayer() {
    radarActive = true;
    document.getElementById('radarControls').style.display = '';
    radarMapListeners.push(map.addListener('idle', updateRadarCoverageHint));
    loadRadarManifest();
    radarRefreshTimer = setInterval(loadRadarManifest, RADAR_REFRESH_MS);
}

function hideRadarLayer() {
    radarActive = false;
    stopRadarPlayback();
    clearInterval(radarRefreshTimer);
    radarRefreshTimer = null;
    radarMapListeners.forEach((l) => google.maps.event.removeListener(l));
    radarMapListeners = [];
    clearTimeout(radarShowTimer);
    syncRadarLayers([]);
    radarShownId = null;
    document.getElementById('radarControls').style.display = 'none';
}

function loadRadarManifest() {
    return Ytan.get('/radar/manifest')
        .then((answer) => {
            if (!radarActive) {
                return;
            }
            const previous = radarFrames[radarFrameIndex];
            const wasAtNewest = !previous || radarFrameIndex === newestObservationIndex(radarFrames);
            radarManifest = answer.data;
            radarFrames = radarManifest.frames || [];
            if (radarFrames.length === 0) {
                return;
            }
            // Stay on the newest observation unless the user picked something else.
            let index = newestObservationIndex(radarFrames);
            if (!wasAtNewest) {
                const same = radarFrames.findIndex((f) => f.time === previous.time && f.type === previous.type);
                if (same >= 0) {
                    index = same;
                }
            }
            renderRadarLegend();
            setRadarFrame(index);
            updateRadarCoverageHint();
        })
        .catch((err) => {
            log('loadRadarManifest() failed', LOG_ERROR, err);
            if (radarActive && radarFrames.length === 0) {
                showToast(t('radar.load_failed'));
                setRadarEnabled(false);
            }
        });
}

function newestObservationIndex(frames) {
    let index = 0;
    frames.forEach((f, i) => {
        if (f.type === 'observation') {
            index = i;
        }
    });
    return index;
}

function radarTileRange(zoom) {
    const b = radarManifest.bounds;
    const n = Math.pow(2, zoom);
    const x = (lng) => Math.floor((lng + 180) / 360 * n);
    const y = (lat) => {
        const s = Math.sin(lat * Math.PI / 180);
        return Math.floor((0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * n);
    };
    return { x0: x(b.west), x1: x(b.east), y0: y(b.north), y1: y(b.south) };
}

function radarLayerFor(frame) {
    const base = (window.YTAN_API_BASE || '/api/v1') + '/radar/tiles/' + frame.id + '/';
    const minZoom = radarManifest.zoom.min;
    return new google.maps.ImageMapType({
        getTileUrl: (coord, zoom) => {
            if (zoom < minZoom) {
                return null;
            }
            const r = radarTileRange(zoom);
            if (coord.x < r.x0 || coord.x > r.x1 || coord.y < r.y0 || coord.y > r.y1) {
                return null; // outside the radar area: no request at all
            }
            return base + zoom + '/' + coord.x + '/' + coord.y + '.png';
        },
        tileSize: new google.maps.Size(256, 256),
        opacity: 0,
        name: 'radar-' + frame.id,
    });
}

/** Keep exactly the layers of `ids` in map.overlayMapTypes. */
function syncRadarLayers(ids) {
    for (const [id, layer] of radarLayers) {
        if (!ids.includes(id)) {
            const pos = map.overlayMapTypes.getArray().indexOf(layer);
            if (pos >= 0) {
                map.overlayMapTypes.removeAt(pos);
            }
            radarLayers.delete(id);
            radarLoaded.delete(id);
        }
    }
    ids.forEach((id) => {
        if (!radarLayers.has(id)) {
            const frame = radarFrames.find((f) => f.id === id);
            const layer = radarLayerFor(frame);
            layer.addListener('tilesloaded', () => onRadarLayerLoaded(id));
            map.overlayMapTypes.push(layer);
            radarLayers.set(id, layer);
        }
    });
}

function setRadarFrame(index) {
    if (radarFrames.length === 0) {
        return;
    }
    radarFrameIndex = Math.max(0, Math.min(radarFrames.length - 1, index));
    const ids = [];
    for (let i = 0; i <= RADAR_PRELOAD && i < radarFrames.length; i++) {
        ids.push(radarFrames[(radarFrameIndex + i) % radarFrames.length].id);
    }
    const target = ids[0];
    // Keep the frame on screen until the new one has its tiles - otherwise a
    // jump to a frame that isn't preloaded shows an empty map for a moment.
    const keepShown = radarShownId && radarShownId !== target && !radarLoaded.has(target)
        && radarLayers.has(radarShownId);
    syncRadarLayers(keepShown ? ids.concat([radarShownId]) : ids);
    clearTimeout(radarShowTimer);
    if (keepShown) {
        // tilesloaded may never come (e.g. map not idle); don't wait forever
        radarShowTimer = setTimeout(() => showRadarLayerOf(target), 2000);
    } else {
        showRadarLayerOf(target);
    }
    updateRadarControls();
}

function onRadarLayerLoaded(id) {
    radarLoaded.add(id);
    if (radarFrames[radarFrameIndex] && radarFrames[radarFrameIndex].id === id && radarShownId !== id) {
        clearTimeout(radarShowTimer);
        showRadarLayerOf(id);
    }
}

function showRadarLayerOf(id) {
    if (!radarFrames[radarFrameIndex] || radarFrames[radarFrameIndex].id !== id) {
        return; // the user moved on meanwhile
    }
    radarShownId = id;
    radarLayers.forEach((layer, layerId) => layer.setOpacity(layerId === id ? RADAR_OPACITY : 0));
    // drop a previous frame that was only kept visible while this one loaded
    const ids = [];
    for (let i = 0; i <= RADAR_PRELOAD && i < radarFrames.length; i++) {
        ids.push(radarFrames[(radarFrameIndex + i) % radarFrames.length].id);
    }
    syncRadarLayers(ids);
}

function updateRadarControls() {
    const slider = document.getElementById('radarFrameSlider');
    slider.max = String(radarFrames.length - 1);
    slider.value = String(radarFrameIndex);

    // Observation part of the track neutral, forecast part tinted.
    const firstForecast = radarFrames.findIndex((f) => f.type === 'forecast');
    const split = firstForecast < 0 ? 100 : (firstForecast - 0.5) / Math.max(1, radarFrames.length - 1) * 100;
    slider.style.setProperty('--radar-split', split.toFixed(1) + '%');

    const frame = radarFrames[radarFrameIndex];
    const time = new Date(frame.time);
    const hhmm = time.toLocaleTimeString(radarLocale(), { hour: '2-digit', minute: '2-digit' });
    const label = document.getElementById('radarControlsTime');
    const tag = document.getElementById('radarControlsTag');
    label.textContent = hhmm;
    if (frame.type === 'forecast') {
        tag.textContent = t('radar.forecast_tag', { min: frame.lead_minutes });
        tag.className = 'radar-controls-tag radar-controls-tag-forecast';
    } else {
        tag.textContent = t('radar.observation_tag');
        tag.className = 'radar-controls-tag';
    }

    const latest = new Date(radarManifest.latest_observation);
    const ageMinutes = (Date.now() - latest.getTime()) / 60000;
    const status = document.getElementById('radarControlsStatus');
    const stale = ageMinutes > RADAR_STALE_MINUTES;
    status.textContent = stale
        ? t('radar.stale')
        : t('radar.as_of', { time: latest.toLocaleTimeString(radarLocale(), { hour: '2-digit', minute: '2-digit' }) });
    status.classList.toggle('radar-controls-status-stale', stale);
}

function radarLocale() {
    return window.YTAN_LOCALE || undefined;
}

function renderRadarLegend() {
    const legend = document.getElementById('radarLegend');
    const fmt = (v) => v.toLocaleString(radarLocale());
    legend.innerHTML = radarManifest.legend.map((c) =>
        '<span class="radar-legend-step"><span class="radar-legend-swatch" style="background:' + escapeHTML(c.color) + '"></span>'
        + '<span class="radar-legend-value">' + escapeHTML(fmt(c.mm_h)) + '</span></span>'
    ).join('') + '<span class="radar-legend-unit">mm/h</span>';
}

function updateRadarCoverageHint() {
    if (!radarManifest || !map.getBounds()) {
        return;
    }
    const b = radarManifest.bounds;
    const covered = map.getBounds().intersects(new google.maps.LatLngBounds(
        { lat: b.south, lng: b.west }, { lat: b.north, lng: b.east }));
    const tooFar = map.getZoom() < radarManifest.zoom.min;
    const hint = document.getElementById('radarCoverageHint');
    hint.textContent = !covered ? t('radar.outside_area') : (tooFar ? t('radar.zoom_in_hint') : '');
    hint.style.display = (!covered || tooFar) ? '' : 'none';
}

function onRadarFrameSliderInput(element) {
    stopRadarPlayback();
    setRadarFrame(parseInt(element.value, 10));
}

function toggleRadarPlayback() {
    if (radarPlayTimer) {
        stopRadarPlayback();
    } else {
        startRadarPlayback();
    }
}

function startRadarPlayback() {
    if (radarFrames.length < 2) {
        return;
    }
    document.getElementById('radarPlayPauseIcon').textContent = 'pause';
    document.getElementById('radarPlayPauseBtn').setAttribute('aria-label', t('radar.pause_aria_label'));
    radarPlayTimer = setInterval(() => {
        if (radarShownId !== radarFrames[radarFrameIndex].id) {
            return; // still loading: wait instead of running ahead of the image
        }
        setRadarFrame((radarFrameIndex + 1) % radarFrames.length);
    }, RADAR_PLAY_INTERVAL_MS);
}

function stopRadarPlayback() {
    if (radarPlayTimer) {
        clearInterval(radarPlayTimer);
        radarPlayTimer = null;
    }
    const icon = document.getElementById('radarPlayPauseIcon');
    const btn = document.getElementById('radarPlayPauseBtn');
    if (icon) {
        icon.textContent = 'play_arrow';
    }
    if (btn) {
        btn.setAttribute('aria-label', t('radar.play_aria_label'));
    }
}
