/**
 * Wind layer: animated particles that follow the wind (faster = stronger)
 * over a faintly coloured wind-speed fill, switched on/off from the map
 * context menu ("Show/hide wind") and off by the x on its panel; the state
 * is settings.detailwind (saved with the other settings, no drawer switch).
 * Never on together with the rain radar - both use the same panel spot.
 *
 * Data: our own backend (WindController: GET /wind/manifest and the gzipped
 * blocks /wind/{run}/{L0|L1|L2}/{hour}/{bx_by}), produced by the Python job
 * in tools/wind/ from the DWD's ICON-D2/ICON-EU 10 m wind (see windkarte.md
 * for the block format). One hour = one set of blocks; the hour slider
 * switches between them.
 *
 * Keeping it cheap (it runs on phones, on battery):
 *  - the wind is sampled ONCE per map view onto a coarse screen grid
 *    (WIND_CELL px per cell) - the per-frame particle step is only reads
 *    from that grid, no projection and no block lookup;
 *  - all particles are drawn as ONE path with ONE stroke() per frame, trails
 *    fade by a single destination-in fill; 30 fps, canvas pixel ratio <= 1.5;
 *  - the colour fill is drawn only when the view or the hour changes, at
 *    cell resolution and enlarged by the browser;
 *  - the animation pauses while the map is dragged or zoomed, while the page
 *    is hidden or a full-screen panel is open, and stops after
 *    WIND_IDLE_STOP_MS without any map movement (the fill stays).
 * Both canvases live in one OverlayView in the map pane, so they pan with
 * the map by themselves and are re-placed on 'idle'.
 */

const WIND_CELL = 8; // px per grid cell of the screen grid
const WIND_MARGIN = 128; // px beyond each map edge, so panning doesn't show a bare edge at once
const WIND_BLOCK_TIMEOUT_MS = 10000;
const WIND_BLOCK_RETRIES = 3; // rebuilds after a failed block load, 3 s apart
const WIND_REBUILD_DELAY_MS = 150; // after the last bounds_changed: don't wait for 'idle' (it comes late while tiles load)
const WIND_FRAME_MS = 30; // ~30 fps: every second requestAnimationFrame at 60 Hz
const WIND_IDLE_STOP_MS = 60000;
const WIND_PX_PER_MS_FRAME = 0.25; // particle step in px per frame and m/s, independent of the zoom
const WIND_MAX_STEP_PX = 6;
const WIND_BLOCK_CACHE_MAX = 80; // blocks are ~17-33 KB each
const WIND_PLAY_INTERVAL_MS = 1200;
const WIND_REFRESH_MS = 15 * 60 * 1000;
const WIND_STALE_HOURS = 9;
const WIND_BLOCK_CELLS = 128;
const WIND_BLOCK_POINTS = 129;
const WIND_NODATA = 255;
const WIND_LEGEND_MS = [2, 4, 6, 8, 11, 14, 17, 21, 25];

let windManifest = null;
let windHours = []; // Date per manifest hour
let windHourIndex = 0;
let windActive = false;
let windOverlay = null; // OverlayView holding the two canvases
let windBlocks = new Map(); // block url -> {data: Uint8Array|null, done, promise}; Map order = LRU order
let windBlockSets = { L1: new Set(), L2: new Set() };
let windGrid = null; // {gw, gh, vx, vy, speed, width, height, left, top}
let windBuildSeq = 0;
let windBoundsTimer = 0;
let windBlockRetries = 0;
let windBuilding = false; // a build is waiting for its blocks
let windGuardRetries = 0;
let windWatchdogMismatch = null;
let windWatchdogTimer = 0;
let windDragging = false;
let windFirstBuildStarted = false; // the overlay's draw() starts the first build once; later ones come from 'idle'
let windMapListeners = [];
let windRefreshTimer = null;
let windPlayTimer = null;
let windRaf = 0;
let windLastFrame = 0;
let windLastActivity = 0;
let windParticles = null; // {n, x, y, age, life}
let windColorLut = null;

function initWind() {
    registerMapContextMenuItem('waves', 'wind.context_menu.toggle_item', windContextMenuToggle);
    window.addEventListener('online', updateWindOnlineState);
    window.addEventListener('offline', updateWindOnlineState);
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stopWindPlayback();
            windStopAnimation();
        } else if (windActive) {
            windTouchActivity();
            loadWindManifest();
        }
    });
    if (settings.detailwind && navigator.onLine) {
        showWindLayer();
    }
}

/** Switch the wind layer on/off and remember it (settings cookie). */
function setWindEnabled(enabled) {
    if (enabled && settings.detailradar) {
        setRadarEnabled(false); // same panel spot, and two animated overlays would be a lot
    }
    settings.detailwind = enabled;
    if (enabled) {
        showWindLayer();
    } else {
        hideWindLayer();
    }
    saveSettings();
}

function windContextMenuToggle() {
    if (!settings.detailwind && !navigator.onLine) {
        showToast(t('wind.offline'));
        return;
    }
    setWindEnabled(!settings.detailwind);
}

/** The x in the wind panel. */
function closeWindLayer() {
    setWindEnabled(false);
}

