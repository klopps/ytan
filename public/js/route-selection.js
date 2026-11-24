/**
 * Selecting several waypoints of the line in the route editor and deleting
 * them together (todo.md "Mehrfachauswahl von Wegpunkten").
 *
 * MeasureTool is a vendored, minified library whose waypoints are SVG circles
 * - so the selection is an own layer: the selected waypoints are remembered by
 * COORDINATES (indexes shift whenever the line changes), drawn as rings in a
 * div over the map, and hit-tested from measureTool.points + the map
 * projection like routeSplitWaypointAt() (route.js).
 *
 * Desktop: Ctrl/Cmd+click on a waypoint toggles it, Ctrl/Cmd+drag on the empty
 * map draws a rectangle (Shift = add to the selection), Delete asks and
 * deletes, Escape or the x of the bar ends the selection. A plain click on the
 * empty map ends it too - but that click would add a waypoint to the line, so
 * it is swallowed (routeSelConsumeClick(), hooked into MeasureTool's click
 * handler).
 * Touch: the waypoint menu (long-press) offers "select several" = selection
 * MODE: tapping a waypoint toggles it, a tap on the empty map does nothing,
 * "select stretch" takes everything between two tapped waypoints.
 */

var routeSel = {
    coords: [],       // [{lat, lng}] of the selected waypoints
    mode: false,      // selection mode (touch menu entry / "select stretch"): taps toggle, empty map ignored
    range: false,     // waiting for the two ends of a stretch
    rangeFrom: null,  // index of the first end
    swallowUntil: 0,  // a click right after our own mouse action must not reach the line
    renderQueued: false,
    touch: null,      // {idx, x, y, moved} of the current tap on a waypoint
    rect: null        // {x0, y0, element, add} while a rectangle is dragged
};

const ROUTE_SEL_HIT_MOUSE_PX = 14;
const ROUTE_SEL_HIT_TOUCH_PX = 24;

function routeSelEditorActive() {
    var routeBtn = document.getElementById('routeButton');
    return !!routeBtn && routeBtn.classList.contains('active') && shapeEditorActive
        && typeof overlayProjection !== 'undefined' && !!overlayProjection && !!overlayProjection.getProjection();
}

function routeSelHas(point) {
    return routeSel.coords.some(function (c) { return autorouteSamePoint(c, point); });
}

/** Indexes (in the line as it is now) of the selected waypoints. */
function routeSelIndexes() {
    var indexes = [];
    (measureTool.points || []).forEach(function (p, k) {
        if (routeSelHas(p)) {
            indexes.push(k);
        }
    });
    return indexes;
}

/** Nearest waypoint (first and last included) within `radius` px of the screen position, or -1. */
function routeSelPointAt(clientX, clientY, radius) {
    if (!routeSelEditorActive()) {
        return -1;
    }
    var points = measureTool.points || [];
    var projection = overlayProjection.getProjection();
    var rect = map.getDiv().getBoundingClientRect();
    var best = -1;
    var bestDist = radius;
    for (var k = 0; k < points.length; k++) {
        var px = projection.fromLatLngToContainerPixel(new google.maps.LatLng(points[k].lat, points[k].lng));
        var dist = Math.hypot(px.x - (clientX - rect.left), px.y - (clientY - rect.top));
        if (dist <= bestDist) {
            bestDist = dist;
            best = k;
        }
    }
    return best;
}

/* ------------------------------------------------------------ the state */

function routeSelAdd(point) {
    if (!routeSelHas(point)) {
        routeSel.coords.push({ lat: point.lat, lng: point.lng });
    }
}

function routeSelToggle(index) {
    var point = measureTool.points[index];
    if (!point) {
        return;
    }
    if (routeSelHas(point)) {
        routeSel.coords = routeSel.coords.filter(function (c) { return !autorouteSamePoint(c, point); });
        if (routeSel.coords.length === 0) {
            routeSel.mode = false;
            routeSel.range = false;
            routeSel.rangeFrom = null;
        }
    } else {
        routeSelAdd(point);
    }
}

/** A click/tap on the waypoint `index`: toggles it, or sets an end of the stretch to select. */
function routeSelTap(index) {
    if (routeSel.range) {
        if (routeSel.rangeFrom === null) {
            routeSel.rangeFrom = index;
            showToast(t('route.selection.range_second'), 'info');
        } else {
            var from = Math.min(routeSel.rangeFrom, index);
            var to = Math.max(routeSel.rangeFrom, index);
            for (var k = from; k <= to; k++) {
                routeSelAdd(measureTool.points[k]);
            }
            routeSel.range = false;
            routeSel.rangeFrom = null;
        }
    } else {
        routeSelToggle(index);
    }
    routeSelUpdate();
}

