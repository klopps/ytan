/**
 * /admin/water (templates/admin-water.php): the water index's lines over a
 * satellite map, for checking it, and the admin's corrections of it
 * (WaterIndexAdminController, WaterCorrections on the server).
 *
 * Drawing: ONE canvas in an OverlayView; the scale comes from the overlay
 * projection (fractional zoom after a pinch - map.getZoom() is rounded, see
 * wind.js) and the canvas is re-placed at the container's top left on every
 * draw/idle. Sea/lake lines get short ticks on their WATER side (OSM: water on
 * the right of the drawing direction), rivers/canals an arrow in drawing
 * direction (= downstream for rivers, what Routencheck's current uses).
 * Lines a delete correction removes are drawn dashed red.
 *
 * Tools: delete (polygon + kinds), flip (polygon + kinds: the water side /
 * flow direction is reversed), add (a line of ONE kind; sea/lake: water on
 * the right of the drawing direction). A click on the map adds a vertex
 * while a tool is active. The tools are a map control (#wxTools, visible in
 * full screen too); delete/flip highlight what saving would change
 * (wxAffectedSegments()). Login gate: admin-auth.js, wxInit() = onReady.
 */

var WX_COLORS = { sea: '#1565c0', lake: '#00acc1', river: '#2e7d32', canal: '#8e24aa' };
var WX_KINDS = ['sea', 'lake', 'river', 'canal'];
/** Segments that come from a correction (own lines): drawn like the others, in this colour. */
var WX_OWN_COLOR = '#ff00ff';
var WX_VIEW_KEY = 'ytan_admin_water_view';
var WX_TICK_SPACING = 12;
var WX_TICK_LENGTH = 5;
var WX_MAX_POLYGON_POINTS = 200;
var WX_MAX_LINE_POINTS = 2000;
var WX_HELP_KEY = 'ytan_admin_water_help';
/** The active tool on top of the bar: symbol, colour, short text, full name (keys literal for the scanner). */
var WX_TOOL_LOOK = {
    delete: { icon: 'bi-eraser', colour: '#e53935', label: function () { return t('admin.water.tb.delete'); }, title: function () { return t('admin.water.tool.delete'); } },
    flip: { icon: 'bi-arrow-left-right', colour: '#1e88e5', label: function () { return t('admin.water.tb.flip'); }, title: function () { return t('admin.water.tool.flip'); } },
    add: { icon: 'bi-pencil', colour: '#43a047', label: function () { return t('admin.water.tb.add'); }, title: function () { return t('admin.water.tool.add'); } },
    edit: { icon: 'bi-pencil-square', colour: '#ff6f00', label: function () { return t('admin.water.tb.edit'); }, title: function () { return t('admin.water.editing'); } },
};

var wx = {
    map: null,
    overlay: null,
    canvas: null,
    data: { segments: [], removed: [] },
    loadSeq: 0,
    loadTimer: null,
    tool: null,          // 'delete' | 'flip' | 'add' | null
    shape: null,         // google.maps.Polygon / Polyline being drawn
    corrections: [],
    correctionShapes: [],
    selectedId: null,
    infoWindow: null,
};

// Keys listed literally (TranslationUsageScanner finds only literal keys).
function wxKindLabel(kind) {
    switch (kind) {
        case 'sea': return t('admin.water.kind.sea');
        case 'lake': return t('admin.water.kind.lake');
        case 'river': return t('admin.water.kind.river');
        default: return t('admin.water.kind.canal');
    }
}

function wxToolHint(tool) {
    switch (tool) {
        case 'delete': return t('admin.water.hint_delete');
        case 'flip': return t('admin.water.hint_flip');
        case 'edit': return t('admin.water.hint_edit');
        default: return t('admin.water.hint_add');
    }
}

/** A correction's type for the list; replace = line edited, or deleted when it has no new line. */
function wxTypeLabel(type, points) {
    switch (type) {
        case 'delete': return t('admin.water.type.delete');
        case 'flip': return t('admin.water.type.flip');
        case 'replace': return points && points.length ? t('admin.water.type.replace') : t('admin.water.type.line_deleted');
        default: return t('admin.water.type.add');
    }
}

/** The tools that draw a line (add, and edit = a line of the index changed). */
function wxIsLineTool() {
    return wx.tool === 'add' || wx.tool === 'edit';
}

// ---------------------------------------------------------------- overlay

function wxCreateOverlay() {
    function WaterOverlay() {}
    WaterOverlay.prototype = new google.maps.OverlayView();
    WaterOverlay.prototype.onAdd = function () {
        wx.canvas = document.createElement('canvas');
        wx.canvas.style.position = 'absolute';
        wx.canvas.style.pointerEvents = 'none';
        this.getPanes().overlayLayer.appendChild(wx.canvas);
    };
    WaterOverlay.prototype.draw = function () {
        wxRender();
    };
    WaterOverlay.prototype.onRemove = function () {
        wx.canvas.remove();
        wx.canvas = null;
    };
    wx.overlay = new WaterOverlay();
    wx.overlay.setMap(wx.map);
}

