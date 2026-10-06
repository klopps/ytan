import Toybox.Graphics;
import Toybox.Lang;
import Toybox.WatchUi;

// Full-screen alert when the last waypoint is reached - shown by the system
// on top of the activity screen like Garmin's own alerts, dismissed by the
// watch after a few seconds or with a button.
class YtanFinishAlert extends WatchUi.DataFieldAlert {
    private var _text;

    function initialize(text) {
        DataFieldAlert.initialize();
        _text = text;
    }

    function onUpdate(dc as Graphics.Dc) as Void {
        dc.setColor(Graphics.COLOR_WHITE, Graphics.COLOR_BLACK);
        dc.clear();
        var cx = dc.getWidth() / 2;
        var cy = dc.getHeight() / 2;
        // A simple flag as the finish symbol above the text.
        var pole = dc.getHeight() / 6;
        dc.setPenWidth(4);
        dc.drawLine(cx - pole / 2, cy - pole / 3, cx - pole / 2, cy - pole / 3 - pole);
        dc.fillPolygon([
            [cx - pole / 2, cy - pole / 3 - pole],
            [cx + pole / 2, cy - pole / 3 - pole * 3 / 4],
            [cx - pole / 2, cy - pole / 3 - pole / 2]
        ]);
        dc.drawText(cx, cy + pole / 3, Graphics.FONT_LARGE, _text,
            Graphics.TEXT_JUSTIFY_CENTER | Graphics.TEXT_JUSTIFY_VCENTER);
    }
}