function routeSelReset() {
    routeSel.coords = [];
    routeSel.mode = false;
    routeSel.range = false;
    routeSel.rangeFrom = null;
    routeSelEndRect();
    routeSelUpdate();
}

/** x of the bar / Escape. */
function routeSelClose() {
    routeSelReset();
}

function routeSelStartMode(index) {
    // from the waypoint menu: the point the menu was opened for is the first selected one
    var pending = typeof routeSplitPending !== 'undefined' ? routeSplitPending : null;
    closeContextMenu();
    if (!routeSelEditorActive()) {
        return;
    }
    var point = pending && pending.points[pending.index] ? pending.points[pending.index] : measureTool.points[index];
    routeSel.mode = true;
    if (point) {
        routeSelAdd(point);
    }
    routeSelUpdate();
    showToast(t('route.selection.started'), 'info');
}

function routeSelStartModeFromMenu() {
    routeSelStartMode(-1);
}

function routeSelToggleRange() {
    routeSel.mode = true;
    routeSel.range = !routeSel.range;
    routeSel.rangeFrom = null;
    if (routeSel.range) {
        showToast(t('route.selection.range_first'), 'info');
    }
    routeSelUpdate();
}

/** Called from map-core.js's measure_change listener: forget waypoints that are gone or moved. */
function routeSelOnMeasureChange() {
    if (routeSel.coords.length === 0 && !routeSel.mode) {
        return;
    }
    var points = measureTool.points || [];
    routeSel.coords = routeSel.coords.filter(function (c) {
        return points.some(function (p) { return autorouteSamePoint(c, p); });
    });
    if (routeSel.coords.length === 0) {
        routeSel.mode = false;
        routeSel.range = false;
        routeSel.rangeFrom = null;
    }
    routeSelUpdate();
}

/**
 * Hooked into MeasureTool's own map click handler (see routeSelInit()): returns
 * true when the click must not reach the line - empty-map taps in selection
 * mode do nothing, and with a selection on the first plain click only ends it
 * (it would add a waypoint otherwise).
 */
function routeSelConsumeClick() {
    if (!routeSelEditorActive()) {
        return false;
    }
    if (routeSplitSwallowClick) {
        return true; // the click after a long-press that opened a menu (route.js)
    }
    if (routeSel.mode) {
        return true;
    }
    if (routeSel.coords.length > 0) {
        routeSelReset();
        return true;
    }
    return Date.now() < routeSel.swallowUntil;
}

/* ------------------------------------------------------------ deleting */

async function routeSelDelete() {
    var indexes = routeSelIndexes();
    if (indexes.length === 0) {
        return;
    }
    var points = editorLinePoints();
    if (points.length - indexes.length < 2) {
        showToast(t('route.selection.too_few'), 'warning');
        return;
    }
    var confirmed = await showConfirmDialog(t('route.selection.confirm', { count: indexes.length }), {
        type: 'danger',
        confirmLabel: t('route.selection.delete'),
        cancelLabel: t('route.selection.no')
    });
    if (!confirmed || !routeSelEditorActive()) {
        return;
    }
    // the line may have changed while the dialog was open: ask again which waypoints are meant
    var now = editorLinePoints();
    var remaining = now.filter(function (p) { return !routeSelHas(p); });
    var count = now.length - remaining.length;
    if (remaining.length < 2 || count === 0) {
        return;
    }
    routeSelReset();
    autorouteReplaceEditorLine(remaining);
    showToast(t('route.selection.deleted', { count: count }), 'success');
}

/* ------------------------------------------------------------ drawing */

function routeSelLayer() {
    var layer = document.getElementById('routeSelLayer');
    if (!layer && typeof map !== 'undefined' && map) {
        layer = document.createElement('div');
        layer.id = 'routeSelLayer';
        layer.className = 'route-sel-layer';
        map.getDiv().appendChild(layer);
    }
    return layer;
}

function routeSelUpdate() {
    routeSelRenderBar();
    routeSelQueueRender();
}

function routeSelQueueRender() {
    if (routeSel.renderQueued) {
        return;
    }
    routeSel.renderQueued = true;
    requestAnimationFrame(function () {
        routeSel.renderQueued = false;
        routeSelRenderRings();
    });
}