/** Offline: hide the layer but keep it switched on, it comes back online. */
function updateWindOnlineState() {
    if (!navigator.onLine && windActive) {
        hideWindLayer();
    } else if (navigator.onLine && settings.detailwind && !windActive) {
        showWindLayer();
    }
}

function showWindLayer() {
    if (windActive) {
        return;
    }
    windActive = true;
    windFirstBuildStarted = false;
    document.getElementById('windControls').style.display = '';
    windOverlay = createWindOverlay();
    windOverlay.setMap(map);
    windMapListeners.push(map.addListener('idle', onWindMapIdle));
    // 'idle' only comes once the map's tiles have loaded - seconds after a pan on a phone, during
    // which the canvases (the area they were built for) leave a bare strip at the screen edge.
    // So the view is rebuilt shortly after the map stops moving, not only on 'idle'.
    windMapListeners.push(map.addListener('bounds_changed', windScheduleRebuild));
    windMapListeners.push(map.addListener('dragstart', windOnDragStart));
    windMapListeners.push(map.addListener('dragend', windOnDragEnd));
    windMapListeners.push(map.addListener('zoom_changed', windPauseForZoom));
    windMapListeners.push(map.addListener('maptypeid_changed', updateWindParticleStyle));
    windMapListeners.push(map.addListener('click', windTouchActivity)); // a tap wakes a stopped animation
    loadWindManifest();
    windRefreshTimer = setInterval(loadWindManifest, WIND_REFRESH_MS);
    windWatchdogTimer = setInterval(windWatchdog, 2000);
}

function hideWindLayer() {
    windActive = false;
    stopWindPlayback();
    windStopAnimation();
    clearInterval(windRefreshTimer);
    windRefreshTimer = null;
    windMapListeners.forEach((l) => google.maps.event.removeListener(l));
    windMapListeners = [];
    windBuildSeq++;
    clearTimeout(windBoundsTimer);
    clearInterval(windWatchdogTimer);
    windDragging = false;
    windBuilding = false;
    if (windOverlay) {
        windOverlay.setMap(null);
        windOverlay = null;
    }
    windGrid = null;
    windParticles = null;
    windBlocks.clear();
    document.getElementById('windControls').style.display = 'none';
}

/** Created lazily: google.maps isn't loaded yet when this file is parsed. */
function createWindOverlay() {
    class WindCanvasOverlay extends google.maps.OverlayView {
        onAdd() {
            // colour fill below, particles above
            this.fill = document.createElement('canvas');
            this.fill.className = 'wind-canvas wind-canvas-fill';
            this.fill.style.opacity = String(windFillOpacity());
            this.particles = document.createElement('canvas');
            this.particles.className = 'wind-canvas';
            const pane = this.getPanes().mapPane;
            pane.appendChild(this.fill);
            pane.appendChild(this.particles);
        }

        draw() {
            // The projection is ready the first time this runs, which is when the first build can start.
            if (windActive && !windFirstBuildStarted && windManifest) {
                windRebuild();
            }
            // Maps calls draw() whenever it re-bases the pane's pixel origin (after a zoom or a long
            // pan): the canvases then have to be moved to where their geographic corner is now,
            // otherwise the wind sits displaced against the map until the next 'idle' rebuild.
            windRealign();
        }

        onRemove() {
            this.fill.remove();
            this.particles.remove();
            this.fill = null;
            this.particles = null;
        }
    }
    return new WindCanvasOverlay();
}

/* ---- manifest --------------------------------------------------------- */

/** Opacity of the colour fill, 0..1: the admin setting (/admin/wind, percent), 32 % by default. */
function windFillOpacity() {
    const percent = Number(window.YTAN_WIND_FILL_OPACITY);
    return Number.isFinite(percent) ? Math.min(100, Math.max(0, percent)) / 100 : 0.32;
}

function windApiBase() {
    return window.YTAN_API_BASE || '/api/v1';
}

function loadWindManifest() {
    return Ytan.get('/wind/manifest')
        .then((answer) => {
            if (!windActive) {
                return;
            }
            const manifest = answer.data;
            if (!manifest || !manifest.hours || manifest.hours.length === 0) {
                showToast(t('wind.not_available'));
                setWindEnabled(false);
                return;
            }
            const shownTime = windHours[windHourIndex] ? windHours[windHourIndex].getTime() : null;
            const newRun = !windManifest || windManifest.run !== manifest.run;
            windManifest = manifest;
            windHours = manifest.hours.map((h) => new Date(h));
            windBlockSets = {
                L1: new Set(manifest.levels.L1.blocks || []),
                L2: new Set(manifest.levels.L2.blocks || []),
            };
            // keep the hour the user looks at, otherwise the one that is "now"
            let index = shownTime === null ? -1 : windHours.findIndex((d) => d.getTime() === shownTime);
            if (index < 0) {
                index = windCurrentHourIndex();
            }
            if (newRun) {
                windBlocks.clear();
            }
            windHourIndex = index;
            updateWindControls();
            if (newRun || !windGrid) {
                windRebuild(true);
            }
        })
        .catch((err) => {
            log('loadWindManifest() failed', LOG_ERROR, err);
            if (windActive && !windManifest) {
                showToast(t('wind.load_failed'));
                setWindEnabled(false);
            }
        });
}