function wxWorld(lat, lng) {
    var s = Math.sin(lat * Math.PI / 180);
    s = Math.min(Math.max(s, -0.9999), 0.9999);
    return [(lng + 180) / 360 * 256, (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * 256];
}

/** Container pixel mapping from the overlay projection: {x(lat,lng), y(...)} via world coordinates. */
function wxPixelMapper() {
    var projection = wx.overlay && wx.overlay.getProjection();
    if (!projection) {
        return null;
    }
    var centre = wx.map.getCenter();
    var c = projection.fromLatLngToContainerPixel(centre);
    var e = projection.fromLatLngToContainerPixel(new google.maps.LatLng(centre.lat(), centre.lng() + 0.01));
    var wc = wxWorld(centre.lat(), centre.lng());
    var we = wxWorld(centre.lat(), centre.lng() + 0.01);
    var scale = (e.x - c.x) / (we[0] - wc[0]);
    return function (lat, lng) {
        var w = wxWorld(lat, lng);
        return [c.x + (w[0] - wc[0]) * scale, c.y + (w[1] - wc[1]) * scale];
    };
}

function wxRender() {
    if (!wx.canvas || !wx.overlay.getProjection()) {
        return;
    }
    var div = wx.map.getDiv();
    var width = div.clientWidth;
    var height = div.clientHeight;
    var ratio = Math.min(window.devicePixelRatio || 1, 2);
    // container (0,0) in pane coordinates = div pixel minus container pixel of any point
    var projection = wx.overlay.getProjection();
    var centre = wx.map.getCenter();
    var inDiv = projection.fromLatLngToDivPixel(centre);
    var inContainer = projection.fromLatLngToContainerPixel(centre);
    wx.canvas.style.left = (inDiv.x - inContainer.x) + 'px';
    wx.canvas.style.top = (inDiv.y - inContainer.y) + 'px';
    wx.canvas.style.width = width + 'px';
    wx.canvas.style.height = height + 'px';
    if (wx.canvas.width !== Math.round(width * ratio) || wx.canvas.height !== Math.round(height * ratio)) {
        wx.canvas.width = Math.round(width * ratio);
        wx.canvas.height = Math.round(height * ratio);
    }
    var ctx = wx.canvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);
    var px = wxPixelMapper();
    if (!px) {
        return;
    }
    var shown = {};
    WX_KINDS.forEach(function (kind) {
        shown[kind] = document.getElementById('wxShow-' + kind).checked;
    });

    if (document.getElementById('wxShowRemoved').checked && wx.data.removed.length) {
        ctx.save();
        ctx.setLineDash([6, 4]);
        ctx.strokeStyle = '#e53935';
        ctx.lineWidth = 2;
        ctx.beginPath();
        wx.data.removed.forEach(function (s) {
            if (!shown[s[0]]) {
                return;
            }
            var a = px(s[1], s[2]);
            var b = px(s[3], s[4]);
            ctx.moveTo(a[0], a[1]);
            ctx.lineTo(b[0], b[1]);
        });
        ctx.stroke();
        ctx.restore();
    }

    WX_KINDS.forEach(function (kind) {
        if (!shown[kind]) {
            return;
        }
        var lines = new Path2D();
        var marks = new Path2D();
        // segments from corrections (s[5] = 1): same drawing, own colour
        var ownLines = new Path2D();
        var ownMarks = new Path2D();
        var shore = kind === 'sea' || kind === 'lake';
        wx.data.segments.forEach(function (s) {
            if (s[0] !== kind) {
                return;
            }
            var a = px(s[1], s[2]);
            var b = px(s[3], s[4]);
            if ((a[0] < -50 && b[0] < -50) || (a[0] > width + 50 && b[0] > width + 50)
                || (a[1] < -50 && b[1] < -50) || (a[1] > height + 50 && b[1] > height + 50)) {
                return;
            }
            var line = s[5] ? ownLines : lines;
            var mark = s[5] ? ownMarks : marks;
            line.moveTo(a[0], a[1]);
            line.lineTo(b[0], b[1]);
            var dx = b[0] - a[0];
            var dy = b[1] - a[1];
            var length = Math.hypot(dx, dy);
            if (length < 1) {
                return;
            }
            var ux = dx / length;
            var uy = dy / length;
            if (shore) {
                // water on the right of the drawing direction; screen y points down
                var nx = -uy * WX_TICK_LENGTH;
                var ny = ux * WX_TICK_LENGTH;
                for (var d = WX_TICK_SPACING / 2; d < length; d += WX_TICK_SPACING) {
                    var x = a[0] + ux * d;
                    var y = a[1] + uy * d;
                    mark.moveTo(x, y);
                    mark.lineTo(x + nx, y + ny);
                }
            } else if (length > 30) {
                var mx = a[0] + dx / 2;
                var my = a[1] + dy / 2;
                mark.moveTo(mx - ux * 6 - uy * 4, my - uy * 6 + ux * 4);
                mark.lineTo(mx, my);
                mark.lineTo(mx - ux * 6 + uy * 4, my - uy * 6 - ux * 4);
            }
        });
        [[lines, marks, WX_COLORS[kind]], [ownLines, ownMarks, WX_OWN_COLOR]].forEach(function (set) {
            ctx.strokeStyle = set[2];
            ctx.lineWidth = 2.5;
            ctx.stroke(set[0]);
            ctx.lineWidth = 1.5;
            ctx.stroke(set[1]);
        });
    });

    // live preview of the area tools: what saving would delete (red) / reverse (orange),
    // drawn over everything, also for kinds hidden by the checkboxes above the map
    var affected = wxAffectedSegments();
    if (affected.length) {
        ctx.save();
        ctx.strokeStyle = wx.tool === 'delete' ? '#e53935' : '#ff9800';
        ctx.lineWidth = 6;
        ctx.lineCap = 'round';
        ctx.beginPath();
        affected.forEach(function (s) {
            var a = px(s[1], s[2]);
            var b = px(s[3], s[4]);
            ctx.moveTo(a[0], a[1]);
            ctx.lineTo(b[0], b[1]);
        });
        ctx.stroke();
        ctx.restore();
    }
}

function wxRedraw() {
    wxRender();
}

/** Ray casting in degrees, the same test as the server's (WaterCorrections::inside()). */
function wxInPolygon(lat, lng, polygon) {
    var inside = false;
    for (var i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        var a = polygon[i];
        var b = polygon[j];
        if ((a[0] > lat) !== (b[0] > lat) && lng < (b[1] - a[1]) * (lat - a[0]) / (b[0] - a[0]) + a[1]) {
            inside = !inside;
        }
    }
    return inside;
}

/**
 * The loaded segments the area being drawn would delete/reverse: chosen kinds,
 * midpoint inside the polygon (as the server decides). Empty for other tools
 * or fewer than 3 corners.
 */
function wxAffectedSegments() {
    if ((wx.tool !== 'delete' && wx.tool !== 'flip') || !wx.shape || wx.shape.getPath().getLength() < 3) {
        return [];
    }
    var polygon = wx.shape.getPath().getArray().map(function (p) { return [p.lat(), p.lng()]; });
    var kinds = wxToolKinds();
    return wx.data.segments.filter(function (s) {
        return kinds.indexOf(s[0]) >= 0 && wxInPolygon((s[1] + s[3]) / 2, (s[2] + s[4]) / 2, polygon);
    });
}

// ---------------------------------------------------------------- loading

function wxScheduleLoad() {
    clearTimeout(wx.loadTimer);
    wx.loadTimer = setTimeout(wxLoad, 250);
}

function wxReload() {
    wxLoad();
}

