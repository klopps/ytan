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
 * Drawing: one <canvas> in an OverlayView (map pane, above the base map),
 * into which the current frame's tiles are drawn with drawImage. Earlier
 * versions used one ImageMapType per frame and switched them by opacity;
 * on desktop that was clean, but the Android app flickered badly: Maps sets
 * the opacity on every single tile <img> (and has its own 200 ms tile
 * transitions), and Chrome on Android decodes images that become visible
 * asynchronously, so tiles showed up empty for a moment at each switch. Now
 * tiles are loaded and decoded as Image objects before a frame is shown,
 * and a switch replaces the canvas content in one go. Crossfade = drawing
 * both frames with globalAlpha.
 */

const RADAR_OPACITY = 0.65;
const RADAR_FADE_MS = 350; // crossfade between frames (a hard switch flickers: ~30% of pixels change per step)
const RADAR_PLAY_INTERVAL_MS = 700;
const RADAR_PRELOAD = 3;
const RADAR_REFRESH_MS = 5 * 60 * 1000;
const RADAR_STALE_MINUTES = 30;
const RADAR_CANVAS_MARGIN = 128; // px drawn beyond each map edge, so dragging doesn't show a bare edge at once
const RADAR_TILE_CACHE_MAX = 300; // hard cap on decoded tiles (256 KB each); pruneRadarTiles() keeps far fewer

let radarManifest = null;
let radarFrames = []; // manifest.frames: {id, time, type, lead_minutes?}
let radarFrameIndex = 0;
let radarOverlay = null; // OverlayView holding the canvas
let radarTiles = new Map(); // tile url -> {img, done, ok, promise}; Map order = LRU order
let radarShownId = null; // frame id currently drawn
let radarShowTimer = null;
let radarFade = null; // running crossfade {from, to, start, progress, raf}
let radarDrawQueued = false;
let radarViewKey = '';
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
    if (enabled && settings.detailwind) {
        setWindEnabled(false); // same panel spot; wind.js does the same the other way round
    }
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
    if (radarActive) {
        return;
    }
    radarActive = true;
    document.getElementById('radarControls').style.display = '';
    radarOverlay = createRadarOverlay();
    radarOverlay.setMap(map);
    radarMapListeners.push(map.addListener('idle', updateRadarCoverageHint));
    radarMapListeners.push(map.addListener('bounds_changed', onRadarViewChanged));
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
    if (radarOverlay) {
        radarOverlay.setMap(null);
        radarOverlay = null;
    }
    radarTiles.clear();
    radarShownId = null;
    radarViewKey = '';
    document.getElementById('radarControls').style.display = 'none';
}