function routeSelRenderRings() {
    var layer = document.getElementById('routeSelLayer');
    if (routeSel.coords.length === 0 || !routeSelEditorActive()) {
        if (layer) {
            layer.querySelectorAll('.route-sel-ring').forEach(function (el) { el.remove(); });
        }
        return;
    }
    layer = routeSelLayer();
    var projection = overlayProjection.getProjection();
    var rings = layer.querySelectorAll('.route-sel-ring');
    for (var k = routeSel.coords.length; k < rings.length; k++) {
        rings[k].remove();
    }
    routeSel.coords.forEach(function (c, k) {
        var ring = rings[k];
        if (!ring) {
            ring = document.createElement('div');
            ring.className = 'route-sel-ring';
            layer.appendChild(ring);
        }
        var px = projection.fromLatLngToContainerPixel(new google.maps.LatLng(c.lat, c.lng));
        ring.style.left = px.x + 'px';
        ring.style.top = px.y + 'px';
    });
}

function routeSelRenderBar() {
    var bar = document.getElementById('routeSelBar');
    var visible = routeSelEditorActive() && (routeSel.coords.length > 0 || routeSel.mode);
    if (!visible) {
        if (bar) {
            bar.remove();
        }
        return;
    }
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'routeSelBar';
        bar.className = 'route-sel-bar';
        document.body.appendChild(bar);
    }
    bar.innerHTML =
        '<span class="route-sel-count">' + escapeHTML(t('route.selection.count', { count: routeSel.coords.length })) + '</span>' +
        '<button type="button" class="route-sel-btn' + (routeSel.range ? ' active' : '') + '" data-on-click="routeSelToggleRange()">' +
            '<i class="material-icons-round">linear_scale</i>' + escapeHTML(t('route.selection.range')) + '</button>' +
        '<button type="button" class="route-sel-btn route-sel-danger" data-on-click="routeSelDelete()"' + (routeSel.coords.length === 0 ? ' disabled' : '') + '>' +
            '<i class="material-icons-round">delete</i>' + escapeHTML(t('route.selection.delete')) + '</button>' +
        '<button type="button" class="route-sel-btn route-sel-close" data-on-click="routeSelClose()" title="' + escapeHTML(t('route.selection.close')) + '" aria-label="' + escapeHTML(t('route.selection.close')) + '">' +
            '<i class="material-icons-round">close</i></button>';
}

/* ------------------------------------------------------------ rectangle (desktop) */

function routeSelStartRect(event) {
    var layer = routeSelLayer();
    var element = document.createElement('div');
    element.className = 'route-sel-rect';
    layer.appendChild(element);
    routeSel.rect = { x0: event.clientX, y0: event.clientY, element: element, add: event.shiftKey };
    routeSelDrawRect(event.clientX, event.clientY);
    document.addEventListener('mousemove', routeSelRectMove, true);
    document.addEventListener('mouseup', routeSelRectUp, true);
}

function routeSelDrawRect(x1, y1) {
    var r = routeSel.rect;
    var box = map.getDiv().getBoundingClientRect();
    r.element.style.left = (Math.min(r.x0, x1) - box.left) + 'px';
    r.element.style.top = (Math.min(r.y0, y1) - box.top) + 'px';
    r.element.style.width = Math.abs(x1 - r.x0) + 'px';
    r.element.style.height = Math.abs(y1 - r.y0) + 'px';
}

function routeSelRectMove(event) {
    if (routeSel.rect) {
        event.preventDefault();
        routeSelDrawRect(event.clientX, event.clientY);
    }
}

function routeSelRectUp(event) {
    var r = routeSel.rect;
    routeSelEndRect();
    if (!r) {
        return;
    }
    routeSel.swallowUntil = Date.now() + 500;
    if (Math.hypot(event.clientX - r.x0, event.clientY - r.y0) < 4) {
        return; // a Ctrl+click on the empty map: nothing
    }
    if (!r.add) {
        routeSel.coords = [];
    }
    var projection = overlayProjection.getProjection();
    var box = map.getDiv().getBoundingClientRect();
    var left = Math.min(r.x0, event.clientX) - box.left;
    var right = Math.max(r.x0, event.clientX) - box.left;
    var top = Math.min(r.y0, event.clientY) - box.top;
    var bottom = Math.max(r.y0, event.clientY) - box.top;
    (measureTool.points || []).forEach(function (p) {
        var px = projection.fromLatLngToContainerPixel(new google.maps.LatLng(p.lat, p.lng));
        if (px.x >= left && px.x <= right && px.y >= top && px.y <= bottom) {
            routeSelAdd(p);
        }
    });
    if (routeSel.coords.length === 0) {
        routeSel.mode = false;
    }
    routeSelUpdate();
}

