import Toybox.Activity;
import Toybox.Application;
import Toybox.Attention;
import Toybox.Graphics;
import Toybox.Lang;
import Toybox.Math;
import Toybox.Position;
import Toybox.System;
import Toybox.WatchUi;

// Shows bearing (degrees, true north) and distance to the next waypoint of
// the YTAN route on two lines, each in the largest number font that fits
// the field, under a small label "YTAN 3/12". A waypoint counts as reached
// within ARRIVAL_RADIUS_M; when the field starts mid-route it picks the
// waypoint ahead of the closest route segment.
class YtanNavField extends WatchUi.DataField {
    // Largest first. Number fonts only contain digits (and "." "-"), so
    // units and the degree sign are drawn in a text font next to them.
    const NUMBER_FONTS = [
        Graphics.FONT_NUMBER_THAI_HOT,
        Graphics.FONT_NUMBER_HOT,
        Graphics.FONT_NUMBER_MEDIUM,
        Graphics.FONT_NUMBER_MILD,
        Graphics.FONT_LARGE,
        Graphics.FONT_MEDIUM,
        Graphics.FONT_SMALL
    ];
    const ARRIVAL_RADIUS_M = 50.0d;
    const LOOKAHEAD_SEGMENTS = 10;
    const SKIP_MARGIN_M = 50.0d;
    // Share of the field height kept free at an edge touching the bezel.
    const EDGE_INSET = 0.07d;
    const EARTH_RADIUS_M = 6371000.0d;
    const DEG = 0.017453292519943295d; // PI / 180

    private var _version = null;
    private var _nautical = false;
    private var _lats = null; // Array of Double, degrees
    private var _lngs = null;
    private var _next = -1; // index of the next waypoint, -1 = not determined yet
    private var _error = null;

    // What onUpdate() draws, set by compute(): either a status text, or
    // bearing + distance.
    private var _label = "";
    private var _routeName = "";
    private var _waypoints = "";
    private var _status = null;
    private var _bearing = "";
    private var _distance = "";
    private var _distanceUnit = "";

    private var _strLabel;
    private var _strNoRoute;
    private var _strNoGps;
    private var _strFinish;
    private var _strFinishReached;
    private var _strBadToken;
    private var _strNm;

    function initialize() {
        DataField.initialize();
        _strLabel = WatchUi.loadResource(Rez.Strings.Label);
        _strNoRoute = WatchUi.loadResource(Rez.Strings.NoRoute);
        _strNoGps = WatchUi.loadResource(Rez.Strings.NoGps);
        _strFinish = WatchUi.loadResource(Rez.Strings.Finish);
        _strFinishReached = WatchUi.loadResource(Rez.Strings.FinishReached);
        _strBadToken = WatchUi.loadResource(Rez.Strings.BadToken);
        _strNm = WatchUi.loadResource(Rez.Strings.UnitNauticalMiles);
        _label = _strLabel;
        _status = _strNoRoute;

        var stored = Application.Storage.getValue("route");
        if (stored instanceof Dictionary) {
            setRoute(stored);
        }
    }

    function setRoute(data as Dictionary) as Void {
        _error = null;
        var version = data["v"] as String?;
        _nautical = "n".equals(data["u"]);
        if (version != null && version.equals(_version)) {
            return;
        }
        _version = version;
        _next = -1;
        var name = data["n"];
        _routeName = name instanceof String ? name : "";

        var flat = data["p"] as Array<Number>?;
        if (flat == null || flat.size() < 2) {
            _lats = null;
            _lngs = null;
            return;
        }
        var count = flat.size() / 2;
        var lats = new Array<Double>[count];
        var lngs = new Array<Double>[count];
        for (var i = 0; i < count; i++) {
            lats[i] = flat[2 * i].toDouble() / 100000.0d;
            lngs[i] = flat[2 * i + 1].toDouble() / 100000.0d;
        }
        _lats = lats;
        _lngs = lngs;
    }

    // A failed sync only matters if there is nothing to navigate with yet,
    // or the watch key itself was rejected.
    function setSyncError(code) {
        if (code == 401) {
            _error = _strBadToken;
        } else if (_lats == null) {
            _error = _strNoRoute + " (" + code + ")";
        }
    }