async function wxLoad() {
    var bounds = wx.map.getBounds();
    if (!bounds) {
        return;
    }
    var sw = bounds.getSouthWest();
    var ne = bounds.getNorthEast();
    var seq = ++wx.loadSeq;
    var query = '?south=' + sw.lat().toFixed(5) + '&west=' + sw.lng().toFixed(5)
        + '&north=' + ne.lat().toFixed(5) + '&east=' + ne.lng().toFixed(5)
        + (document.getElementById('wxRaw').checked ? '&raw=1' : '');
    var status = document.getElementById('wxStatus');
    status.textContent = t('admin.water.loading');
    try {
        var answer = await Ytan.get('/admin/water/segments' + query);
        if (seq !== wx.loadSeq) {
            return;
        }
        var data = answer.data;
        wx.data = { segments: data.segments || [], removed: data.removed || [] };
        wxRender();
        wxUpdateToolUi(); // the area tools' count follows the newly loaded segments
        if (!data.available) {
            status.textContent = t('admin.water.no_index');
        } else if (data.too_large) {
            status.textContent = t('admin.water.zoom_in');
        } else {
            var text = t('admin.water.status', { count: wx.data.segments.length, version: data.version || '?' });
            if (wx.data.removed.length) {
                text += ' · ' + t('admin.water.status_removed', { count: wx.data.removed.length });
            }
            if (data.truncated) {
                text += ' · ' + t('admin.water.truncated');
            }
            if (!data.covered) {
                text += ' · ' + t('admin.water.not_covered');
            }
            status.textContent = text;
        }
    } catch (err) {
        if (seq === wx.loadSeq) {
            status.textContent = t('admin.water.load_failed', { error: translateApiError(err.data) || err.message });
        }
    }
}

// ---------------------------------------------------------------- line info

function wxDistanceToSegment(p, a, b) {
    var dx = b[0] - a[0];
    var dy = b[1] - a[1];
    var length = dx * dx + dy * dy;
    var t0 = length > 0 ? Math.max(0, Math.min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / length)) : 0;
    return Math.hypot(p[0] - (a[0] + t0 * dx), p[1] - (a[1] + t0 * dy));
}

function wxShowLineInfo(latLng) {
    var px = wxPixelMapper();
    if (!px) {
        return;
    }
    var p = px(latLng.lat(), latLng.lng());
    var best = null;
    var bestDistance = 10;
    wx.data.segments.forEach(function (s) {
        if (!document.getElementById('wxShow-' + s[0]).checked) {
            return;
        }
        var d = wxDistanceToSegment(p, px(s[1], s[2]), px(s[3], s[4]));
        if (d < bestDistance) {
            bestDistance = d;
            best = s;
        }
    });
    if (!best) {
        if (wx.infoWindow) {
            wx.infoWindow.close();
        }
        return;
    }
    var a = new google.maps.LatLng(best[1], best[2]);
    var b = new google.maps.LatLng(best[3], best[4]);
    var length = google.maps.geometry ? google.maps.geometry.spherical.computeDistanceBetween(a, b) : wxHaversine(best);
    var bearing = (wxBearing(best) + 360) % 360;
    var html = '<div class="small wx-info"><strong>' + escapeHTML(wxKindLabel(best[0])) + '</strong><br>'
        + escapeHTML(t('admin.water.info_length', { meters: Math.round(length) })) + '<br>'
        + escapeHTML(t('admin.water.info_bearing', { degrees: Math.round(bearing) })) + '<br>'
        + '<span class="wx-info-coords">' + best[1].toFixed(6) + ', ' + best[2].toFixed(6) + ' → ' + best[3].toFixed(6) + ', ' + best[4].toFixed(6) + '</span>';
    wx.infoSegment = best;
    if (document.getElementById('wxRaw').checked) {
        // a correction is made against what applies now, not the index as built
        html += '<div class="wx-info-coords mt-1">' + escapeHTML(t('admin.water.raw_no_edit')) + '</div>';
    } else {
        html += '<div class="d-flex flex-wrap gap-2 mt-2">'
            + '<button type="button" class="btn btn-primary btn-sm text-nowrap" data-on-click="wxEditLine()"><i class="bi bi-pencil"></i> ' + escapeHTML(t('admin.water.edit_line')) + '</button>'
            + '<button type="button" class="btn btn-outline-danger btn-sm text-nowrap" data-on-click="wxDeleteLine()"><i class="bi bi-trash"></i> ' + escapeHTML(t('admin.water.delete_line')) + '</button>'
            + '</div>';
    }
    html += '</div>';
    if (!wx.infoWindow) {
        wx.infoWindow = new google.maps.InfoWindow();
    }
    wx.infoWindow.setContent(html);
    wx.infoWindow.setPosition(latLng);
    wx.infoWindow.open({ map: wx.map });
}

// ---------------------------------------------------------------- whole lines

var WX_CHAIN_MAX = 2000;

function wxPointKey(lat, lng) {
    return lat.toFixed(6) + ',' + lng.toFixed(6);
}

function wxSegmentKey(s) {
    return s[0] + '|' + wxPointKey(s[1], s[2]) + '|' + wxPointKey(s[3], s[4]);
}

/**
 * The line a segment belongs to: the index holds loose segments, so the
 * chain is followed through the loaded ones of the same kind in drawing
 * direction, as long as exactly one segment goes on from a point and one
 * arrives there (a junction or the end of the loaded area stops it). A
 * chain that comes back to its start is a ring (first point repeated last).
 *
 * @returns {{kind: string, points: Array<[number, number]>, segments: number, ring: boolean}}
 */
function wxChain(clicked) {
    var kind = clicked[0];
    var outgoing = {};
    var incoming = {};
    // Compared by coordinates, never by object: the data is reloaded on every
    // map idle (opening the InfoWindow pans the map), so the clicked segment
    // is usually not an element of wx.data.segments any more.
    var clickedKey = wxSegmentKey(clicked);
    var start = clicked;
    // only segments of the same origin: an own line never runs on into the index's lines and back
    var origin = clicked[5] || 0;
    wx.data.segments.forEach(function (s) {
        if (s[0] !== kind || (s[5] || 0) !== origin) {
            return;
        }
        if (wxSegmentKey(s) === clickedKey) {
            start = s;
        }
        var from = wxPointKey(s[1], s[2]);
        var to = wxPointKey(s[3], s[4]);
        (outgoing[from] = outgoing[from] || []).push(s);
        (incoming[to] = incoming[to] || []).push(s);
    });
    var used = new Set([start]);
    var points = [[start[1], start[2]], [start[3], start[4]]];
    var ring = false;

    var current = start;
    while (used.size < WX_CHAIN_MAX) {
        var key = wxPointKey(current[3], current[4]);
        var next = outgoing[key] || [];
        if (next.length !== 1 || (incoming[key] || []).length !== 1) {
            break;
        }
        if (next[0] === start) {
            ring = true;
            points.push([start[1], start[2]]);
            break;
        }
        if (used.has(next[0])) {
            break;
        }
        current = next[0];
        used.add(current);
        points.push([current[3], current[4]]);
    }
    current = start;
    while (!ring && used.size < WX_CHAIN_MAX) {
        var startKey = wxPointKey(current[1], current[2]);
        var previous = incoming[startKey] || [];
        if (previous.length !== 1 || (outgoing[startKey] || []).length !== 1 || used.has(previous[0])) {
            break;
        }
        current = previous[0];
        used.add(current);
        points.unshift([current[1], current[2]]);
    }
    return { kind: kind, points: points, segments: used.size, ring: ring };
}