/** Index of the last hour that is not in the future (0 when the run starts later). */
function windCurrentHourIndex() {
    const now = Date.now();
    let index = 0;
    windHours.forEach((d, i) => {
        if (d.getTime() <= now) {
            index = i;
        }
    });
    return index;
}

/* ---- blocks ----------------------------------------------------------- */

function windPad3(n) {
    return String(n).padStart(3, '0');
}

function windBlockUrl(level, hour, block) {
    return '/wind/' + windManifest.run + '/' + level + '/' + windPad3(hour) + (block ? '/' + block : '');
}

/** Block data from the LRU cache, starting the load if needed (data is null until done, and when it failed). */
function windBlock(level, hour, block) {
    const url = windBlockUrl(level, hour, block);
    let entry = windBlocks.get(url);
    if (entry) {
        windBlocks.delete(url); // move to the end = most recently used
        windBlocks.set(url, entry);
        return entry;
    }
    entry = { data: null, done: false, promise: null };
    // a request that never answers (phone on a bad connection) must not keep a build waiting forever
    const timeout = new Promise((resolve, reject) => setTimeout(() => reject(new Error('timeout')), WIND_BLOCK_TIMEOUT_MS));
    entry.promise = Promise.race([Ytan.fetchBlob(url).then((blob) => blob.arrayBuffer()), timeout])
        .then((buffer) => {
            entry.data = new Uint8Array(buffer);
        })
        .catch(() => {
            entry.data = null; // a missing block must not block the view ...
            entry.failed = true;
            windBlocks.delete(url); // ... and is not remembered as "no data": the next build asks again
        })
        .then(() => {
            entry.done = true;
        });
    windBlocks.set(url, entry);
    while (windBlocks.size > WIND_BLOCK_CACHE_MAX) {
        windBlocks.delete(windBlocks.keys().next().value);
    }
    return entry;
}

/** Which data level serves a zoom: L0 overview, L1 (0.0625 degrees), plus L2 (0.02) up close. */
function windLevelsForZoom(zoom) {
    if (zoom <= 5) {
        return ['L0'];
    }
    return zoom >= 9 ? ['L2', 'L1'] : ['L1'];
}

/** The "bx_by" names of a level's blocks that are listed in the manifest and touch the box. */
function windBlocksInBox(level, box) {
    const blockDeg = WIND_BLOCK_CELLS * windManifest.levels[level].res;
    const out = [];
    const bx0 = Math.floor((box.west + 180) / blockDeg);
    const bx1 = Math.floor((box.east + 180) / blockDeg);
    const by0 = Math.floor((box.south + 90) / blockDeg);
    const by1 = Math.floor((box.north + 90) / blockDeg);
    for (let by = by0; by <= by1; by++) {
        for (let bx = bx0; bx <= bx1; bx++) {
            const name = bx + '_' + by;
            if (windBlockSets[level].has(name)) {
                out.push(name);
            }
        }
    }
    return out;
}

/** Every block the box needs for an hour at a zoom (entries of windBlock()). */
function windNeededBlocks(box, zoom, hour) {
    const entries = [];
    windLevelsForZoom(zoom).forEach((level) => {
        if (level === 'L0') {
            entries.push(windBlock('L0', hour, null));
        } else {
            windBlocksInBox(level, box).forEach((name) => entries.push(windBlock(level, hour, name)));
        }
    });
    return entries;
}

/* ---- sampling --------------------------------------------------------- */

/**
 * Bilinear u/v from interleaved bytes (rows south -> north, `channels` per point).
 * Corners without data are left out and the weights renormalised; with less
 * than half the weight left there is no value (keeps the region edges crisp).
 * Writes m/s into out.u/out.v (and out.g, the gust, when the data has it) and returns whether there is a value.
 */
function windBilinear(data, channels, cols, rows, fi, fj, out) {
    if (fi < 0 || fj < 0 || fi > cols - 1 || fj > rows - 1) {
        return false;
    }
    const i0 = Math.min(Math.floor(fi), cols - 2);
    const j0 = Math.min(Math.floor(fj), rows - 2);
    const fx = fi - i0;
    const fy = fj - j0;
    let wsum = 0;
    let su = 0;
    let sv = 0;
    let sg = 0;
    for (let dj = 0; dj < 2; dj++) {
        for (let di = 0; di < 2; di++) {
            const idx = ((j0 + dj) * cols + (i0 + di)) * channels;
            if (data[idx] === WIND_NODATA || data[idx + 1] === WIND_NODATA) {
                continue;
            }
            const w = (di ? fx : 1 - fx) * (dj ? fy : 1 - fy);
            wsum += w;
            su += w * data[idx];
            sv += w * data[idx + 1];
            sg += w * (channels > 2 && data[idx + 2] !== WIND_NODATA ? data[idx + 2] : data[idx]);
        }
    }
    if (wsum < 0.5) {
        return false;
    }
    out.u = (su / wsum) / 254 * 80 - 40;
    out.v = (sv / wsum) / 254 * 80 - 40;
    out.g = channels > 2 ? (sg / wsum) / 254 * 60 : 0; // gust, m/s (only with the third channel)
    return true;
}