/** Created lazily: google.maps isn't loaded yet when this file is parsed. */
function createRadarOverlay() {
    class RadarCanvasOverlay extends google.maps.OverlayView {
        onAdd() {
            this.canvas = document.createElement('canvas');
            this.canvas.className = 'radar-canvas';
            this.getPanes().mapPane.appendChild(this.canvas);
        }

        draw() {
            // Maps calls this when the projection changes (after a zoom, a re-based pane): the view
            // may have changed with it, so the tiles it needs are preloaded here too
            onRadarViewChanged();
        }

        onRemove() {
            this.canvas.remove();
            this.canvas = null;
        }
    }
    return new RadarCanvasOverlay();
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

function radarLngToTileX(lng, n) {
    return (lng + 180) / 360 * n;
}

function radarLatToTileY(lat, n) {
    const s = Math.sin(lat * Math.PI / 180);
    return (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * n;
}

function radarTileLatLng(x, y, n) {
    const lat = Math.atan(Math.sinh(Math.PI * (1 - 2 * y / n))) * 180 / Math.PI;
    return new google.maps.LatLng(lat, x / n * 360 - 180);
}

function radarTileRange(zoom) {
    const b = radarManifest.bounds;
    const n = Math.pow(2, zoom);
    return {
        x0: Math.floor(radarLngToTileX(b.west, n)), x1: Math.floor(radarLngToTileX(b.east, n)),
        y0: Math.floor(radarLatToTileY(b.north, n)), y1: Math.floor(radarLatToTileY(b.south, n)),
    };
}

/**
 * The area the canvas covers (the map plus RADAR_CANVAS_MARGIN on every
 * side) and the tiles needed for it at the current zoom, clipped to the
 * radar area. Scale and zoom come from the overlay's projection, the area
 * from the map centre (see the comment in the body: getZoom()/getBounds()
 * are rounded after a pinch). Both always belong together, so even right
 * after a zoom change, while the projection still has the old zoom, the
 * view is consistent (the earlier mix of new bounds and old projection
 * asked for 17 x 23 tiles at zoom 12). Null until the map and the overlay
 * are ready.
 */
function radarCurrentView() {
    const projection = radarOverlay && radarOverlay.getProjection();
    if (!projection || !map.getCenter() || !radarManifest) {
        return null;
    }
    const div = map.getDiv();
    // Scale from the PROJECTION, view from the map CENTRE: after a pinch the map is drawn at a
    // fractional zoom while map.getZoom() and map.getBounds() still answer for a ROUNDED zoom
    // (measured: setZoom(7.5) -> getZoom() 8, bounds too narrow) - the canvas then missed strips
    // at the screen edges and tiles were missing in them. The tile zoom stays an integer; the
    // tiles are drawn scaled to the real scale by drawRadarFrame().
    const a = projection.fromLatLngToDivPixel(new google.maps.LatLng(0, 0));
    const b = projection.fromLatLngToDivPixel(new google.maps.LatLng(0, 90));
    const scale = (b.x - a.x) * 4; // world size in px at the real zoom
    if (!(scale > 0)) {
        return null;
    }
    const zoom = Math.round(Math.log2(scale / 256));
    const n = Math.pow(2, zoom);
    const center = map.getCenter();
    // visible area plus the margin, in world pixels at the real scale -> tile coordinates at the tile zoom
    const cx = (center.lng() + 180) / 360 * scale;
    const sinLat = Math.sin(center.lat() * Math.PI / 180);
    const cy = (0.5 - Math.log((1 + sinLat) / (1 - sinLat)) / (4 * Math.PI)) * scale;
    const halfW = div.clientWidth / 2 + RADAR_CANVAS_MARGIN;
    const halfH = div.clientHeight / 2 + RADAR_CANVAS_MARGIN;
    const toTile = n / scale; // world px at the real scale -> tile units at the tile zoom (256 px per tile)
    const fx0 = (cx - halfW) * toTile;
    const fx1 = (cx + halfW) * toTile;
    const fy0 = (cy - halfH) * toTile;
    const fy1 = (cy + halfH) * toTile;
    const view = { zoom: zoom, n: n, nw: radarTileLatLng(fx0, fy0, n), se: radarTileLatLng(fx1, fy1, n),
        x0: 1, x1: 0, y0: 1, y1: 0 }; // empty tile range
    if (zoom >= radarManifest.zoom.min && div.clientWidth > 0) {
        const r = radarTileRange(zoom);
        view.x0 = Math.max(Math.floor(fx0), r.x0);
        view.x1 = Math.min(Math.floor(fx1), r.x1);
        view.y0 = Math.max(Math.floor(fy0), r.y0);
        view.y1 = Math.min(Math.floor(fy1), r.y1);
        // guard against an inconsistent moment: never more than a few screens of tiles
        const maxTiles = 4 * Math.ceil(div.clientWidth / 256 + 2) * Math.ceil(div.clientHeight / 256 + 2);
        if ((view.x1 - view.x0 + 1) * (view.y1 - view.y0 + 1) > maxTiles) {
            return null;
        }
    }
    view.key = [zoom, view.x0, view.x1, view.y0, view.y1].join('/');
    return view;
}

function radarTileUrl(frameId, z, x, y) {
    return (window.YTAN_API_BASE || '/api/v1') + '/radar/tiles/' + frameId + '/' + z + '/' + x + '/' + y + '.png';
}

/** Loaded-and-decoded tile from the LRU cache, starting the load if needed. */
function radarTile(url) {
    let tile = radarTiles.get(url);
    if (tile) {
        radarTiles.delete(url); // move to the end = most recently used
        radarTiles.set(url, tile);
        return tile;
    }
    tile = { img: new Image(), done: false, ok: false, promise: null };
    tile.promise = new Promise((resolve) => {
        tile.img.onload = () => tile.img.decode().catch(() => {}).then(() => {
            tile.done = true;
            tile.ok = true;
            resolve();
        });
        tile.img.onerror = () => { // a missing tile mustn't block the frame
            tile.done = true;
            resolve();
        };
    });
    tile.img.src = url;
    radarTiles.set(url, tile);
    while (radarTiles.size > RADAR_TILE_CACHE_MAX) {
        radarTiles.delete(radarTiles.keys().next().value);
    }
    return tile;
}

function radarFrameTiles(frameId, view) {
    const tiles = [];
    for (let x = view.x0; x <= view.x1; x++) {
        for (let y = view.y0; y <= view.y1; y++) {
            if (radarTileInRegions(x, y, view.n)) {
                tiles.push({ x: x, y: y, tile: radarTile(radarTileUrl(frameId, view.zoom, x, y)) });
            }
        }
    }
    return tiles;
}

/**
 * The radar regions (admin settings, manifest.regions): rectangles the job
 * computes. Older manifests without them: the enclosing bounds.
 */
function radarRegions() {
    return (radarManifest.regions && radarManifest.regions.length) ? radarManifest.regions : [radarManifest.bounds];
}

/** Whether a tile touches any region - tiles in between are empty, no request for them. */
function radarTileInRegions(x, y, n) {
    const west = x / n * 360 - 180;
    const east = (x + 1) / n * 360 - 180;
    const north = Math.atan(Math.sinh(Math.PI * (1 - 2 * y / n))) * 180 / Math.PI;
    const south = Math.atan(Math.sinh(Math.PI * (1 - 2 * (y + 1) / n))) * 180 / Math.PI;
    return radarRegions().some((r) => r.west <= east && r.east >= west && r.south <= north && r.north >= south);
}

function radarFrameReady(frameId, view) {
    return radarFrameTiles(frameId, view).every((t) => t.tile.done);
}

/** Start loading the current frame and the next RADAR_PRELOAD ones. */
function preloadRadarFrames(view) {
    const ids = [];
    for (let i = 0; i <= RADAR_PRELOAD && i < radarFrames.length; i++) {
        const id = radarFrames[(radarFrameIndex + i) % radarFrames.length].id;
        ids.push(id);
        Promise.all(radarFrameTiles(id, view).map((t) => t.tile.promise)).then(() => onRadarFrameLoaded(id));
    }
    pruneRadarTiles(view, ids);
}

/**
 * Drop decoded tiles nobody needs any more: everything except this view's
 * tiles of the given frames and of the frame(s) on screen. Each tile is
 * 256 KB decoded; on a phone the loop through 37 frames would otherwise
 * pile up 100 MB. Going round again comes from the HTTP cache (tiles are
 * immutable), only the decoding repeats.
 */
function pruneRadarTiles(view, ids) {
    const keep = new Set();
    ids.concat([radarShownId, radarFade && radarFade.from]).forEach((id) => {
        if (!id) {
            return;
        }
        for (let x = view.x0; x <= view.x1; x++) {
            for (let y = view.y0; y <= view.y1; y++) {
                keep.add(radarTileUrl(id, view.zoom, x, y));
            }
        }
    });
    for (const url of Array.from(radarTiles.keys())) {
        if (!keep.has(url)) {
            radarTiles.delete(url);
        }
    }
}

function setRadarFrame(index) {
    if (radarFrames.length === 0) {
        return;
    }
    radarFrameIndex = Math.max(0, Math.min(radarFrames.length - 1, index));
    updateRadarControls();
    const view = radarCurrentView();
    if (!view) {
        return; // overlay not ready yet; its first draw() shows the frame
    }
    radarViewKey = view.key;
    preloadRadarFrames(view);
    const target = radarFrames[radarFrameIndex].id;
    clearTimeout(radarShowTimer);
    if (!radarShownId || radarFrameReady(target, view)) {
        showRadarFrame(target);
    } else {
        // Keep the current frame on screen while the new one loads; a tile
        // that never answers must not stall the radar.
        radarShowTimer = setTimeout(() => showRadarFrame(target), 2000);
    }
}

function onRadarFrameLoaded(id) {
    const current = radarFrames[radarFrameIndex];
    if (current && current.id === id && radarShownId !== id) {
        clearTimeout(radarShowTimer);
        showRadarFrame(id);
    } else if (id === radarShownId) {
        scheduleRadarDraw(); // tiles of the visible frame arrived (e.g. after panning)
    }
}

function showRadarFrame(id) {
    const current = radarFrames[radarFrameIndex];
    if (!current || current.id !== id) {
        return; // the user moved on meanwhile
    }
    if (radarFade) {
        cancelAnimationFrame(radarFade.raf);
        radarFade = null;
    }
    const from = radarShownId;
    radarShownId = id;
    if (!from || from === id || document.hidden) {
        drawRadar();
        return;
    }
    radarFade = { from: from, to: id, start: performance.now(), progress: 0, raf: 0 };
    drawRadar();
    radarFade.raf = requestAnimationFrame(stepRadarFade);
}

function stepRadarFade(now) {
    const fade = radarFade;
    if (!fade) {
        return;
    }
    fade.progress = Math.min(1, (now - fade.start) / RADAR_FADE_MS);
    if (fade.progress < 1) {
        drawRadar();
        fade.raf = requestAnimationFrame(stepRadarFade);
    } else {
        radarFade = null;
        drawRadar();
    }
}

/** Map moved or zoomed: redraw (next animation frame) and load what the new view needs. */
function onRadarViewChanged() {
    scheduleRadarDraw();
    const view = radarCurrentView();
    if (view && view.key !== radarViewKey) {
        radarViewKey = view.key;
        preloadRadarFrames(view);
    }
}

function scheduleRadarDraw() {
    if (radarDrawQueued) {
        return;
    }
    radarDrawQueued = true;
    requestAnimationFrame(() => {
        radarDrawQueued = false;
        drawRadar();
    });
}

/**
 * Paint the canvas: position it over the current view, then the shown
 * frame - or, during a crossfade, both frames. Their alphas are chosen so
 * that where both have rain the result stays RADAR_OPACITY
 * ((1 - a_from) * (1 - a_to) = 1 - RADAR_OPACITY); a linear fade would
 * visibly lighten mid-way.
 */
function drawRadar() {
    const canvas = radarOverlay && radarOverlay.canvas;
    const view = radarCurrentView();
    if (!canvas || !view) {
        return;
    }
    if (!radarShownId && radarFrames.length > 0) {
        setRadarFrame(radarFrameIndex); // the overlay just got its projection
        if (!radarShownId) {
            return;
        }
    }
    const projection = radarOverlay.getProjection();
    const nwPx = projection.fromLatLngToDivPixel(view.nw);
    const sePx = projection.fromLatLngToDivPixel(view.se);
    const left = Math.round(nwPx.x);
    const top = Math.round(nwPx.y);
    const width = Math.round(sePx.x) - left;
    const height = Math.round(sePx.y) - top;
    if (width <= 0 || height <= 0 || width > 8192 || height > 8192) {
        return; // projection not caught up with the zoom yet; draw() follows
    }
    const dpr = window.devicePixelRatio || 1;
    canvas.style.left = left + 'px';
    canvas.style.top = top + 'px';
    canvas.style.width = width + 'px';
    canvas.style.height = height + 'px';
    const w = Math.round(width * dpr);
    const h = Math.round(height * dpr);
    if (canvas.width !== w || canvas.height !== h) {
        canvas.width = w;
        canvas.height = h;
    }
    const ctx = canvas.getContext('2d');
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (!radarShownId) {
        return;
    }
    ctx.setTransform(dpr, 0, 0, dpr, -left * dpr, -top * dpr);
    if (radarFade) {
        const p = radarFade.progress;
        const e = p * p * (3 - 2 * p); // smoothstep
        const rest = 1 - RADAR_OPACITY;
        drawRadarFrame(ctx, view, radarFade.from, 1 - Math.pow(rest, 1 - e));
        drawRadarFrame(ctx, view, radarFade.to, 1 - Math.pow(rest, e));
    } else {
        drawRadarFrame(ctx, view, radarShownId, RADAR_OPACITY);
    }
}

function drawRadarFrame(ctx, view, frameId, alpha) {
    const projection = radarOverlay.getProjection();
    ctx.globalAlpha = alpha;
    radarFrameTiles(frameId, view).forEach((t) => {
        if (!t.tile.ok) {
            return;
        }
        const nw = projection.fromLatLngToDivPixel(radarTileLatLng(t.x, t.y, view.n));
        const se = projection.fromLatLngToDivPixel(radarTileLatLng(t.x + 1, t.y + 1, view.n));
        ctx.drawImage(t.tile.img, nw.x, nw.y, se.x - nw.x, se.y - nw.y);
    });
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
    label.classList.toggle('radar-controls-time-forecast', frame.type === 'forecast');
    label.parentNode.style.setProperty('--radar-pos', String(radarFrames.length > 1 ? radarFrameIndex / (radarFrames.length - 1) : 0));
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
    const view = map.getBounds();
    const covered = radarRegions().some((r) => view.intersects(new google.maps.LatLngBounds(
        { lat: r.south, lng: r.west }, { lat: r.north, lng: r.east })));
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