function wxChainLength(points) {
    var length = 0;
    for (var i = 1; i < points.length; i++) {
        length += wxHaversine([null, points[i - 1][0], points[i - 1][1], points[i][0], points[i][1]]);
    }
    return length;
}

function wxShowChain(points) {
    wxHideChain();
    wx.chainShape = new google.maps.Polyline({
        map: wx.map,
        path: points.map(function (p) { return { lat: p[0], lng: p[1] }; }),
        clickable: false,
        strokeColor: '#ff6f00',
        strokeOpacity: 0.9,
        strokeWeight: 7,
        zIndex: 5,
    });
}

function wxHideChain() {
    if (wx.chainShape) {
        wx.chainShape.setMap(null);
        wx.chainShape = null;
    }
}

/**
 * The correction an own line belongs to (segment field 5 = its id), null for
 * a line of the index.
 */
function wxOwnCorrection(segment) {
    var id = segment[5] || 0;
    if (id <= 0) {
        return null;
    }
    return wx.corrections.find(function (c) { return c.id === id; }) || null;
}

/** An own correction's line as a chain (the whole line, also beyond the loaded area). */
function wxCorrectionChain(correction) {
    var points = correction.points;
    var first = points[0];
    var last = points[points.length - 1];
    return {
        kind: correction.kinds[0],
        points: points,
        segments: points.length - 1,
        ring: points.length >= 4 && first[0] === last[0] && first[1] === last[1],
    };
}

/**
 * InfoWindow "Bearbeiten": the whole line goes into the editor. An own line
 * (from a correction) is changed in that correction; a line of the index is
 * replaced by a new correction - the index itself always stays as it is.
 */
function wxEditLine() {
    if (!wx.infoSegment) {
        return;
    }
    var own = wxOwnCorrection(wx.infoSegment);
    if (own) {
        wxStartTool('edit', wxCorrectionChain(own));
        wx.editCorrectionId = own.id;
        wx.editCorrectionType = own.type;
        document.getElementById('wxNote').value = own.note || ''; // the correction's note, to keep or change
        if (own.type === 'add') {
            // an added line may change its kind (a replaced one keeps it: it says which index line it replaces)
            WX_KINDS.forEach(function (kind) {
                document.getElementById('wxKind-' + kind).disabled = false;
            });
            wxUpdateToolUi();
        }
        document.getElementById('wxDrawHint').textContent = t('admin.water.hint_edit_own', { id: own.id });
        return;
    }
    wxStartTool('edit', wxChain(wx.infoSegment));
}

/**
 * InfoWindow "Löschen". On an own line its correction is deleted: an added
 * line disappears, a changed line of the index gets its original back (to
 * leave the place empty, delete the original afterwards). A line of the
 * index is hidden by a new correction.
 */
async function wxDeleteLine() {
    if (!wx.infoSegment) {
        return;
    }
    var own = wxOwnCorrection(wx.infoSegment);
    if (wx.infoWindow) {
        wx.infoWindow.close();
    }
    if (own) {
        await wxDeleteOwnLine(own);
        return;
    }
    var chain = wxChain(wx.infoSegment);
    wxShowChain(chain.points);
    var confirmed = await adminConfirm({
        title: t('admin.water.confirm_delete_line_title'),
        message: t('admin.water.confirm_delete_line', {
            kind: wxKindLabel(chain.kind),
            count: chain.segments,
            meters: Math.round(wxChainLength(chain.points)),
        }),
        confirmLabel: t('admin.water.delete_line'),
        danger: true,
    });
    if (!confirmed) {
        wxHideChain();
        return;
    }
    try {
        var answer = await Ytan.post('/admin/water/corrections', {
            type: 'replace',
            kinds: [chain.kind],
            original: chain.points,
            points: [],
        });
        wxToastPois('admin.water.saved', answer.data.pois);
        await wxLoadCorrections();
        wxLoad();
    } catch (err) {
        showAdminToast(t('admin.water.save_failed', { error: translateApiError(err.data) || err.message }), 'error');
    } finally {
        wxHideChain();
    }
}

async function wxDeleteOwnLine(correction) {
    var chain = wxCorrectionChain(correction);
    wxShowChain(chain.points);
    var vars = {
        id: correction.id,
        kind: wxKindLabel(chain.kind),
        meters: Math.round(wxChainLength(chain.points)),
    };
    // an added line disappears; a changed original line: the change is discarded, the original comes back
    var confirmed = await adminConfirm({
        title: correction.type === 'add' ? t('admin.water.confirm_delete_own_title') : t('admin.water.confirm_discard_own_title'),
        message: correction.type === 'add' ? t('admin.water.confirm_delete_own', vars) : t('admin.water.confirm_discard_own', vars),
        confirmLabel: t('admin.water.delete_line'),
        danger: true,
    });
    if (!confirmed) {
        wxHideChain();
        return;
    }
    try {
        var answer = await Ytan.del('/admin/water/corrections/' + correction.id);
        if (wx.selectedId === correction.id) {
            wx.selectedId = null;
        }
        wxToastPois('admin.water.deleted', answer.data.pois);
        await wxLoadCorrections();
        wxLoad();
    } catch (err) {
        showAdminToast(t('admin.water.save_failed', { error: translateApiError(err.data) || err.message }), 'error');
    } finally {
        wxHideChain();
    }
}

function wxHaversine(s) {
    var r = 6371008.8;
    var p1 = s[1] * Math.PI / 180;
    var p2 = s[3] * Math.PI / 180;
    var dp = p2 - p1;
    var dl = (s[4] - s[2]) * Math.PI / 180;
    var h = Math.sin(dp / 2) * Math.sin(dp / 2) + Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) * Math.sin(dl / 2);
    return 2 * r * Math.asin(Math.sqrt(h));
}