/** u/v (m/s) at a position for an hour: L2 where it has data, else L1 / L0 by zoom. */
function windSampleAt(lat, lon, hour, zoom, out) {
    const channels = windManifest.channels;
    if (zoom <= 5) {
        const l0 = windManifest.levels.L0;
        if (!l0.rows) {
            return false;
        }
        const entry = windBlock('L0', hour, null);
        return !!entry.data && windBilinear(entry.data, channels, l0.cols, l0.rows,
            (lon - l0.west) / l0.res, (lat - l0.south) / l0.res, out);
    }
    const levels = zoom >= 9 ? ['L2', 'L1'] : ['L1'];
    for (let k = 0; k < levels.length; k++) {
        const level = levels[k];
        const res = windManifest.levels[level].res;
        const bx = Math.floor((lon + 180) / (WIND_BLOCK_CELLS * res));
        const by = Math.floor((lat + 90) / (WIND_BLOCK_CELLS * res));
        const name = bx + '_' + by;
        if (!windBlockSets[level].has(name)) {
            continue;
        }
        const entry = windBlock(level, hour, name);
        if (entry.data && windBilinear(entry.data, channels, WIND_BLOCK_POINTS, WIND_BLOCK_POINTS,
            (lon + 180) / res - bx * WIND_BLOCK_CELLS, (lat + 90) / res - by * WIND_BLOCK_CELLS, out)) {
            return true;
        }
    }
    return false;
}

/* ---- Mercator --------------------------------------------------------- */

/** World size in px at the map's current (possibly fractional) zoom, read from the overlay projection. */
function windProjectionScale(projection) {
    const a = projection.fromLatLngToDivPixel(new google.maps.LatLng(0, 0));
    const b = projection.fromLatLngToDivPixel(new google.maps.LatLng(0, 90));
    return (b.x - a.x) * 4;
}

function windWorldX(lng, scale) {
    return (lng + 180) / 360 * scale;
}

