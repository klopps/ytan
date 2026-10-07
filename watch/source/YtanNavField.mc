import Toybox.Activity;
import Toybox.Application;
import Toybox.Attention;
import Toybox.Graphics;
import Toybox.Lang;
import Toybox.Math;
import Toybox.Position;
import Toybox.System;
import Toybox.Time;
import Toybox.Time.Gregorian;
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
    const INFO_FONT = Graphics.FONT_SMALL;
    const NAME_FONT = Graphics.FONT_XTINY;
    // Space between bearing and distance in their shared row.
    const VALUE_GAP = 14;
    // Gap between the middle of the bottom row and each of its two values.
    const TRAVEL_GAP = 8;
    // Scalable condensed system font for the values (fenix 7 and newer).
    const CONDENSED_FACE = "RobotoCondensedBold";
    const ETA_FONT = Graphics.FONT_SMALL;
    // A value row is its visible digits plus this much breathing room.
    const VALUE_ROW_FACTOR = 1.1d;
    // ETA: speed smoothed over ~5 minutes of paddling; below ~1 km/h counts
    // as a pause and doesn't change it. Shown after 30 s of paddling - or
    // right away if the user set a default speed in YTAN (payload "s"), which
    // then hands over to the measured speed linearly over those 5 minutes.
    const SPEED_WINDOW_S = 300.0d;
    const PAUSE_SPEED_MS = 0.28d;
    const MIN_MOVING_S = 30.0d;
    const SKIP_MARGIN_M = 50.0d;
    // Share of the field height kept free at an edge touching the bezel.
    const EDGE_INSET = 0.07d;
    // Compass markers (north, next waypoint) on the bezel of a full-screen
    // round field: triangle size as a share of the width.
    const MARKER_SIZE = 0.05d;
    // Text colors as 0xRRGGBB, [element][background]:
    //  - bearing (also the waypoint marker),
    //  - distance,
    //  - other text,
    //  - north marker
    // each on a dark and on a light background. These are the defaults; YTAN's profile can
    // override them (payload "c", same order, see WatchColors.php).
    const COLOR_BEARING = 0;
    const COLOR_DISTANCE = 1;
    const COLOR_TEXT = 2;
    const COLOR_NORTH = 3;
    const DEFAULT_COLORS = [
        0x20C0FF, 0x0000C0,
        0x80ff80, 0x008000,
        0xFFFFFF, 0x000000,
        0xFF0000, 0xFF0000
    ];
    // The two arrow symbols of the bottom row, hand-made pixel bitmaps
    // (watch/assets/*.png, 18x10 and 14x8): one hex digit per pixel, row by
    // row, 0 = transparent .. F = opaque. They are drawn pixel by pixel in
    // the text color, blended with the (black or white) background, so they
    // follow the configured color and stay anti-aliased.
    const ARROW_FROM_START_LARGE = "FA0000000000870000FA0000000001CF7000FA00000000001CF700FA000000000001CF70FECCCCCCCCCCCCDFF8FECCCCCCCCCCCCDFF7FA000000000001CF70FA00000000001CF700FA0000000001CF7000FA0000000000870000";
    const ARROW_TO_END_LARGE = "00000000004B1000AF00000000008FC100AF000000000008FC10AF0000000000008FC1AFCCCCCCCCCCCCCFFCBFCCCCCCCCCCCCCFFCBF0000000000008FC1AF000000000008FC10AF00000000008FC100AF00000000004B1000AF";
    const ARROW_FROM_START_SMALL = "F4000000069000F400000005F800F4000000005F80FBAAAAAAAAADF8FBAAAAAAAAADF8F4000000005F80F400000005F800F4000000069000";
    const ARROW_TO_END_SMALL = "00000000A5004F000000008F404F0000000009F44FAAAAAAAAAAFF8FAAAAAAAAAAFF8F0000000009F44F000000009F404F00000000A5004F";
    const ARROW_LARGE_WIDTH = 18;
    const ARROW_LARGE_HEIGHT = 10;
    const ARROW_SMALL_WIDTH = 14;
    const ARROW_SMALL_HEIGHT = 8;
    const EARTH_RADIUS_M = 6371000.0d;
    const DEG = 0.017453292519943295d; // PI / 180

    private var _colors = DEFAULT_COLORS;
    private var _version = null;
    private var _nautical = false;
    private var _lats = null; // Array of Double, degrees
    private var _lngs = null;
    private var _remainFrom = null; // route length from waypoint i to the finish, meters
    private var _speed = null; // smoothed measured speed, m/s
    private var _priorSpeed = null; // the user's default speed from YTAN, m/s
    private var _movingSeconds = 0.0d;
    private var _lastTimer = null;
    private var _next = -1; // index of the next waypoint, -1 = not determined yet
    private var _error = null;

    // What onUpdate() draws, set by compute(): either a status text, or
    // bearing + distance.
    private var _label = "";
    private var _clock = "";
    private var _heartRate = "--";
    private var _routeName = "";
    private var _waypoints = "";
    private var _status = null;
    private var _bearing = "";
    private var _bearingDeg = 0; // true bearing to the next waypoint, degrees
    private var _heading = null; // where the watch points, radians from true north
    private var _distance = "";
    private var _distanceUnit = "";
    private var _toFinish = "";
    private var _traveled = "--";
    private var _eta = "";

    private var _strLabel;
    private var _strEta;
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
        _strEta = WatchUi.loadResource(Rez.Strings.Eta);
        _label = _strLabel;
        _status = _strNoRoute;

        var stored = Application.Storage.getValue("route");
        if (stored instanceof Dictionary) {
            setRoute(stored);
        }
    }

    function setRoute(data as Dictionary) as Void {
        _error = null;
        // Colors apply even when the route itself is unchanged.
        var colors = data["c"];
        _colors = colors instanceof Array && colors.size() == DEFAULT_COLORS.size() ? colors : DEFAULT_COLORS;
        var prior = data["s"];
        _priorSpeed = (prior instanceof Number || prior instanceof Float || prior instanceof Double) && prior > 0 ? prior.toDouble() : null;
        var version = data["v"] as String?;
        _nautical = "n".equals(data["u"]);
        if (version != null && version.equals(_version)) {
            return;
        }
        _version = version;
        _next = -1;
        _speed = null;
        _movingSeconds = 0.0d;
        _lastTimer = null;
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

        var remain = new Array<Double>[count];
        remain[count - 1] = 0.0d;
        for (var i = count - 2; i >= 0; i--) {
            remain[i] = remain[i + 1] + distanceMeters(lats[i], lngs[i], lats[i + 1], lngs[i + 1]);
        }
        _remainFrom = remain;
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

    // Color of an element on the current background.
    private function colorOf(element, onDark) {
        return _colors[element * 2 + (onDark ? 0 : 1)];
    }

    function compute(info) {
        _label = _strLabel;
        _waypoints = "";
        _clock = clockText();
        var heartRate = info.currentHeartRate;
        _heartRate = heartRate != null ? heartRate.toString() : "--";
        if (_error != null) {
            _status = _error;
            return;
        }
        if (_lats == null) {
            _status = _strNoRoute;
            return;
        }
        var location = info.currentLocation;
        var speed = info.currentSpeed;
        // True-north heading: the compass when standing still, GPS course
        // when moving (Garmin picks), so it also works at a standstill.
        var heading = info.currentHeading;
        // Distance covered in this activity, as counted by the watch itself.
        var elapsed = info.elapsedDistance;
        if (location == null) {
            location = simulatedLocation();
            speed = simulatedSpeed();
            heading = simulatedHeading();
            elapsed = simulatedElapsedDistance();
        }
        if (heading != null) {
            _heading = heading;
        }
        if (location == null) {
            _status = _strNoGps;
            return;
        }
        updateSpeed(speed);
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
        _bearingDeg = bearingDegrees(lat, lng, _lats[_next], _lngs[_next]);
        _bearing = _bearingDeg.format("%03d");
        var toNext = distanceMeters(lat, lng, _lats[_next], _lngs[_next]);
        var next = formatDistance(toNext);
        _distance = next[0];
        _distanceUnit = next[1];
        var remaining = toNext + _remainFrom[_next];
        var finish = formatDistance(remaining);
        _toFinish = finish[0] + " " + finish[1];
        if (elapsed == null) {
            _traveled = "--";
        } else {
            var traveled = formatDistance(elapsed);
            _traveled = traveled[0] + " " + traveled[1];
        }
        _eta = etaText(remaining);
    }

    // Exponential moving average over ~SPEED_WINDOW_S of paddling - at the
    // start over the time paddled so far, so the first sample doesn't
    // dominate. Pauses (and gaps, e.g. activity paused) leave it unchanged.
    private function updateSpeed(speed) {
        var now = System.getTimer();
        var dt = _lastTimer == null ? 0.0d : (now - _lastTimer) / 1000.0d;
        _lastTimer = now;
        if (speed == null || speed < PAUSE_SPEED_MS || dt <= 0.0d || dt > 10.0d) {
            return;
        }
        _movingSeconds += dt;
        var window = _movingSeconds < SPEED_WINDOW_S ? _movingSeconds : SPEED_WINDOW_S;
        var alpha = dt / window;
        _speed = _speed == null ? speed.toDouble() : _speed + alpha * (speed - _speed);
    }

    // The speed the ETA uses: the measured one (once there are 30 s of it),
    // or - with a default speed set - the default, handing over to the
    // measurement as the paddled time approaches SPEED_WINDOW_S.
    private function effectiveSpeed() {
        if (_priorSpeed == null) {
            return (_speed == null || _movingSeconds < MIN_MOVING_S) ? null : _speed;
        }
        if (_speed == null) {
            return _priorSpeed;
        }
        var weight = _movingSeconds / SPEED_WINDOW_S;
        if (weight > 1.0d) {
            weight = 1.0d;
        }
        return _priorSpeed * (1.0d - weight) + _speed * weight;
    }

    // "15:42 (1:23 h)" for the remaining route distance in meters - the
    // "ETA " prefix is added in onUpdate() only where there is room for it.
    private function etaText(remaining) {
        var speed = effectiveSpeed();
        if (speed == null) {
            return "--:--";
        }
        var seconds = (remaining / speed).toNumber();
        if (seconds > 86400) {
            return "--";
        }
        var arrival = Gregorian.info(Time.now().add(new Time.Duration(seconds)), Time.FORMAT_SHORT);
        return formatClock(arrival.hour, arrival.min) + " (" + formatDuration(seconds) + ")";
    }

    // Draws the first [text, font] variant that fits maxWidth - so a row
    // degrades step by step (smaller font, shorter text) instead of being
    // cut off; only if none fits, `fallback` is shortened with "...".
    private function drawFirstFitting(dc, cx, cy, maxWidth, variants, fallback) {
        for (var i = 0; i < variants.size(); i++) {
            var text = variants[i][0];
            var font = variants[i][1];
            if (dc.getTextWidthInPixels(text, font) <= maxWidth) {
                dc.drawText(cx, cy, font, text, Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
                return;
            }
        }
        dc.drawText(cx, cy, Graphics.FONT_XTINY, fitText(dc, fallback, Graphics.FONT_XTINY, maxWidth),
            Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
    }

    private function formatDuration(seconds) {
        // Always hh:mm h, e.g. "01:40 h" or "00:05 h".
        var minutes = (seconds + 59) / 60;
        return (minutes / 60).format("%02d") + ":" + (minutes % 60).format("%02d") + " h";
    }

    // Time of day, 12/24 h as set on the watch.
    private function clockText() {
        var time = System.getClockTime();
        return formatClock(time.hour, time.min);
    }

    private function formatClock(hour, min) {
        if (!System.getDeviceSettings().is24Hour) {
            hour = hour % 12;
            if (hour == 0) {
                hour = 12;
            }
        }
        return hour + ":" + min.format("%02d");
    }

    // "14:32  [heart] 128", centered at (cx, cy). The heart is drawn, since
    // not every Garmin font has a heart glyph.
    private function drawInfoRow(dc, cx, cy) {
        var gap = 14;
        var heartSize = (Graphics.getFontAscent(INFO_FONT) * 0.6d).toNumber();
        var clockWidth = dc.getTextWidthInPixels(_clock, INFO_FONT);
        var hrWidth = dc.getTextWidthInPixels(_heartRate, INFO_FONT);
        var total = clockWidth + gap + heartSize + 4 + hrWidth;
        var x = cx - total / 2;
        dc.drawText(x, cy, INFO_FONT, _clock, Graphics.TEXT_JUSTIFY_LEFT | Graphics.TEXT_JUSTIFY_VCENTER);
        x += clockWidth + gap;
        drawHeart(dc, x, cy, heartSize);
        dc.drawText(x + heartSize + 4, cy, INFO_FONT, _heartRate, Graphics.TEXT_JUSTIFY_LEFT | Graphics.TEXT_JUSTIFY_VCENTER);
    }

    // Filled heart of the given width, vertically centered on cy.
    private function drawHeart(dc, x, cy, size) {
        var r = size / 4;
        var top = cy - size / 2 + r;
        dc.fillCircle(x + r, top, r);
        dc.fillCircle(x + size - r, top, r);
        dc.fillPolygon([[x, top], [x + size, top], [x + size / 2, cy + size / 2]]);
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
        var onDark = background == Graphics.COLOR_BLACK;
        var foreground = colorOf(COLOR_TEXT, onDark);
        dc.setColor(foreground, background);
        dc.clear();
        dc.setColor(foreground, Graphics.COLOR_TRANSPARENT);

        var width = dc.getWidth();
        var height = dc.getHeight();
        if (_heading != null && isFullScreenRound(width, height)) {
            drawMarker(dc, width, height, -_heading, colorOf(COLOR_NORTH, onDark));
            if (_status == null) {
                drawMarker(dc, width, height, _bearingDeg * DEG - _heading, colorOf(COLOR_BEARING, onDark));
            }
            dc.setColor(foreground, Graphics.COLOR_TRANSPARENT);
        }
        var labelHeight = Graphics.getFontHeight(Graphics.FONT_XTINY);

        // Keep label and counter off the bezel where the field touches it.
        var flags = getObscurityFlags();
        var inset = (height * EDGE_INSET).toNumber();
        var insetTop = (flags & OBSCURE_TOP) != 0 ? inset : 0;
        var insetBottom = (flags & OBSCURE_BOTTOM) != 0 ? inset : 0;

        dc.drawText(width / 2, insetTop, Graphics.FONT_XTINY, _label, Graphics.TEXT_JUSTIFY_CENTER);
        var infoHeight = Graphics.getFontHeight(INFO_FONT);
        drawInfoRow(dc, width / 2, insetTop + labelHeight + infoHeight / 2);

        // Waypoint counter on its own line at the bottom, mirroring the label.
        var hasWaypoints = !"".equals(_waypoints);
        if (hasWaypoints) {
            dc.drawText(width / 2, height - insetBottom - labelHeight, Graphics.FONT_XTINY, _waypoints, Graphics.TEXT_JUSTIFY_CENTER);
        }

        var top = insetTop + labelHeight + infoHeight;
        var area = height - top - insetBottom - (hasWaypoints ? labelHeight : 0);
        if (_status != null) {
            // With a route loaded (no GPS yet, or finished) the status still
            // says which route this is, in the same spot as in normal view.
            if (_error == null && _lats != null && !"".equals(_routeName)) {
                var statusNameHeight = textHeight(NAME_FONT);
                var nameRow = layoutRows(top, area, statusNameHeight, textHeight(ETA_FONT))[0];
                dc.drawText(width / 2, nameRow, NAME_FONT,
                    fitText(dc, _routeName, NAME_FONT, usableWidth(width, height, nameRow, statusNameHeight)),
                    Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
            }
            dc.drawText(width / 2, top + area / 2, Graphics.FONT_MEDIUM, _status,
                Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
            return;
        }

        // Route name at the top, bearing + distance side by side in the
        // middle, ETA and distance to finish at the bottom.
        var nameHeight = "".equals(_routeName) ? 0 : textHeight(NAME_FONT);
        var textRow = textHeight(ETA_FONT);
        var font = pickFont(dc, width, height, top, area, nameHeight, textRow);
        var unitFont = font == Graphics.FONT_SMALL ? Graphics.FONT_XTINY : Graphics.FONT_SMALL;
        var rows = layoutRows(top, area, nameHeight, textRow);
        var cx = width / 2;
        if (nameHeight > 0) {
            var name = fitText(dc, _routeName, NAME_FONT, usableWidth(width, height, rows[0], nameHeight));
            dc.drawText(cx, rows[0], NAME_FONT, name, Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
        }

        var bearingWidth = valueWidth(dc, _bearing, "°", font, unitFont);
        var x = cx - (bearingWidth + VALUE_GAP + valueWidth(dc, _distance, _distanceUnit, font, unitFont)) / 2;
        dc.setColor(colorOf(COLOR_BEARING, onDark), Graphics.COLOR_TRANSPARENT);
        drawValueAt(dc, x, rows[1], _bearing, "°", true, font, unitFont);
        dc.setColor(colorOf(COLOR_DISTANCE, onDark), Graphics.COLOR_TRANSPARENT);
        drawValueAt(dc, x + bearingWidth + VALUE_GAP, rows[1], _distance, _distanceUnit, false, font, unitFont);
        dc.setColor(foreground, Graphics.COLOR_TRANSPARENT);

        var eta = _strEta + " " + _eta;
        drawFirstFitting(dc, cx, rows[2], usableWidth(width, height, rows[2], textRow),
            [[eta, ETA_FONT], [eta, Graphics.FONT_XTINY], [_eta, Graphics.FONT_XTINY]], _eta);
        drawTraveledAndFinish(dc, cx, rows[3], usableWidth(width, height, rows[3], textRow), foreground, background);
    }

    // One row around the middle: "|-> 821 m" ends TRAVEL_GAP left of it
    // (distance covered since the start), "12.6 km ->|" starts TRAVEL_GAP
    // right of it (distance left to the finish). Each side has to
    // fit half the usable width: normal font, small font, then without the
    // space before the unit; if even that doesn't fit, the small one is drawn.
    private function drawTraveledAndFinish(dc, cx, cy, maxWidth, foreground, background) {
        var room = maxWidth / 2 - TRAVEL_GAP;
        var font = ETA_FONT;
        var compact = false;
        var traveled = _traveled;
        var toFinish = _toFinish;
        var arrow = 0;
        for (var step = 0; step < 4; step++) {
            font = step == 0 ? ETA_FONT : Graphics.FONT_XTINY;
            compact = step >= 2;
            traveled = compact ? withoutSpace(_traveled) : _traveled;
            toFinish = compact ? withoutSpace(_toFinish) : _toFinish;
            arrow = step == 3 ? ARROW_SMALL_WIDTH : ARROW_LARGE_WIDTH;
            var widest = dc.getTextWidthInPixels(traveled, font);
            var other = dc.getTextWidthInPixels(toFinish, font);
            if (other > widest) {
                widest = other;
            }
            if (arrow + 3 + widest <= room) {
                break;
            }
        }

        var left = cx - TRAVEL_GAP - (arrow + 3 + dc.getTextWidthInPixels(traveled, font));
        drawArrow(dc, left, cy, arrow == ARROW_SMALL_WIDTH, false, foreground, background);
        var textTop = cy + digitHeight(font) / 2 - Graphics.getFontAscent(font);
        dc.drawText(left + arrow + 3, textTop, font, traveled, Graphics.TEXT_JUSTIFY_LEFT);

        // The finish symbol goes behind its value, the start symbol in front.
        var right = cx + TRAVEL_GAP;
        dc.drawText(right, textTop, font, toFinish, Graphics.TEXT_JUSTIFY_LEFT);
        drawArrow(dc, right + dc.getTextWidthInPixels(toFinish, font) + 3, cy, arrow == ARROW_SMALL_WIDTH, true, foreground, background);
    }

    private function withoutSpace(text) {
        var space = text.find(" ");
        return space == null ? text : text.substring(0, space) + text.substring(space + 1, text.length());
    }

    // Arrow bitmap (the small one if `small`), left edge at x, vertically
    // centered on cy, with the bar at its tip (toEnd) or at its start. Each
    // pixel is the text color mixed into the background by its opacity.
    private function drawArrow(dc, x, cy, small, toEnd, foreground, background) {
        var data = small
            ? (toEnd ? ARROW_TO_END_SMALL : ARROW_FROM_START_SMALL)
            : (toEnd ? ARROW_TO_END_LARGE : ARROW_FROM_START_LARGE);
        var width = small ? ARROW_SMALL_WIDTH : ARROW_LARGE_WIDTH;
        var height = small ? ARROW_SMALL_HEIGHT : ARROW_LARGE_HEIGHT;
        var top = cy - height / 2;
        var digits = data.toCharArray();
        for (var i = 0; i < digits.size(); i++) {
            var code = digits[i].toNumber();
            var level = code <= 57 ? code - 48 : code - 55;
            if (level > 0) {
                dc.setColor(level == 15 ? foreground : mixColors(foreground, background, level), Graphics.COLOR_TRANSPARENT);
                dc.drawPoint(x + i % width, top + i / width);
            }
        }
        dc.setColor(foreground, Graphics.COLOR_TRANSPARENT);
    }

    // `foreground` at `level`/15 opacity over `background` (both 0xRRGGBB).
    private function mixColors(foreground, background, level) {
        var mixed = 0;
        for (var shift = 16; shift >= 0; shift -= 8) {
            var f = (foreground >> shift) & 0xFF;
            var b = (background >> shift) & 0xFF;
            mixed = (mixed << 8) | ((b * (15 - level) + f * level + 7) / 15);
        }
        return mixed;
    }

    // Vertical centers of [name, values, ETA, traveled + distance to finish]: the
    // route name hugs the info row at the top, ETA and distance to finish
    // sit right above the waypoint counter, and the values get all the
    // height in between.
    private function layoutRows(top, area, nameHeight, textRow) {
        var bottom = top + area;
        return [
            top + nameHeight / 2,
            (top + nameHeight + bottom - 2 * textRow) / 2,
            bottom - textRow - textRow / 2,
            bottom - textRow / 2
        ];
    }

    // Visible height of a text font (ascent + descent, without the extra
    // line spacing getFontHeight() includes).
    private function textHeight(font) {
        return Graphics.getFontAscent(font) + Graphics.getFontDescent(font);
    }

    // Simulator build only (watch\sim.jungle, bin\sim-watch.bat): without a
    // GPS fix, a position starts ~330 m south of the first waypoint and
    // moves SIM_STEP_M per call (compute() runs once a second) towards the
    // next waypoint - a fast-forward paddle along the real route.
    const SIM_STEP_M = 30.0d;
    private var _simLat = null;
    private var _simLng = null;
    private var _simHeading = null;
    private var _simTraveled = 0.0d;

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
        // Pointing 35 degrees off the course, so the waypoint marker visibly
        // differs from "straight ahead".
        _simHeading = bearingDegrees(_simLat, _simLng, _lats[target], _lngs[target]) * DEG - 0.61d;
        if (distance > 0.0d) {
            var fraction = SIM_STEP_M / distance;
            if (fraction > 1.0d) {
                fraction = 1.0d;
            }
            _simLat += (_lats[target] - _simLat) * fraction;
            _simLng += (_lngs[target] - _simLng) * fraction;
            _simTraveled += fraction < 1.0d ? SIM_STEP_M : distance;
        }
        return new Position.Location({ :latitude => _simLat, :longitude => _simLng, :format => :degrees });
    }

    (:device)
    private function simulatedLocation() {
        return null;
    }

    // Matches simulatedLocation()'s step per compute() call (~1 s).
    (:simulator)
    private function simulatedSpeed() {
        return _simLat == null ? null : SIM_STEP_M;
    }

    (:device)
    private function simulatedSpeed() {
        return null;
    }

    (:simulator)
    private function simulatedElapsedDistance() {
        return _simLat == null ? null : _simTraveled;
    }

    (:device)
    private function simulatedElapsedDistance() {
        return null;
    }

    (:simulator)
    private function simulatedHeading() {
        return _simHeading;
    }

    (:device)
    private function simulatedHeading() {
        return null;
    }

    // The value font: the scalable condensed system font where the watch has
    // one (sized to exactly fill the row), else the largest fixed number
    // font for which bearing + distance side by side fit their row.
    private function pickFont(dc, width, height, top, area, nameHeight, textRow) {
        var rows = layoutRows(top, area, nameHeight, textRow);
        var valuesHeight = area - nameHeight - 2 * textRow;
        var condensed = pickCondensed(dc, width, height, rows[1], valuesHeight);
        if (condensed != null) {
            return condensed;
        }
        for (var i = 0; i < NUMBER_FONTS.size(); i++) {
            var font = NUMBER_FONTS[i];
            var unitFont = font == Graphics.FONT_SMALL ? Graphics.FONT_XTINY : Graphics.FONT_SMALL;
            // Visible digits, not the padded font cell, have to fit.
            var glyphHeight = digitHeight(font);
            if (glyphHeight * VALUE_ROW_FACTOR > valuesHeight) {
                continue;
            }
            var both = valueWidth(dc, _bearing, "°", font, unitFont) + VALUE_GAP
                + valueWidth(dc, _distance, _distanceUnit, font, unitFont);
            if (both > usableWidth(width, height, rows[1], glyphHeight)) {
                continue;
            }
            return font;
        }
        return Graphics.FONT_SMALL;
    }

    // Scalable system font (Connect IQ 4.2+, e.g. fenix 7): measured once at
    // 100 px, then scaled so the digits fill the row's height - or its
    // width, whichever is tighter. null where the watch has no such font.
    private var _probeFont = null;
    private var _condensedFont = null;
    private var _condensedSize = 0;

    private function pickCondensed(dc, width, height, cy, valuesHeight) {
        if (!(Graphics has :getVectorFont)) {
            return null;
        }
        if (_probeFont == null) {
            _probeFont = Graphics.getVectorFont({ :face => CONDENSED_FACE, :size => 100 });
            if (_probeFont == null) {
                return null;
            }
        }
        var unitFont = Graphics.FONT_SMALL;
        var digitPerPx = digitHeight(_probeFont) / 100.0d;
        var widthPerPx = (dc.getTextWidthInPixels(_bearing, _probeFont) + dc.getTextWidthInPixels(_distance, _probeFont)) / 100.0d;
        var fixed = dc.getTextWidthInPixels("°", unitFont) + dc.getTextWidthInPixels(_distanceUnit, unitFont) + 4 + VALUE_GAP;
        var size = valuesHeight / VALUE_ROW_FACTOR / digitPerPx;
        // The usable width depends on the row's height on a round display -
        // a couple of rounds settle it.
        for (var i = 0; i < 3; i++) {
            var byWidth = (usableWidth(width, height, cy, size * digitPerPx) - fixed) / widthPerPx;
            if (byWidth < size) {
                size = byWidth;
            }
        }
        // Even sizes only, so a changing value doesn't rebuild the font
        // every second.
        var px = (size.toNumber() / 2) * 2;
        if (px < 12) {
            return null;
        }
        if (px != _condensedSize) {
            _condensedFont = Graphics.getVectorFont({ :face => CONDENSED_FACE, :size => px });
            _condensedSize = px;
        }
        return _condensedFont;
    }

    private function isFullScreenRound(width, height) {
        var settings = System.getDeviceSettings();
        return settings.screenShape == System.SCREEN_SHAPE_ROUND
            && width == settings.screenWidth && height == settings.screenHeight;
    }

    private function markerSize(width) {
        return (width * MARKER_SIZE).toNumber();
    }

    // Ring width taken by the markers, including a small gap to the text.
    private function markerDepth(width) {
        return markerSize(width) + 5;
    }

    // Triangle on the bezel, pointing at the center; `angle` in radians
    // clockwise from the top of the screen.
    private function drawMarker(dc, width, height, angle, color) {
        var size = markerSize(width);
        var ux = Math.sin(angle);
        var uy = -Math.cos(angle);
        var outer = width / 2.0d - 1;
        var inner = outer - size;
        var half = size * 0.6d;
        var cx = width / 2.0d;
        var cy = height / 2.0d;
        dc.setColor(color, Graphics.COLOR_TRANSPARENT);
        dc.fillPolygon([
            [(cx + ux * outer - uy * half).toNumber(), (cy + uy * outer + ux * half).toNumber()],
            [(cx + ux * outer + uy * half).toNumber(), (cy + uy * outer - ux * half).toNumber()],
            [(cx + ux * inner).toNumber(), (cy + uy * inner).toNumber()]
        ]);
    }

    // On a round display a full-screen field loses its corners: the visible
    // width of a text line is the circle's chord at the line's outer edge.
    // Smaller fields only know which sides touch the bezel, so they just
    // keep a margin there.
    private function usableWidth(width, height, cy, glyphHeight) {
        if (isFullScreenRound(width, height)) {
            // The compass markers take the outermost ring.
            var center = width / 2.0d;
            var r = center - markerDepth(width);
            var dy = (cy - center).abs() + glyphHeight / 2.0d;
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

    // Number and unit starting at x, vertically centered on cy. The degree
    // sign sits at the top of the digits; a distance unit shares their
    // baseline. The number is drawn by its top edge so its baseline (top +
    // ascent) is known exactly - number fonts carry a lot of padding, so
    // estimating it from the vertical center put the unit far too low.
    private function drawValueAt(dc, x, cy, value, unit, unitAtTop, font, unitFont) {
        var valueWidthPx = dc.getTextWidthInPixels(value, font);
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

    // Digits fill about 72% of a fixed number font's ascent - the rest is
    // padding above them - and 91% of the scalable condensed font's (both
    // measured in the simulator; 0.91 puts the degree sign's top exactly
    // level with the digits').
    private function digitHeight(font) {
        var ratio = (Graphics has :VectorFont && font instanceof Graphics.VectorFont) ? 0.91d : 0.72d;
        return (Graphics.getFontAscent(font) * ratio).toNumber();
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

    // [number, unit]: m below 1 km, then km (2 decimals below 10 km) - or
    // nautical miles, as set in YTAN.
    private function formatDistance(meters) {
        if (_nautical) {
            var nm = meters / 1852.0d;
            return [nm.format(nm < 10.0d ? "%.2f" : "%.1f"), _strNm];
        }
        if (meters < 1000.0d) {
            return [meters.toNumber().toString(), "m"];
        }
        var km = meters / 1000.0d;
        return [km.format(km < 10.0d ? "%.2f" : "%.1f"), "km"];
    }
}