function wxBearing(s) {
    var p1 = s[1] * Math.PI / 180;
    var p2 = s[3] * Math.PI / 180;
    var dl = (s[4] - s[2]) * Math.PI / 180;
    return Math.atan2(Math.sin(dl) * Math.cos(p2), Math.cos(p1) * Math.sin(p2) - Math.sin(p1) * Math.cos(p2) * Math.cos(dl)) * 180 / Math.PI;
}

// ---------------------------------------------------------------- tools

/**
 * Starts a tool. 'edit' takes the chain of an existing line (wxChain()):
 * its course is the editor's line and is kept as the correction's
 * "original"; the kind is fixed.
 */
function wxStartTool(tool, chain) {
    wxCancelTool();
    wx.tool = tool;
    wx.editOriginal = tool === 'edit' ? chain.points : null;
    var defaults = tool === 'delete' ? WX_KINDS : (tool === 'flip' ? ['sea', 'lake'] : (tool === 'edit' ? [chain.kind] : ['sea']));
    WX_KINDS.forEach(function (kind) {
        var box = document.getElementById('wxKind-' + kind);
        box.checked = defaults.indexOf(kind) >= 0;
        box.disabled = tool === 'edit';
    });
    var colour = tool === 'delete' ? '#e53935' : (tool === 'flip' ? '#1e88e5' : (tool === 'edit' ? '#ff6f00' : '#43a047'));
    if (tool === 'add' || tool === 'edit') {
        wx.shape = new google.maps.Polyline({
            map: wx.map,
            editable: true,
            // clickable: a click on a point removes it (see below)
            clickable: true,
            strokeColor: colour,
            strokeWeight: 3,
            path: tool === 'edit' ? chain.points.map(function (p) { return { lat: p[0], lng: p[1] }; }) : [],
            icons: [{ icon: { path: google.maps.SymbolPath.FORWARD_OPEN_ARROW, scale: 2.5 }, offset: '50%', repeat: '80px' }],
        });
        wx.shape.addListener('click', wxLinePointClicked);
        if (tool === 'edit') {
            var bounds = new google.maps.LatLngBounds();
            chain.points.forEach(function (p) { bounds.extend({ lat: p[0], lng: p[1] }); });
            wx.map.fitBounds(bounds, 60);
        }
    } else {
        // clickable:false - a click inside the polygon must still reach the map, to add a vertex there.
        wx.shape = new google.maps.Polygon({
            map: wx.map,
            editable: true,
            clickable: false,
            strokeColor: colour,
            fillColor: colour,
            fillOpacity: 0.15,
            path: [],
        });
    }
    wxWatchPath();
    document.getElementById('wxDrawHint').textContent = wxToolHint(tool);
    document.getElementById('wxNote').value = '';
    document.getElementById('wxDrawPanel').hidden = false;
    document.getElementById('wxNoteBox').hidden = true;
    // while drawing the bar shows this tool's actions; on top the tool itself (a click cancels it)
    document.getElementById('wxToolButtons').hidden = true;
    var look = WX_TOOL_LOOK[tool];
    var title = document.getElementById('wxToolTitle');
    title.style.setProperty('--wx-tool', look.colour);
    title.title = look.title() + ' - ' + t('common.cancel');
    document.getElementById('wxToolTitleIcon').className = 'bi ' + look.icon;
    document.getElementById('wxToolTitleText').textContent = look.label();
    wxShowHelp();
    document.getElementById('wxReverse').hidden = !wxIsLineTool();
    document.getElementById('wxClose').hidden = !wxIsLineTool();
    document.getElementById('wxEditRow').classList.toggle('wx-compact', wxIsLineTool());
    document.getElementById('wxRingOutside').checked = true;
    if (tool === 'edit' && chain.ring && (chain.kind === 'sea' || chain.kind === 'lake')) {
        // keep the ring's water side as it is
        var ring = chain.points.slice(0, -1).map(function (p) { return new google.maps.LatLng(p[0], p[1]); });
        document.getElementById(wxRingArea(ring) > 0 ? 'wxRingOutside' : 'wxRingInside').checked = true;
    }
    wx.map.setOptions({ draggableCursor: 'crosshair' });
    if (wx.infoWindow) {
        wx.infoWindow.close();
    }
    wxUpdateToolUi();
}

function wxCancelTool() {
    if (wx.shape) {
        wx.shape.setMap(null);
        wx.shape = null;
    }
    wx.tool = null;
    wx.editOriginal = null;
    wx.editCorrectionId = null;
    wx.editCorrectionType = null;
    wxHideChain();
    WX_KINDS.forEach(function (kind) {
        document.getElementById('wxKind-' + kind).disabled = false;
    });
    document.getElementById('wxDrawPanel').hidden = true;
    document.getElementById('wxToolButtons').hidden = false;
    document.getElementById('wxDrawHint').hidden = true;
    document.getElementById('wxNoteBox').hidden = true;
    document.getElementById('wxSaveBadge').hidden = true;
    wxRender();
    if (wx.map) {
        wx.map.setOptions({ draggableCursor: null });
    }
}

/** A line has exactly one kind: ticking one unticks the others. */
function wxToolKindChanged(input) {
    if (wxIsLineTool() && input.checked) {
        WX_KINDS.forEach(function (kind) {
            var box = document.getElementById('wxKind-' + kind);
            if (box !== input) {
                box.checked = false;
            }
        });
    }
    wxUpdateToolUi();
}

function wxToolKinds() {
    return WX_KINDS.filter(function (kind) {
        return document.getElementById('wxKind-' + kind).checked;
    });
}

function wxUpdateToolUi() {
    if (!wx.tool) {
        return;
    }
    var count = wx.shape ? wx.shape.getPath().getLength() : 0;
    var needed = wxIsLineTool() ? 2 : 3;
    document.getElementById('wxUndo').disabled = count === 0;
    document.getElementById('wxReverse').disabled = count < 2;
    document.getElementById('wxClose').disabled = count < 3 || wxLineClosed();
    var kinds = wxToolKinds();
    document.getElementById('wxRingSide').hidden = !(wxIsLineTool() && kinds.length === 1 && (kinds[0] === 'sea' || kinds[0] === 'lake'));
    document.getElementById('wxSave').disabled = count < needed || wxToolKinds().length === 0;
    // area tools: the badge says how many loaded segments saving would change; the map shows them
    var area = wx.tool === 'delete' || wx.tool === 'flip';
    var badge = document.getElementById('wxSaveBadge');
    badge.hidden = !(area && count >= 3 && kinds.length);
    if (!badge.hidden) {
        var affected = wxAffectedSegments().length;
        badge.textContent = affected;
        document.getElementById('wxSave').title = t('admin.water.save_count', { count: affected });
    } else {
        document.getElementById('wxSave').title = t('admin.water.save');
    }
    wxRender();
}