function windWorldY(lat, scale) {
    const s = Math.sin(lat * Math.PI / 180);
    return (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * scale;
}

function windLngAt(x, scale) {
    return x / scale * 360 - 180;
}

function windLatAt(y, scale) {
    const n = Math.PI - 2 * Math.PI * y / scale;
    return 180 / Math.PI * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
}

/* ---- building the screen grid ----------------------------------------- */

function onWindMapIdle() {
    // nothing moves any more: a drag that was turned into a pinch never reports its dragend
    windDragging = false;
    updateWindCoverageHint();
    windRebuild();
}

function windOnDragStart() {
    windDragging = true;
    clearTimeout(windBoundsTimer);
    windPauseForMapMove();
}

function windOnDragEnd() {
    windDragging = false;
    windScheduleRebuild();
}

/** Rebuild WIND_REBUILD_DELAY_MS after the last bounds_changed (not while a finger is dragging the map). */
function windScheduleRebuild() {
    clearTimeout(windBoundsTimer);
    if (windDragging) {
        return;
    }
    windBoundsTimer = setTimeout(() => windRebuild(), WIND_REBUILD_DELAY_MS);
}

/**
 * Place the canvases over the current view, load the blocks it needs, sample
 * the wind onto the screen grid, paint the colour fill and (re)start the
 * particles. A newer call makes an older one that is still loading give up. Without `force`
 * nothing happens when the view, the hour and the run are those of the grid that is already there.
 */
/** Identifies the view (run, hour, centre, scale, size) a grid was built for; null while the map is not ready. */
function windViewKey() {
    const projection = windOverlay && windOverlay.getProjection();
    const div = map.getDiv();
    const center = map.getCenter();
    if (!windManifest || !projection || !center || div.clientWidth === 0) {
        return null;
    }
    const scale = windProjectionScale(projection);
    if (!(scale > 0)) {
        return null;
    }
    return [windManifest.run, windHourIndex, center.lat().toFixed(5), center.lng().toFixed(5), scale.toFixed(1),
        div.clientWidth, div.clientHeight].join('|');
}

function windRebuild(force) {
    const projection = windOverlay && windOverlay.getProjection();
    const div = map.getDiv();
    if (!windActive) {
        return;
    }
    const key = windViewKey();
    if (key === null) {
        // map or projection not ready (right after a zoom, a resize): look again shortly, a few times
        if (windManifest && windGuardRetries++ < 20) {
            windScheduleRebuild();
        }
        return;
    }
    windGuardRetries = 0;
    windFirstBuildStarted = true;
    if (!force && windGrid && windGrid.key === key) {
        // 'idle' after a rebuild for this very view (or a pinch that ended where it began): the
        // fill may have been hidden for the zoom, show it again and wake the animation. A build
        // still in flight for ANOTHER view (zoomed out and back before it finished) must not land.
        windBuildSeq++;
        windBuilding = false;
        if (windOverlay.fill) {
            windOverlay.fill.style.opacity = String(windFillOpacity());
        }
        windTouchActivity();
        return;
    }
    const seq = ++windBuildSeq;
    windBuilding = true;
    // The scale comes from the projection, NOT from map.getZoom(): after a pinch the map is
    // drawn at a fractional zoom while getZoom() still answers a rounded one (measured: setZoom(6.63)
    // -> getZoom() 7, but 70.4 px per degree = 2^6.63). Computing positions from the rounded value
    // put the wind displaced against the map, growing with the distance from the canvas corner.
    const scale = windProjectionScale(projection);
    const zoom = Math.log2(scale / 256);
    // Anchor on the map CENTRE (it is the middle of the div), not on map.getBounds(): at a fractional
    // zoom the bounds are computed for the rounded zoom as well and are too narrow/wide.
    const center = map.getCenter();
    const centerPx = projection.fromLatLngToDivPixel(center);
    // div pixel = world pixel - offset (the projection's origin moves with the pane)
    const offX = windWorldX(center.lng(), scale) - centerPx.x;
    const offY = windWorldY(center.lat(), scale) - centerPx.y;
    const left = Math.round(centerPx.x - div.clientWidth / 2) - WIND_MARGIN;
    const top = Math.round(centerPx.y - div.clientHeight / 2) - WIND_MARGIN;
    const width = div.clientWidth + 2 * WIND_MARGIN;
    const height = div.clientHeight + 2 * WIND_MARGIN;
    const gw = Math.ceil(width / WIND_CELL);
    const gh = Math.ceil(height / WIND_CELL);

    const lons = new Float64Array(gw);
    const lats = new Float64Array(gh);
    for (let i = 0; i < gw; i++) {
        lons[i] = windLngAt(left + (i + 0.5) * WIND_CELL + offX, scale);
    }
    for (let j = 0; j < gh; j++) {
        lats[j] = windLatAt(top + (j + 0.5) * WIND_CELL + offY, scale);
    }
    const box = { west: lons[0], east: lons[gw - 1], north: lats[0], south: lats[gh - 1] };
    const hour = windHourIndex;

    const entries = windNeededBlocks(box, zoom, hour);
    Promise.all(entries.map((e) => e.promise || Promise.resolve())).then(() => {
        if (seq !== windBuildSeq || !windActive || !windOverlay || !windOverlay.fill) {
            return;
        }
        // The map may have moved or zoomed while the blocks loaded without this build having been
        // replaced (the next rebuild is still waiting for its timer): a grid for the old view must
        // not be put on the screen.
        if (windViewKey() !== key) {
            windBuilding = false;
            windScheduleRebuild();
            return;
        }
        const vx = new Float32Array(gw * gh);
        const vy = new Float32Array(gw * gh);
        const speed = new Float32Array(gw * gh);
        const gust = windManifest.channels > 2 ? new Float32Array(gw * gh) : null;
        const sample = { u: 0, v: 0, g: 0 };
        for (let j = 0; j < gh; j++) {
            for (let i = 0; i < gw; i++) {
                const k = j * gw + i;
                if (windSampleAt(lats[j], lons[i], hour, zoom, sample)) {
                    vx[k] = sample.u;
                    vy[k] = -sample.v; // screen y points down
                    speed[k] = Math.hypot(sample.u, sample.v);
                    if (gust) {
                        gust[k] = sample.g;
                    }
                } else {
                    vx[k] = vy[k] = speed[k] = NaN;
                    if (gust) {
                        gust[k] = NaN;
                    }
                }
            }
        }
        // a block that failed to load (flaky connection) left a hole: try again shortly, a few times
        if (entries.some((e) => e.failed) && windBlockRetries < WIND_BLOCK_RETRIES) {
            windBlockRetries++;
            setTimeout(() => windActive && windRebuild(true), 3000);
        } else if (!entries.some((e) => e.failed)) {
            windBlockRetries = 0;
        }
        windGrid = { gw: gw, gh: gh, vx: vx, vy: vy, speed: speed, gust: gust, width: width, height: height,
            // geographic position of the canvases' top-left corner (what windRealign() keeps in place)
            cornerLat: windLatAt(top + offY, scale), cornerLng: windLngAt(left + offX, scale), scale: scale, zoom: zoom, key: key };
        windPlaceCanvases(left, top, width, height);
        windPaintFill();
        windResetParticles();
        windBuilding = false;
        windTouchActivity();
        // the next hour is likely wanted next (slider / playback): fetch it quietly
        if (hour + 1 < windHours.length) {
            windNeededBlocks(box, zoom, hour + 1);
        }
    }).catch((err) => {
        // Whatever went wrong: never leave the layer hidden and stopped (it only came back with a page reload)
        log('windRebuild() failed', LOG_ERROR, err);
        if (seq === windBuildSeq) {
            windBuilding = false;
            windRecover();
        }
    });
}

/** After a failed build: show what there is again and look again in a moment. */
function windRecover() {
    if (!windActive || !windOverlay || !windOverlay.fill) {
        return;
    }
    windOverlay.fill.style.opacity = String(windFillOpacity());
    windTouchActivity();
    if (windGuardRetries++ < 20) {
        setTimeout(() => windActive && windRebuild(true), 1000);
    }
}

/**
 * Every 2 seconds while the layer is on: has it got stuck (a zoom that never got its rebuild, a build
 * that never finished)? The grid then no longer belongs to the view on the screen and nothing is
 * under way: build again; and the animation must be running whenever it is allowed to.
 */
function windWatchdog() {
    if (!windActive || !windManifest || windDragging || document.hidden) {
        return;
    }
    const key = windViewKey();
    if (key === null) {
        return;
    }
    if (!windGrid) {
        if (!windBuilding) {
            windRebuild(true);
        }
        return;
    }
    if (windGrid.key !== key) {
        // the same mismatch twice in a row = not just a gesture in progress
        if (windWatchdogMismatch === key && !windBuilding) {
            windWatchdogMismatch = null;
            windRebuild();
        } else {
            windWatchdogMismatch = key;
        }
        return;
    }
    windWatchdogMismatch = null;
    if (windOverlay && windOverlay.fill && windOverlay.fill.style.opacity === '0') {
        windOverlay.fill.style.opacity = String(windFillOpacity());
    }
    if (!windRaf && performance.now() - windLastActivity <= WIND_IDLE_STOP_MS) {
        windStartAnimation();
    }
}

/** Move the canvases so their top-left corner is again over the position it was built for. */
function windRealign() {
    const grid = windGrid;
    const projection = windOverlay && windOverlay.getProjection();
    if (!grid || !projection || !windOverlay.fill || Math.abs(windProjectionScale(projection) / grid.scale - 1) > 0.001) {
        return; // a zoom is under way: the canvases are hidden and the next build replaces them
    }
    const p = projection.fromLatLngToDivPixel(new google.maps.LatLng(grid.cornerLat, grid.cornerLng));
    [windOverlay.fill, windOverlay.particles].forEach((canvas) => {
        canvas.style.left = Math.round(p.x) + 'px';
        canvas.style.top = Math.round(p.y) + 'px';
    });
}

function windCanvasScale() {
    return Math.min(window.devicePixelRatio || 1, 1.5);
}

function windPlaceCanvases(left, top, width, height) {
    const s = windCanvasScale();
    [windOverlay.fill, windOverlay.particles].forEach((canvas) => {
        canvas.style.left = left + 'px';
        canvas.style.top = top + 'px';
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';
        canvas.width = Math.round(width * s);
        canvas.height = Math.round(height * s);
        canvas.getContext('2d').setTransform(s, 0, 0, s, 0, 0);
    });
    windOverlay.fill.style.opacity = String(windFillOpacity());
    updateWindParticleStyle();
}

/** Whether the colour fill shows gusts (only offered when the data has them; the particles stay the mean wind). */
function windShowsGusts() {
    return !!settings.windGusts && !!windManifest && windManifest.channels > 2 && !!windGrid && !!windGrid.gust;
}

function toggleWindGusts() {
    settings.windGusts = !settings.windGusts;
    saveSettings();
    updateWindControls();
    if (windGrid && windOverlay && windOverlay.fill) {
        windPaintFill();
    }
    windTouchActivity();
}

/** 0..60 m/s in steps of 0.5 -> [r, g, b], from the weather timeline's colour scale. */
function windColorFor(speedMs) {
    if (!windColorLut) {
        windColorLut = [];
        for (let k = 0; k <= 120; k++) {
            const m = /(\d+),\s*(\d+),\s*(\d+)/.exec(windSpeedColor(k * 0.5 * 3.6));
            windColorLut.push([+m[1], +m[2], +m[3]]);
        }
    }
    return windColorLut[Math.min(120, Math.round(speedMs * 2))];
}

/** The colour fill: one pixel per grid cell, enlarged (smoothed) by the browser. */
function windPaintFill() {
    const grid = windGrid;
    const canvas = windOverlay.fill;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, grid.width, grid.height);
    const small = document.createElement('canvas');
    small.width = grid.gw;
    small.height = grid.gh;
    const image = small.getContext('2d').createImageData(grid.gw, grid.gh);
    const values = windShowsGusts() ? grid.gust : grid.speed; // particles always follow the mean wind
    for (let k = 0; k < values.length; k++) {
        const sp = values[k];
        if (sp !== sp) {
            continue; // transparent
        }
        const rgb = windColorFor(sp);
        image.data[k * 4] = rgb[0];
        image.data[k * 4 + 1] = rgb[1];
        image.data[k * 4 + 2] = rgb[2];
        image.data[k * 4 + 3] = 255;
    }
    small.getContext('2d').putImageData(image, 0, 0);
    ctx.imageSmoothingEnabled = true;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(small, 0, 0, grid.gw, grid.gh, 0, 0, grid.gw * WIND_CELL, grid.gh * WIND_CELL);
}