    function compute(info) {
        _label = _strLabel;
        _waypoints = "";
        if (_error != null) {
            _status = _error;
            return;
        }
        if (_lats == null) {
            _status = _strNoRoute;
            return;
        }
        var location = info.currentLocation;
        if (location == null) {
            location = simulatedLocation();
        }
        if (location == null) {
            _status = _strNoGps;
            return;
        }
        var position = location.toDegrees();
        var lat = position[0].toDouble();
        var lng = position[1].toDouble();
        var count = _lats.size();

        // No alert for where navigation starts (activity just opened, or a
        // new route) - only for waypoints reached while navigating.
        var starting = _next < 0;
        if (starting) {
            _next = initialWaypoint(lat, lng);
        }
        var before = _next;
        while (_next < count && distanceMeters(lat, lng, _lats[_next], _lngs[_next]) <= ARRIVAL_RADIUS_M) {
            _next++;
        }
        skipAhead(lat, lng);
        if (!starting && _next > before) {
            if (_next >= count) {
                alertFinish();
            } else {
                alertWaypoint();
            }
        }
        if (_next >= count) {
            _waypoints = count + "/" + count;
            _status = _strFinish;
            return;
        }

        _waypoints = (_next + 1) + "/" + count;
        _status = null;
        _bearing = bearingDegrees(lat, lng, _lats[_next], _lngs[_next]).format("%03d");
        setDistance(distanceMeters(lat, lng, _lats[_next], _lngs[_next]));
    }

    // Waypoint reached (or passed via a shortcut): one short buzz and the
    // lap tone - as far as the watch's own tone/vibration settings allow.
    private function alertWaypoint() {
        var settings = System.getDeviceSettings();
        if (Attention has :vibrate && settings.vibrateOn) {
            Attention.vibrate([new Attention.VibeProfile(100, 400)]);
        }
        if (Attention has :playTone && settings.tonesOn) {
            Attention.playTone(Attention.TONE_LAP);
        }
    }

    // Finish: three long buzzes, the success tone and a full-screen alert.
    private function alertFinish() {
        var settings = System.getDeviceSettings();
        if (Attention has :vibrate && settings.vibrateOn) {
            Attention.vibrate([
                new Attention.VibeProfile(100, 700),
                new Attention.VibeProfile(0, 250),
                new Attention.VibeProfile(100, 700),
                new Attention.VibeProfile(0, 250),
                new Attention.VibeProfile(100, 700)
            ]);
        }
        if (Attention has :playTone && settings.tonesOn) {
            Attention.playTone(Attention has :TONE_SUCCESS ? Attention.TONE_SUCCESS : Attention.TONE_ALARM);
        }
        if (WatchUi.DataField has :showAlert) {
            showAlert(new YtanFinishAlert(_strFinishReached));
        }
    }