/** The hint beside the bar: open unless switched off with "Help" (remembered in localStorage). */
function wxShowHelp() {
    var off = false;
    try {
        off = localStorage.getItem(WX_HELP_KEY) === 'off';
    } catch (err) {
        // not essential
    }
    document.getElementById('wxDrawHint').hidden = off;
    document.getElementById('wxHelpButton').classList.toggle('wx-on', !off);
}

function wxToggleHelp() {
    var hint = document.getElementById('wxDrawHint');
    try {
        localStorage.setItem(WX_HELP_KEY, hint.hidden ? 'on' : 'off');
    } catch (err) {
        // not essential
    }
    wxShowHelp();
}

/** The note field beside the bar. */
function wxToggleNote() {
    var box = document.getElementById('wxNoteBox');
    box.hidden = !box.hidden;
    document.getElementById('wxNoteButton').classList.toggle('wx-on', !box.hidden);
    if (!box.hidden) {
        document.getElementById('wxNote').focus();
    }
}

function wxUndo() {
    if (wx.shape && wx.shape.getPath().getLength() > 0) {
        wx.shape.getPath().pop();
    }
}

function wxReverse() {
    if (!wx.shape) {
        return;
    }
    wxSetPath(wx.shape.getPath().getArray().slice().reverse());
}

/** Whether the line being drawn ends on its first point (a ring). */
function wxLineClosed() {
    if (!wx.shape || !wxIsLineTool()) {
        return false;
    }
    var path = wx.shape.getPath();
    var n = path.getLength();
    return n >= 4 && path.getAt(0).equals(path.getAt(n - 1));
}

/**
 * A click on a point of the line being drawn removes it (Google's editable
 * line can move and insert points, not delete them). A ring keeps its first
 * and last point together; a line keeps at least 2 points.
 */
function wxLinePointClicked(event) {
    if (event.vertex === undefined || event.vertex === null || !wx.shape) {
        return;
    }
    var points = wx.shape.getPath().getArray().slice();
    var closed = wxLineClosed();
    var index = event.vertex;
    if (points.length - (closed ? 1 : 0) <= (closed ? 3 : 2)) {
        showAdminToast(t('admin.water.too_few_points'), 'info');
        return;
    }
    if (closed && (index === 0 || index === points.length - 1)) {
        points.shift();
        points[points.length - 1] = points[0];
    } else {
        points.splice(index, 1);
    }
    wxSetPath(points);
}

/**
 * Signed area of a ring in a flat local metric (x = east, y = north):
 * positive = counter-clockwise. The closing point may or may not be repeated.
 */
function wxRingArea(points) {
    var lat0 = points[0].lat() * Math.PI / 180;
    var k = Math.cos(lat0);
    var area = 0;
    for (var i = 0, n = points.length; i < n; i++) {
        var a = points[i];
        var b = points[(i + 1) % n];
        area += a.lng() * k * b.lat() - b.lng() * k * a.lat();
    }
    return area / 2;
}

/**
 * Closes the new line: the first point is appended exactly as the last one
 * (so autorouting finds no gap). Sea/lake: the direction is set from the
 * chosen side - water outside (island) = counter-clockwise, water inside
 * (lake, bay) = clockwise, since the water lies on the right of the drawing
 * direction (OSM coastline convention).
 */
function wxCloseLine() {
    if (!wx.shape || !wxIsLineTool() || wxLineClosed()) {
        return;
    }
    var points = wx.shape.getPath().getArray().slice();
    if (points.length < 3) {
        showAdminToast(t('admin.water.close_needs_points'), 'error');
        return;
    }
    var kinds = wxToolKinds();
    if (kinds.length === 1 && (kinds[0] === 'sea' || kinds[0] === 'lake')) {
        var waterOutside = document.getElementById('wxRingOutside').checked;
        var counterClockwise = wxRingArea(points) > 0;
        if (waterOutside !== counterClockwise) {
            points.reverse();
        }
    }
    points.push(points[0]);
    wxSetPath(points);
}

/** The side chosen after closing: turns the ring round when needed. */
function wxRingSideChanged() {
    if (!wxLineClosed()) {
        return;
    }
    var points = wx.shape.getPath().getArray().slice(0, -1);
    var waterOutside = document.getElementById('wxRingOutside').checked;
    if (waterOutside !== (wxRingArea(points) > 0)) {
        points.reverse();
        points.push(points[0]);
        wxSetPath(points);
    }
}

/** Replaces the drawn path (new MVCArray - its listeners are added again). */
function wxSetPath(points) {
    wx.shape.setPath(points);
    wxWatchPath();
    wxUpdateToolUi();
}

/**
 * Listeners on the drawn path. In a closed ring the first and the last point
 * are one: dragging either moves the other along.
 */
function wxWatchPath() {
    var path = wx.shape.getPath();
    ['insert_at', 'remove_at'].forEach(function (eventName) {
        google.maps.event.addListener(path, eventName, wxUpdateToolUi);
    });
    google.maps.event.addListener(path, 'set_at', function (index, previous) {
        var last = path.getLength() - 1;
        if (wxIsLineTool() && last >= 3 && (index === 0 || index === last)) {
            var other = index === 0 ? last : 0;
            if (path.getAt(other).equals(previous)) {
                path.setAt(other, path.getAt(index));
            }
        }
        wxUpdateToolUi();
    });
}

function wxMapClick(event) {
    if (!wx.tool) {
        wxShowLineInfo(event.latLng);
        return;
    }
    if (wxLineClosed()) {
        showAdminToast(t('admin.water.line_closed'), 'info');
        return;
    }
    var path = wx.shape.getPath();
    var max = wxIsLineTool() ? WX_MAX_LINE_POINTS : WX_MAX_POLYGON_POINTS;
    if (path.getLength() >= max) {
        showAdminToast(t('admin.water.too_many_points', { max: max }), 'error');
        return;
    }
    path.push(event.latLng);
}