function routeSelEndRect() {
    document.removeEventListener('mousemove', routeSelRectMove, true);
    document.removeEventListener('mouseup', routeSelRectUp, true);
    if (routeSel.rect) {
        routeSel.rect.element.remove();
        routeSel.rect = null;
    }
}

/* ------------------------------------------------------------ input */

/** What an own mouse press at this event would do: 'toggle' (on a waypoint), 'rect' (Ctrl on the empty map) or null. */
function routeSelMouseAction(event) {
    if (event.button !== 0 || !routeSelEditorActive()) {
        return null;
    }
    if (event.sourceCapabilities && event.sourceCapabilities.firesTouchEvents) {
        return null; // the compatibility mouse event of a touch
    }
    var ctrl = event.ctrlKey || event.metaKey;
    if (!ctrl && !routeSel.mode) {
        return null;
    }
    if (routeSelPointAt(event.clientX, event.clientY, ROUTE_SEL_HIT_MOUSE_PX) >= 0) {
        return 'toggle';
    }
    return ctrl ? 'rect' : null;
}

function routeSelInit() {
    // MeasureTool's click handler calls this._checkClick() on the instance every time.
    var original = measureTool._checkClick;
    if (typeof original === 'function') {
        measureTool._checkClick = function () {
            if (routeSelConsumeClick()) {
                return undefined;
            }
            return original.apply(this, arguments);
        };
    }

    var mapDiv = map.getDiv();

    // Maps may listen to pointer events: keep the press away from it (no preventDefault -
    // that would cancel the mousedown that follows).
    mapDiv.addEventListener('pointerdown', function (event) {
        if (event.pointerType === 'mouse' && routeSelMouseAction(event)) {
            event.stopPropagation();
        }
    }, true);

    mapDiv.addEventListener('mousedown', function (event) {
        var action = routeSelMouseAction(event);
        if (!action) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        routeSel.swallowUntil = Date.now() + 500;
        if (action === 'toggle') {
            routeSelTap(routeSelPointAt(event.clientX, event.clientY, ROUTE_SEL_HIT_MOUSE_PX));
        } else {
            routeSelStartRect(event);
        }
    }, true);

    // the click that follows our own press must not reach MeasureTool's waypoint circles either
    mapDiv.addEventListener('click', function (event) {
        if (Date.now() < routeSel.swallowUntil && routeSelEditorActive()) {
            event.stopPropagation();
        }
    }, true);

    // Touch, in selection mode: a tap on a waypoint toggles it (and never starts a drag of it).
    mapDiv.addEventListener('touchstart', function (event) {
        routeSel.touch = null;
        if (!routeSel.mode || event.touches.length !== 1 || !routeSelEditorActive()) {
            return;
        }
        var touch = event.touches[0];
        var idx = routeSelPointAt(touch.clientX, touch.clientY, ROUTE_SEL_HIT_TOUCH_PX);
        if (idx >= 0) {
            routeSel.touch = { idx: idx, x: touch.clientX, y: touch.clientY, moved: false };
            event.stopPropagation();
        }
    }, { capture: true, passive: false });

    mapDiv.addEventListener('touchmove', function (event) {
        if (routeSel.touch && event.touches.length === 1) {
            var touch = event.touches[0];
            if (Math.hypot(touch.clientX - routeSel.touch.x, touch.clientY - routeSel.touch.y) > 10) {
                routeSel.touch.moved = true;
            }
        }
    }, { capture: true, passive: true });

    mapDiv.addEventListener('touchend', function (event) {
        var state = routeSel.touch;
        routeSel.touch = null;
        if (!state) {
            return;
        }
        event.stopPropagation();
        event.preventDefault(); // no compatibility mouse events / click after it
        if (!state.moved) {
            routeSelTap(state.idx);
        }
    }, { capture: true, passive: false });

    mapDiv.addEventListener('touchcancel', function () { routeSel.touch = null; }, { capture: true, passive: true });

    document.addEventListener('keydown', function (event) {
        if (routeSel.coords.length === 0 && !routeSel.mode) {
            return;
        }
        if (document.querySelector('.confirm-dialog-overlay')) {
            return;
        }
        var target = event.target;
        if (target && (/^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName) || target.isContentEditable)) {
            return;
        }
        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            routeSelDelete();
        } else if (event.key === 'Escape') {
            routeSelClose();
        }
    });

    ['bounds_changed', 'zoom_changed', 'idle'].forEach(function (name) {
        map.addListener(name, function () {
            if (routeSel.coords.length > 0) {
                routeSelQueueRender();
            }
        });
    });
}