/* ---- particles -------------------------------------------------------- */

function windParticleCount(width, height) {
    const max = window.innerWidth < 900 ? 2000 : 4000;
    return Math.min(max, Math.round(width * height / 500));
}

function windResetParticles() {
    const grid = windGrid;
    const n = windParticleCount(grid.width, grid.height);
    windParticles = {
        n: n, x: new Float32Array(n), y: new Float32Array(n),
        age: new Float32Array(n), life: new Float32Array(n),
    };
    for (let p = 0; p < n; p++) {
        windRespawn(p);
        windParticles.age[p] = Math.random() * windParticles.life[p]; // spread out, not all dying together
    }
    const ctx = windOverlay.particles.getContext('2d');
    ctx.clearRect(0, 0, grid.width, grid.height);
}

function windRespawn(p) {
    const grid = windGrid;
    const pt = windParticles;
    for (let tries = 0; tries < 4; tries++) {
        pt.x[p] = Math.random() * grid.width;
        pt.y[p] = Math.random() * grid.height;
        const k = Math.floor(pt.y[p] / WIND_CELL) * grid.gw + Math.floor(pt.x[p] / WIND_CELL);
        if (grid.speed[k] === grid.speed[k]) {
            break; // not NaN: there is wind here
        }
    }
    pt.age[p] = 0;
    pt.life[p] = 60 + Math.random() * 60;
}

