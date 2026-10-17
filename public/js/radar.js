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
const RADAR_FADE_MS = 350; // crossfade between frames (a hard switch flickers: ~30% of pixels change per step)
const RADAR_PLAY_INTERVAL_MS = 700;
const RADAR_PRELOAD = 3;
const RADAR_REFRESH_MS = 5 * 60 * 1000;
const RADAR_STALE_MINUTES = 30;

let radarManifest = null;
let radarFrames = []; // manifest.frames: {id, time, type, lead_minutes?}
let radarFrameIndex = 0;
let radarLayers = new Map(); // frame id -> ImageMapType currently in map.overlayMapTypes
let radarLoaded = new Set(); // frame ids whose tiles for the current view are loaded and decoded
let radarPreloadView = ''; // view (zoom + tile range) radarLoaded refers to
let radarShownId = null; // frame id whose layer is visible right now
let radarShowTimer = null;
let radarFade = null; // running crossfade {from, to, start, raf}
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
    radarMapListeners.push(map.addListener('idle', onRadarViewChanged));
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
    if (radarFade) {
        cancelAnimationFrame(radarFade.raf);
        radarFade = null;
    }
    syncRadarLayers([]);
    radarShownId = null;
    radarPreloadView = '';
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
            preloadRadarFrame(frame);
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
    // the visible frame stays a layer until the crossfade away from it is done
    syncRadarLayers(radarShownId && radarLayers.has(radarShownId) ? ids.concat([radarShownId]) : ids);
    clearTimeout(radarShowTimer);
    if (keepShown) {
        // a tile that never answers must not stall the radar
        radarShowTimer = setTimeout(() => showRadarLayerOf(target), 2000);
    } else {
        showRadarLayerOf(target);
    }
    updateRadarControls();
}

/**
 * Load and decode a frame's tiles for the current view with plain Image
 * objects (same URLs as its ImageMapType, so the layer then gets them from
 * the browser cache - tiles are immutable). Google's own 'tilesloaded'
 * event on an ImageMapType is unreliable: measured, some preloaded layers
 * never fired it, and each of those steps stalled until the timeout.
 */
function preloadRadarFrame(frame) {
    const view = radarCurrentView();
    if (!view) {
        return;
    }
    const urls = radarViewTileUrls(frame, view);
    Promise.all(urls.map((url) => new Promise((resolve) => {
        const img = new Image();
        img.onload = () => img.decode().catch(() => {}).then(resolve);
        img.onerror = resolve; // a missing tile mustn't block the frame
        img.src = url;
    }))).then(() => {
        if (view.key === radarPreloadView && radarLayers.has(frame.id)) {
            onRadarLayerLoaded(frame.id);
        }
    });
}

/** Visible tile range at the current zoom, clipped to the radar area. */
function radarCurrentView() {
    const bounds = map.getBounds();
    if (!bounds || !radarManifest) {
        return null;
    }
    const zoom = Math.round(map.getZoom());
    if (zoom < radarManifest.zoom.min) {
        return { key: 'z' + zoom, zoom: zoom, x0: 1, x1: 0, y0: 1, y1: 0 };
    }
    const n = Math.pow(2, zoom);
    const tx = (lng) => Math.floor((lng + 180) / 360 * n);
    const ty = (lat) => {
        const s = Math.sin(lat * Math.PI / 180);
        return Math.floor((0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * n);
    };
    const r = radarTileRange(zoom);
    const ne = bounds.getNorthEast();
    const sw = bounds.getSouthWest();
    const v = {
        zoom: zoom,
        x0: Math.max(tx(sw.lng()), r.x0), x1: Math.min(tx(ne.lng()), r.x1),
        y0: Math.max(ty(ne.lat()), r.y0), y1: Math.min(ty(sw.lat()), r.y1),
    };
    v.key = [zoom, v.x0, v.x1, v.y0, v.y1].join('/');
    radarPreloadView = radarPreloadView || v.key;
    return v;
}

function radarViewTileUrls(frame, view) {
    const base = (window.YTAN_API_BASE || '/api/v1') + '/radar/tiles/' + frame.id + '/' + view.zoom + '/';
    const urls = [];
    for (let x = view.x0; x <= view.x1; x++) {
        for (let y = view.y0; y <= view.y1; y++) {
            urls.push(base + x + '/' + y + '.png');
        }
    }
    return urls;
}

/** Map moved/zoomed: the preloaded tiles no longer cover the view. */
function onRadarViewChanged() {
    const view = radarCurrentView();
    if (!view || view.key === radarPreloadView) {
        return;
    }
    radarPreloadView = view.key;
    radarLoaded.clear();
    radarLayers.forEach((layer, id) => {
        const frame = radarFrames.find((f) => f.id === id);
        if (frame) {
            preloadRadarFrame(frame);
        }
    });
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
    finishRadarFade();
    const from = radarShownId;
    radarShownId = id;
    if (!from || from === id || !radarLayers.has(from) || document.hidden) {
        showOnlyRadarLayer(id);
        return;
    }
    radarFade = { from: from, to: id, start: performance.now(), raf: 0 };
    radarFade.raf = requestAnimationFrame(stepRadarFade);
}

/**
 * Crossfade: both layers' opacities are chosen so that where both frames
 * have rain the combined opacity stays RADAR_OPACITY all the way
 * ((1 - a_from) * (1 - a_to) = 1 - RADAR_OPACITY) - a plain linear fade
 * would visibly lighten mid-way.
 */
function stepRadarFade(now) {
    const fade = radarFade;
    if (!fade) {
        return;
    }
    const p = Math.min(1, (now - fade.start) / RADAR_FADE_MS);
    const e = p * p * (3 - 2 * p); // smoothstep
    const rest = 1 - RADAR_OPACITY;
    const to = radarLayers.get(fade.to);
    const from = radarLayers.get(fade.from);
    if (to) {
        to.setOpacity(1 - Math.pow(rest, e));
    }
    if (from) {
        from.setOpacity(1 - Math.pow(rest, 1 - e));
    }
    if (p < 1) {
        fade.raf = requestAnimationFrame(stepRadarFade);
    } else {
        radarFade = null;
        showOnlyRadarLayer(fade.to);
    }
}

/** Jump a running crossfade to its end. */
function finishRadarFade() {
    if (radarFade) {
        cancelAnimationFrame(radarFade.raf);
        radarFade = null;
        showOnlyRadarLayer(radarShownId);
    }
}

function showOnlyRadarLayer(id) {
    radarLayers.forEach((layer, layerId) => layer.setOpacity(layerId === id ? RADAR_OPACITY : 0));
    // drop the previous frame (kept for loading/crossfading)
    const ids = [];
    for (let i = 0; i <= RADAR_PRELOAD && i < radarFrames.length; i++) {
        ids.push(radarFrames[(radarFrameIndex + i) % radarFrames.length].id);
    }
    if (radarShownId && !ids.includes(radarShownId)) {
        ids.push(radarShownId);
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