async function wxSave() {
    if (!wx.shape) {
        return;
    }
    var kinds = wxToolKinds();
    if (wxIsLineTool() && kinds.length !== 1) {
        showAdminToast(t('admin.water.add_one_kind'), 'error');
        return;
    }
    var points = wx.shape.getPath().getArray().map(function (p) {
        return [Number(p.lat().toFixed(6)), Number(p.lng().toFixed(6))];
    });
    var body = {
        type: wx.tool,
        kinds: kinds,
        points: points,
        note: document.getElementById('wxNote').value,
    };
    if (wx.tool === 'edit') {
        // an edited line = the old course out, the new one in
        body.type = 'replace';
        body.original = wx.editOriginal;
    }
    var button = document.getElementById('wxSave');
    button.disabled = true;
    try {
        // an own line: its correction is changed, no new one stacked on top
        var answer = wx.editCorrectionId
            // the note field starts with the correction's note, so what it holds now is the note ("" = none)
            ? await Ytan.put('/admin/water/corrections/' + wx.editCorrectionId, wx.editCorrectionType === 'add'
                ? { points: points, note: body.note, kinds: kinds }
                : { points: points, note: body.note })
            : await Ytan.post('/admin/water/corrections', body);
        wxCancelTool();
        wxToastPois('admin.water.saved', answer.data.pois);
        await wxLoadCorrections();
        wxLoad();
    } catch (err) {
        button.disabled = false;
        showAdminToast(t('admin.water.save_failed', { error: translateApiError(err.data) || err.message }), 'error');
    }
}

function wxToastPois(key, pois) {
    var text = t(key, { count: pois ? pois.computed : 0 });
    if (pois && pois.more) {
        text += ' ' + t('admin.water.pois_more');
    }
    showAdminToast(text, 'success');
}

// ---------------------------------------------------------------- corrections list

async function wxLoadCorrections() {
    try {
        var answer = await Ytan.get('/admin/water/corrections');
        wx.corrections = answer.data || [];
    } catch (err) {
        showAdminToast(t('admin.water.load_failed', { error: translateApiError(err.data) || err.message }), 'error');
        return;
    }
    wxRenderCorrections();
}

function wxRenderCorrections() {
    wx.correctionShapes.forEach(function (shape) {
        shape.setMap(null);
    });
    wx.correctionShapes = [];
    var body = document.getElementById('wxCorrections');
    if (!wx.corrections.length) {
        body.innerHTML = '<tr><td colspan="6" class="text-secondary small">' + escapeHTML(t('admin.water.no_corrections')) + '</td></tr>';
        return;
    }
    body.innerHTML = wx.corrections.map(function (c) {
        return '<tr data-id="' + c.id + '"' + (c.id === wx.selectedId ? ' class="wx-selected"' : '') + '>'
            + '<td>' + c.id + '</td>'
            + '<td>' + escapeHTML(wxTypeLabel(c.type, c.points)) + '</td>'
            + '<td>' + escapeHTML(c.kinds.map(wxKindLabel).join(', ')) + '</td>'
            + '<td class="small" id="wxNoteCell-' + c.id + '">' + escapeHTML(c.note) + '</td>'
            + '<td class="small text-secondary">' + escapeHTML(c.username) + '<br>' + escapeHTML(c.created_at) + '</td>'
            + '<td class="text-nowrap">'
            + '<button type="button" class="btn btn-outline-secondary btn-sm me-1" title="' + escapeHTML(t('admin.water.edit_note')) + '" data-on-click="wxEditNote(' + c.id + ')"><i class="bi bi-pencil"></i></button>'
            + '<button type="button" class="btn btn-outline-secondary btn-sm me-1" title="' + escapeHTML(t('admin.water.show_on_map')) + '" data-on-click="wxShowCorrection(' + c.id + ')"><i class="bi bi-geo-alt"></i></button>'
            + '<button type="button" class="btn btn-outline-danger btn-sm" title="' + escapeHTML(t('admin.water.delete')) + '" data-on-click="wxDeleteCorrection(' + c.id + ')"><i class="bi bi-trash"></i></button>'
            + '</td></tr>';
    }).join('');
    if (!wx.map) {
        return;
    }
    wx.corrections.forEach(function (c) {
        var path = c.points.map(function (p) {
            return { lat: p[0], lng: p[1] };
        });
        var colour = c.type === 'delete' ? '#e53935' : (c.type === 'flip' ? '#1e88e5' : '#43a047');
        var selected = c.id === wx.selectedId;
        // Only the correction picked in the list ("Auf der Karte zeigen") is
        // drawn. Lines (add, replace) are on the map anyway - the canvas draws
        // the corrected index - and drawing every correction showed lines a
        // later one had already replaced; an area (delete, flip) keeps working
        // after saving, its outline has no use on the map any more.
        if (!selected) {
            return;
        }
        if (c.type === 'replace') {
            // the old course dashed red, the new one (if any) green
            wx.correctionShapes.push(new google.maps.Polyline({
                map: wx.map,
                path: (c.original || []).map(function (p) { return { lat: p[0], lng: p[1] }; }),
                clickable: false,
                strokeOpacity: 0,
                icons: [{ icon: { path: 'M 0,-1 0,1', strokeOpacity: 0.7, strokeColor: '#e53935', scale: selected ? 4 : 3 }, offset: '0', repeat: '12px' }],
                zIndex: 0,
            }));
            if (!path.length) {
                return;
            }
        }
        var shape = c.type === 'add' || c.type === 'replace'
            ? new google.maps.Polyline({ map: wx.map, path: path, clickable: false, strokeColor: colour, strokeOpacity: 0.6, strokeWeight: 6, zIndex: 0 })
            : new google.maps.Polygon({ map: wx.map, path: path, clickable: false, strokeColor: colour, strokeWeight: selected ? 3 : 1, strokeOpacity: 0.8, fillColor: colour, fillOpacity: selected ? 0.12 : 0.05, zIndex: 0 });
        wx.correctionShapes.push(shape);
    });
}