/** White over the satellite images, dark over the terrain map and in the light theme. */
function windStrokeStyle() {
    const dark = document.documentElement.dataset.theme === 'dark';
    const type = map.getMapTypeId();
    return (dark || type === 'hybrid' || type === 'satellite') ? 'rgba(255,255,255,0.85)' : 'rgba(20,35,70,0.8)';
}

function updateWindParticleStyle() {
    if (windOverlay && windOverlay.particles) {
        windOverlay.particles.dataset.stroke = windStrokeStyle();
    }
}

function windStartAnimation() {
    if (windRaf || !windGrid || !windParticles || document.hidden) {
        return;
    }
    windRaf = requestAnimationFrame(windFrame);
}

function windStopAnimation() {
    if (windRaf) {
        cancelAnimationFrame(windRaf);
        windRaf = 0;
    }
}

/** Map movement or a tap on the panel: (re)start the animation and its idle timer. */
function windTouchActivity() {
    windLastActivity = performance.now();
    windStartAnimation();
}

function windClearParticles() {
    if (windOverlay && windOverlay.particles && windGrid) {
        windOverlay.particles.getContext('2d').clearRect(0, 0, windGrid.width, windGrid.height);
    }
}

function windPauseForMapMove() {
    windStopAnimation();
    windClearParticles();
}

/** A zoom also makes the colour fill wrong (wrong scale); the next build brings it back. */
function windPauseForZoom() {
    windDragging = false;
    windPauseForMapMove();
    if (windOverlay && windOverlay.fill) {
        windOverlay.fill.style.opacity = '0';
    }
}

function windFrame(now) {
    windRaf = requestAnimationFrame(windFrame);
    if (now - windLastFrame < WIND_FRAME_MS) {
        return; // 60 Hz screen: draw every second frame
    }
    windLastFrame = now;
    if (document.hidden || openPanelCount > 0) {
        // paused behind a full-screen panel (e.g. the weather forecast): that time must not count as
        // "no map movement", or the animation would stop for good the moment the panel closes
        windLastActivity = now;
        return;
    }
    if (now - windLastActivity > WIND_IDLE_STOP_MS) {
        windStopAnimation();
        windClearParticles(); // not frozen trails: the fill stays, the particles go
        return;
    }
    const grid = windGrid;
    const pt = windParticles;
    const ctx = windOverlay && windOverlay.particles && windOverlay.particles.getContext('2d');
    if (!grid || !pt || !ctx) {
        return;
    }
    ctx.globalCompositeOperation = 'destination-in';
    ctx.fillStyle = 'rgba(0,0,0,0.9)'; // trail length
    ctx.fillRect(0, 0, grid.width, grid.height);
    ctx.globalCompositeOperation = 'source-over';
    ctx.strokeStyle = windOverlay.particles.dataset.stroke || 'rgba(255,255,255,0.85)';
    ctx.lineWidth = 1.3;
    ctx.beginPath();

    const gw = grid.gw;
    const gh = grid.gh;
    for (let p = 0; p < pt.n; p++) {
        pt.age[p]++;
        if (pt.age[p] > pt.life[p]) {
            windRespawn(p);
            continue;
        }
        const x = pt.x[p];
        const y = pt.y[p];
        const gx = x / WIND_CELL - 0.5;
        const gy = y / WIND_CELL - 0.5;
        const i0 = Math.floor(gx);
        const j0 = Math.floor(gy);
        if (i0 < 0 || j0 < 0 || i0 >= gw - 1 || j0 >= gh - 1) {
            windRespawn(p);
            continue;
        }
        const fx = gx - i0;
        const fy = gy - j0;
        const k = j0 * gw + i0;
        const a = grid.vx[k];
        const b = grid.vx[k + 1];
        const c = grid.vx[k + gw];
        const d = grid.vx[k + gw + 1];
        if (a !== a || b !== b || c !== c || d !== d) {
            windRespawn(p); // left the data (NaN corner)
            continue;
        }
        const vx = (a * (1 - fx) + b * fx) * (1 - fy) + (c * (1 - fx) + d * fx) * fy;
        const vy = (grid.vy[k] * (1 - fx) + grid.vy[k + 1] * fx) * (1 - fy)
            + (grid.vy[k + gw] * (1 - fx) + grid.vy[k + gw + 1] * fx) * fy;
        let dx = vx * WIND_PX_PER_MS_FRAME;
        let dy = vy * WIND_PX_PER_MS_FRAME;
        const len = Math.hypot(dx, dy);
        if (len > WIND_MAX_STEP_PX) {
            dx *= WIND_MAX_STEP_PX / len;
            dy *= WIND_MAX_STEP_PX / len;
        }
        ctx.moveTo(x, y);
        ctx.lineTo(x + dx, y + dy);
        pt.x[p] = x + dx;
        pt.y[p] = y + dy;
    }
    ctx.stroke();
}