    function onUpdate(dc as Graphics.Dc) as Void {
        var background = getBackgroundColor();
        var foreground = background == Graphics.COLOR_BLACK ? Graphics.COLOR_WHITE : Graphics.COLOR_BLACK;
        dc.setColor(foreground, background);
        dc.clear();
        dc.setColor(foreground, Graphics.COLOR_TRANSPARENT);

        var width = dc.getWidth();
        var height = dc.getHeight();
        var labelHeight = Graphics.getFontHeight(Graphics.FONT_XTINY);

        // Keep label and counter off the bezel where the field touches it.
        var flags = getObscurityFlags();
        var inset = (height * EDGE_INSET).toNumber();
        var insetTop = (flags & OBSCURE_TOP) != 0 ? inset : 0;
        var insetBottom = (flags & OBSCURE_BOTTOM) != 0 ? inset : 0;

        dc.drawText(width / 2, insetTop, Graphics.FONT_XTINY, _label, Graphics.TEXT_JUSTIFY_CENTER);

        // Waypoint counter on its own line at the bottom, mirroring the label.
        var hasWaypoints = !"".equals(_waypoints);
        if (hasWaypoints) {
            dc.drawText(width / 2, height - insetBottom - labelHeight, Graphics.FONT_XTINY, _waypoints, Graphics.TEXT_JUSTIFY_CENTER);
        }

        var top = insetTop + labelHeight;
        var area = height - top - insetBottom - (hasWaypoints ? labelHeight : 0);
        if (_status != null) {
            dc.drawText(width / 2, top + area / 2, Graphics.FONT_MEDIUM, _status,
                Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
            return;
        }

        // Route name between the two values - the widest part of a round
        // display. Small font if the whole name fits, else the smallest
        // font, shortened with "..." if it still doesn't.
        var nameFont = Graphics.FONT_SMALL;
        var nameHeight = 0;
        var name = "";
        if (!"".equals(_routeName)) {
            var nameWidth = usableWidth(width, height, top + area / 2, Graphics.getFontHeight(nameFont));
            if (dc.getTextWidthInPixels(_routeName, nameFont) > nameWidth) {
                nameFont = Graphics.FONT_XTINY;
            }
            name = fitText(dc, _routeName, nameFont, nameWidth);
            nameHeight = Graphics.getFontHeight(nameFont);
        }

        // The two values share the rest; one font for both, so they look
        // like a pair.
        var lineHeight = (area - nameHeight) / 2;
        var cy1 = top + lineHeight / 2;
        var cy2 = top + lineHeight + nameHeight + lineHeight / 2;
        var font = pickFont(dc, width, height, lineHeight, cy1, cy2);
        var unitFont = font == Graphics.FONT_SMALL ? Graphics.FONT_XTINY : Graphics.FONT_SMALL;
        drawValue(dc, width / 2, cy1, _bearing, "°", true, font, unitFont);
        if (nameHeight > 0) {
            dc.drawText(width / 2, top + lineHeight + nameHeight / 2, nameFont, name,
                Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
        }
        drawValue(dc, width / 2, cy2, _distance, _distanceUnit, false, font, unitFont);
    }

    // Simulator build only (watch\sim.jungle, bin\sim-watch.bat): without a
    // GPS fix, a position starts ~330 m south of the first waypoint and
    // moves SIM_STEP_M per call (compute() runs once a second) towards the
    // next waypoint - a fast-forward paddle along the real route.
    const SIM_STEP_M = 30.0d;
    private var _simLat = null;
    private var _simLng = null;

    (:simulator)
    private function simulatedLocation() {
        if (_lats == null) {
            return null;
        }
        var count = _lats.size();
        if (_simLat == null) {
            _simLat = _lats[0] - 0.003d;
            _simLng = _lngs[0];
        }
        var target = _next < 0 ? 0 : (_next >= count ? count - 1 : _next);
        var distance = distanceMeters(_simLat, _simLng, _lats[target], _lngs[target]);
        if (distance > 0.0d) {
            var fraction = SIM_STEP_M / distance;
            if (fraction > 1.0d) {
                fraction = 1.0d;
            }
            _simLat += (_lats[target] - _simLat) * fraction;
            _simLng += (_lngs[target] - _simLng) * fraction;
        }
        return new Position.Location({ :latitude => _simLat, :longitude => _simLng, :format => :degrees });
    }

    (:device)
    private function simulatedLocation() {
        return null;
    }

    // The largest font in which both values (plus units) fit their line.
    private function pickFont(dc, width, height, lineHeight, cy1, cy2) {
        for (var i = 0; i < NUMBER_FONTS.size(); i++) {
            var font = NUMBER_FONTS[i];
            var unitFont = font == Graphics.FONT_SMALL ? Graphics.FONT_XTINY : Graphics.FONT_SMALL;
            // Visible digits, not the padded font cell, have to fit.
            var glyphHeight = digitHeight(font);
            if (glyphHeight > lineHeight * 0.85d) {
                continue;
            }
            if (valueWidth(dc, _bearing, "°", font, unitFont) > usableWidth(width, height, cy1, glyphHeight)
                || valueWidth(dc, _distance, _distanceUnit, font, unitFont) > usableWidth(width, height, cy2, glyphHeight)) {
                continue;
            }
            return font;
        }
        return Graphics.FONT_SMALL;
    }

    // On a round display a full-screen field loses its corners: the visible
    // width of a text line is the circle's chord at the line's outer edge.
    // Smaller fields only know which sides touch the bezel, so they just
    // keep a margin there.
    private function usableWidth(width, height, cy, glyphHeight) {
        var settings = System.getDeviceSettings();
        if (settings.screenShape == System.SCREEN_SHAPE_ROUND
            && width == settings.screenWidth && height == settings.screenHeight) {
            var r = width / 2.0d;
            var dy = (cy - r).abs() + glyphHeight / 2.0d;
            if (dy >= r) {
                return 0;
            }
            return 2.0d * Math.sqrt(r * r - dy * dy) - 8;
        }
        var flags = getObscurityFlags();
        if ((flags & (OBSCURE_LEFT | OBSCURE_RIGHT)) != 0) {
            return width * 0.8d;
        }
        return width - 4;
    }

    // Shortens text with "..." until it fits maxWidth (route names can be
    // long, the top line of a round display is narrow).
    private function fitText(dc, text, font, maxWidth) {
        if (dc.getTextWidthInPixels(text, font) <= maxWidth) {
            return text;
        }
        var length = text.length();
        while (length > 1) {
            length--;
            var shortened = text.substring(0, length) + "...";
            if (dc.getTextWidthInPixels(shortened, font) <= maxWidth) {
                return shortened;
            }
        }
        return "...";
    }

    private function valueWidth(dc, value, unit, font, unitFont) {
        return dc.getTextWidthInPixels(value, font) + 2 + dc.getTextWidthInPixels(unit, unitFont);
    }

    // Number and unit centered together at (cx, cy). The degree sign sits
    // at the top of the digits; a distance unit shares their baseline. The
    // number is drawn by its top edge so its baseline (top + ascent) is
    // known exactly - number fonts carry a lot of padding, so estimating it
    // from the vertical center put the unit far too low.
    private function drawValue(dc, cx, cy, value, unit, unitAtTop, font, unitFont) {
        var valueWidthPx = dc.getTextWidthInPixels(value, font);
        var x = cx - valueWidth(dc, value, unit, font, unitFont) / 2;
        // Center the visible digits (not the padded font cell) on cy.
        var digits = digitHeight(font);
        var baseline = cy + digits / 2;
        dc.drawText(x, baseline - Graphics.getFontAscent(font), font, value, Graphics.TEXT_JUSTIFY_LEFT);

        var unitX = x + valueWidthPx + 2;
        if (unitAtTop) {
            dc.drawText(unitX, baseline - digits, unitFont, unit, Graphics.TEXT_JUSTIFY_LEFT);
        } else {
            dc.drawText(unitX, baseline - Graphics.getFontAscent(unitFont), unitFont, unit, Graphics.TEXT_JUSTIFY_LEFT);
        }
    }

    // Digits fill about 72% of a font's ascent (checked in the simulator);
    // the rest is padding above them.
    private function digitHeight(font) {
        return (Graphics.getFontAscent(font) * 0.72d).toNumber();
    }

    // At start: the end of the route segment closest to the current position
    // - or waypoint 0, if the position is before the start of segment 0
    // (e.g. still on the way to the put-in). The earliest segment within
    // SKIP_MARGIN_M of the closest one wins: on a round trip start and
    // finish are close together, and it must not start at the finish.
    private function initialWaypoint(lat, lng) {
        var count = _lats.size();
        if (count == 1) {
            return 0;
        }
        var cosLat = Math.cos(lat * DEG);
        var closest = -1.0d;
        for (var i = 0; i <= count - 2; i++) {
            var d = segmentDistance(lat, lng, i, cosLat);
            if (closest < 0.0d || d < closest) {
                closest = d;
            }
        }
        for (var i = 0; i <= count - 2; i++) {
            if (segmentDistance(lat, lng, i, cosLat) <= closest + SKIP_MARGIN_M) {
                return (i == 0 && _beforeSegmentStart) ? 0 : i + 1;
            }
        }
        return 0;
    }

    // Shortcut detection: the next waypoint moves on to the end of a later
    // segment (within LOOKAHEAD_SEGMENTS) once that segment is clearly -
    // by SKIP_MARGIN_M - closer than the leg leading to the current
    // waypoint. The margin keeps overlapping legs (out and back on the same
    // line, where both are equally close) from jumping to the way back.
    private function skipAhead(lat, lng) {
        var count = _lats.size();
        if (_next >= count || count < 2) {
            return;
        }
        var cosLat = Math.cos(lat * DEG);
        var current = _next > 0
            ? segmentDistance(lat, lng, _next - 1, cosLat)
            : distanceMeters(lat, lng, _lats[0], _lngs[0]);
        var last = _next + LOOKAHEAD_SEGMENTS - 1;
        if (last > count - 2) {
            last = count - 2;
        }
        var best = -1;
        var bestDistance = 0.0d;
        for (var i = _next; i <= last; i++) {
            var d = segmentDistance(lat, lng, i, cosLat);
            if (best < 0 || d < bestDistance) {
                best = i;
                bestDistance = d;
            }
        }
        if (best >= 0 && bestDistance < current - SKIP_MARGIN_M) {
            _next = best + 1;
        }
    }

    // Distance in meters from the position to segment i (waypoint i to
    // i + 1), via a local flat projection - accurate at segment scale. Sets
    // _beforeSegmentStart if the position lies before the segment's start.
    private var _beforeSegmentStart = false;

    private function segmentDistance(lat, lng, i, cosLat) {
        var ax = (_lngs[i] - lng) * DEG * EARTH_RADIUS_M * cosLat;
        var ay = (_lats[i] - lat) * DEG * EARTH_RADIUS_M;
        var bx = (_lngs[i + 1] - lng) * DEG * EARTH_RADIUS_M * cosLat;
        var by = (_lats[i + 1] - lat) * DEG * EARTH_RADIUS_M;
        var dx = bx - ax;
        var dy = by - ay;
        var lengthSq = dx * dx + dy * dy;
        var t = 0.0d;
        if (lengthSq > 0.0d) {
            t = -(ax * dx + ay * dy) / lengthSq;
        }
        _beforeSegmentStart = t <= 0.0d;
        if (t < 0.0d) {
            t = 0.0d;
        } else if (t > 1.0d) {
            t = 1.0d;
        }
        var px = ax + t * dx;
        var py = ay + t * dy;
        return Math.sqrt(px * px + py * py);
    }

    private function distanceMeters(lat1, lng1, lat2, lng2) {
        var p1 = lat1 * DEG;
        var p2 = lat2 * DEG;
        var dp = (lat2 - lat1) * DEG;
        var dl = (lng2 - lng1) * DEG;
        var sinDp = Math.sin(dp / 2.0d);
        var sinDl = Math.sin(dl / 2.0d);
        var h = sinDp * sinDp + Math.cos(p1) * Math.cos(p2) * sinDl * sinDl;
        return EARTH_RADIUS_M * 2.0d * Math.atan2(Math.sqrt(h), Math.sqrt(1.0d - h));
    }

    // Initial great-circle bearing, 0-359, true north.
    private function bearingDegrees(lat1, lng1, lat2, lng2) {
        var p1 = lat1 * DEG;
        var p2 = lat2 * DEG;
        var dl = (lng2 - lng1) * DEG;
        var y = Math.sin(dl) * Math.cos(p2);
        var x = Math.cos(p1) * Math.sin(p2) - Math.sin(p1) * Math.cos(p2) * Math.cos(dl);
        var degrees = Math.atan2(y, x) / DEG;
        if (degrees < 0.0d) {
            degrees += 360.0d;
        }
        return (degrees + 0.5d).toNumber() % 360;
    }

    private function setDistance(meters) {
        if (_nautical) {
            var nm = meters / 1852.0d;
            _distance = nm.format(nm < 10.0d ? "%.2f" : "%.1f");
            _distanceUnit = _strNm;
        } else if (meters < 1000.0d) {
            _distance = meters.toNumber().toString();
            _distanceUnit = "m";
        } else {
            var km = meters / 1000.0d;
            _distance = km.format(km < 10.0d ? "%.2f" : "%.1f");
            _distanceUnit = "km";
        }
    }
}