function wxShowCorrection(id) {
    var correction = wx.corrections.find(function (c) { return c.id === id; });
    if (!correction || !wx.map) {
        return;
    }
    if (wx.selectedId === id) {
        // second click: hide it again
        wx.selectedId = null;
        wxRenderCorrections();
        return;
    }
    wx.selectedId = id;
    var bounds = new google.maps.LatLngBounds();
    correction.points.concat(correction.original || []).forEach(function (p) {
        bounds.extend({ lat: p[0], lng: p[1] });
    });
    wx.map.fitBounds(bounds, 60);
    wxRenderCorrections();
    document.getElementById('wxMap').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/** Pencil in the list: the note cell becomes a field (Enter / check = save, Escape / x = back). */
function wxEditNote(id) {
    var c = wx.corrections.find(function (x) { return x.id === id; });
    var cell = document.getElementById('wxNoteCell-' + id);
    if (!c || !cell || cell.querySelector('input')) {
        return;
    }
    cell.innerHTML = '<div class="d-flex gap-1">'
        + '<input type="text" class="form-control form-control-sm" id="wxNoteInput-' + id + '" maxlength="500" value="' + escapeHTML(c.note) + '"'
        + ' placeholder="' + escapeHTML(t('admin.water.note_placeholder')) + '" data-on-keydown="wxNoteKey(event, ' + id + ')">'
        + '<button type="button" class="btn btn-primary btn-sm" title="' + escapeHTML(t('admin.water.save')) + '" data-on-click="wxSaveNote(' + id + ')"><i class="bi bi-check-lg"></i></button>'
        + '<button type="button" class="btn btn-outline-secondary btn-sm" title="' + escapeHTML(t('common.cancel')) + '" data-on-click="wxRenderCorrections()"><i class="bi bi-x-lg"></i></button>'
        + '</div>';
    var input = document.getElementById('wxNoteInput-' + id);
    input.focus();
    input.select();
}

function wxNoteKey(event, id) {
    if (event.key === 'Enter') {
        event.preventDefault();
        wxSaveNote(id);
    } else if (event.key === 'Escape') {
        wxRenderCorrections();
    }
}

async function wxSaveNote(id) {
    var input = document.getElementById('wxNoteInput-' + id);
    if (!input) {
        return;
    }
    input.disabled = true;
    try {
        // only the note: PUT without points changes nothing else (any type)
        await Ytan.put('/admin/water/corrections/' + id, { note: input.value });
        showAdminToast(t('admin.water.note_saved'), 'success');
        await wxLoadCorrections();
    } catch (err) {
        input.disabled = false;
        showAdminToast(t('admin.water.save_failed', { error: translateApiError(err.data) || err.message }), 'error');
    }
}

async function wxDeleteCorrection(id) {
    var correction = wx.corrections.find(function (c) { return c.id === id; });
    if (!correction) {
        return;
    }
    if (!(await adminConfirm({
        title: t('admin.water.confirm_delete_title'),
        message: t('admin.water.confirm_delete', { id: id, type: wxTypeLabel(correction.type, correction.points) }),
        confirmLabel: t('admin.water.delete'),
        danger: true,
    }))) {
        return;
    }
    try {
        var answer = await Ytan.del('/admin/water/corrections/' + id);
        if (wx.selectedId === id) {
            wx.selectedId = null;
        }
        wxToastPois('admin.water.deleted', answer.data.pois);
        await wxLoadCorrections();
        wxLoad();
    } catch (err) {
        showAdminToast(t('admin.water.save_failed', { error: translateApiError(err.data) || err.message }), 'error');
    }
}

async function wxExport() {
    try {
        var answer = await Ytan.get('/admin/water/corrections/export');
        var blob = new Blob([JSON.stringify(answer.data)], { type: 'application/json' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'water-corrections-' + new Date().toISOString().slice(0, 10) + '.json';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(link.href); }, 1000);
        showAdminToast(t('admin.water.export_done', { count: answer.data.corrections.length }), 'success');
    } catch (err) {
        showAdminToast(t('admin.water.save_failed', { error: translateApiError(err.data) || err.message }), 'error');
    }
}

async function wxImport(input) {
    var file = input.files && input.files[0];
    input.value = '';
    if (!file) {
        return;
    }
    var data;
    try {
        data = JSON.parse(await file.text());
    } catch (err) {
        showAdminToast(t('admin.water.file_invalid'), 'error');
        return;
    }
    var count = data && Array.isArray(data.corrections) ? data.corrections.length : 0;
    if (!(await adminConfirm({
        title: t('admin.water.confirm_import_title'),
        message: t('admin.water.confirm_import', { count: count, name: file.name }),
        confirmLabel: t('admin.water.import'),
    }))) {
        return;
    }
    try {
        var answer = await Ytan.post('/admin/water/corrections/file', data);
        showAdminToast(t('admin.water.imported', { added: answer.data.added, skipped: answer.data.skipped }), 'success');
        await wxLoadCorrections();
        wxLoad();
    } catch (err) {
        showAdminToast(t('admin.water.save_failed', { error: translateApiError(err.data) || err.message }), 'error');
    }
}

// ---------------------------------------------------------------- init

function wxStoredView() {
    try {
        var view = JSON.parse(localStorage.getItem(WX_VIEW_KEY) || 'null');
        if (view && isFinite(view.lat) && isFinite(view.lng) && isFinite(view.zoom)) {
            return view;
        }
    } catch (err) {
        // storage unavailable - the default view
    }
    return { lat: 54.37, lng: 10.17, zoom: 13 };
}

function wxRememberView() {
    try {
        var centre = wx.map.getCenter();
        localStorage.setItem(WX_VIEW_KEY, JSON.stringify({ lat: centre.lat(), lng: centre.lng(), zoom: wx.map.getZoom() }));
    } catch (err) {
        // not essential
    }
}

async function wxInit() {
    wxLoadCorrections();
    try {
        await loadAdminGoogleMaps();
    } catch (err) {
        document.getElementById('wxNoKey').hidden = false;
        document.getElementById('wxMap').hidden = true;
        return;
    }
    var view = wxStoredView();
    wx.map = new google.maps.Map(document.getElementById('wxMap'), {
        center: { lat: view.lat, lng: view.lng },
        zoom: view.zoom,
        mapTypeId: 'hybrid',
        gestureHandling: 'greedy',
        streetViewControl: false,
        fullscreenControl: true,
        clickableIcons: false,
    });
    wxCreateOverlay();
    // the tools sit ON the map (top left, under the map type control): next to what is drawn,
    // and still there in Google's full-screen mode, which shows only the map's own element
    var tools = document.getElementById('wxTools');
    tools.hidden = false;
    wx.map.controls[google.maps.ControlPosition.LEFT_TOP].push(tools);
    wx.map.addListener('idle', function () {
        wxRender();
        wxRememberView();
        wxScheduleLoad();
    });
    wx.map.addListener('click', wxMapClick);
    wxRenderCorrections();
}

initAdminAuth({ contentId: 'adminAppWrapper', onReady: wxInit });