/* ---- panel ------------------------------------------------------------ */

function windLocale() {
    return window.YTAN_LOCALE || undefined;
}

function updateWindControls() {
    if (!windManifest) {
        return;
    }
    const slider = document.getElementById('windHourSlider');
    slider.max = String(windHours.length - 1);
    slider.value = String(windHourIndex);

    const time = windHours[windHourIndex];
    document.getElementById('windControlsTime').textContent =
        time.toLocaleString(windLocale(), { weekday: 'short', hour: '2-digit', minute: '2-digit' });
    document.getElementById('windControlsTime').parentNode.style.setProperty('--radar-pos', String(windHours.length > 1 ? windHourIndex / (windHours.length - 1) : 0));
    const lead = Math.round((time.getTime() - Date.now()) / 3600000);
    document.getElementById('windControlsTag').textContent = lead <= 0 ? t('wind.now_tag') : t('wind.lead_tag', { h: lead });

    // the model run the data comes from (start time, "2026101818" = 18 UTC)
    const run = windManifest.sources.eu;
    const modelTime = new Date(Date.UTC(+run.substr(0, 4), +run.substr(4, 2) - 1, +run.substr(6, 2), +run.substr(8, 2)));
    const stale = (Date.now() - modelTime.getTime()) / 3600000 > WIND_STALE_HOURS;
    const status = document.getElementById('windControlsStatus');
    status.textContent = stale
        ? t('wind.stale')
        : t('wind.as_of', { time: modelTime.toLocaleTimeString(windLocale(), { hour: '2-digit', minute: '2-digit' }) });
    status.classList.toggle('radar-controls-status-stale', stale);
    const gustBtn = document.getElementById('windGustBtn');
    gustBtn.style.display = windManifest.channels > 2 ? '' : 'none';
    gustBtn.classList.toggle('wind-gust-btn-on', !!settings.windGusts);
    gustBtn.setAttribute('aria-pressed', settings.windGusts ? 'true' : 'false');
    renderWindLegend();
    updateWindCoverageHint();
}

/** Colour swatches with the speeds in the unit the user chose (Beaufort, m/s, km/h, kn). */
function renderWindLegend() {
    const unit = settings.windUnit;
    document.getElementById('windLegend').innerHTML = WIND_LEGEND_MS.map((ms) =>
        '<span class="radar-legend-step"><span class="radar-legend-swatch" style="background:' + escapeHTML(windSpeedColor(ms * 3.6)) + '"></span>'
        + '<span class="radar-legend-value">' + escapeHTML(formatWindSpeedValue(ms * 3.6, unit)) + '</span></span>'
    ).join('') + '<span class="radar-legend-unit">' + escapeHTML(windUnitLabel(unit)) + '</span>';
}

function updateWindCoverageHint() {
    const hint = document.getElementById('windCoverageHint');
    const view = map.getBounds();
    if (!windManifest || !view) {
        hint.style.display = 'none';
        return;
    }
    const l0 = windManifest.levels.L0;
    const covered = l0 && l0.rows && view.intersects(new google.maps.LatLngBounds(
        { lat: l0.south, lng: l0.west },
        { lat: l0.south + (l0.rows - 1) * l0.res, lng: l0.west + (l0.cols - 1) * l0.res }));
    hint.textContent = covered ? '' : t('wind.outside_area');
    hint.style.display = covered ? 'none' : '';
}

function setWindHour(index) {
    if (!windManifest || index < 0 || index >= windHours.length) {
        return;
    }
    windHourIndex = index;
    updateWindControls();
    windRebuild(true);
}

function onWindHourSliderInput(element) {
    stopWindPlayback();
    setWindHour(parseInt(element.value, 10));
}

function toggleWindPlayback() {
    windTouchActivity();
    if (windPlayTimer) {
        stopWindPlayback();
    } else {
        startWindPlayback();
    }
}

function startWindPlayback() {
    if (windHours.length < 2) {
        return;
    }
    document.getElementById('windPlayPauseIcon').textContent = 'pause';
    document.getElementById('windPlayPauseBtn').setAttribute('aria-label', t('wind.pause_aria_label'));
    windPlayTimer = setInterval(() => {
        setWindHour((windHourIndex + 1) % windHours.length);
    }, WIND_PLAY_INTERVAL_MS);
}

function stopWindPlayback() {
    if (windPlayTimer) {
        clearInterval(windPlayTimer);
        windPlayTimer = null;
    }
    const icon = document.getElementById('windPlayPauseIcon');
    const btn = document.getElementById('windPlayPauseBtn');
    if (icon) {
        icon.textContent = 'play_arrow';
    }
    if (btn) {
        btn.setAttribute('aria-label', t('wind.play_aria_label'));
    }
}
