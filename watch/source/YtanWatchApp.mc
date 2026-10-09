import Toybox.Application;
import Toybox.Background;
import Toybox.Lang;
import Toybox.Time;
import Toybox.WatchUi;

// Whether this build has a watch key (YtanConfig, bin/build-watch.bat). With
// one the data field fetches its route from the server itself; a build
// without ("app" variant) only takes what the YTAN Android app sends it over
// the phone connection (YtanSyncService.onPhoneAppMessage()). A build with a
// key does both - the app's message carries the same payload.
(:background)
module YtanKey {
    // The key: the "watchKey" setting if there is one (a .SET settings file
    // copied to GARMIN/APPS/SETTINGS - the only way to change a sideloaded
    // app's settings without a new build, there is no file access otherwise),
    // else the one compiled in.
    function token() as String {
        var configured = Application.Properties.getValue("watchKey");
        if (configured instanceof String && configured.length() > 0) {
            return configured;
        }
        return YtanConfig.WATCH_TOKEN;
    }

    function present() as Boolean {
        return token().length() > 0;
    }
}

// Annotated (:background) because the background process (YtanSyncService)
// starts through this same class; getInitialView() only ever runs in the
// foreground.
(:background)
class YtanWatchApp extends Application.AppBase {
    // Data fields may only fetch in the background, at most every 5 minutes.
    const SYNC_INTERVAL_SECONDS = 300;

    private var _field = null;

    function initialize() {
        AppBase.initialize();
    }

    function getInitialView() {
        // Fetch as early as Garmin allows when the activity opens: right away
        // if the last fetch was at least 5 minutes ago, else exactly 5
        // minutes after it - a periodic registration would count its first
        // interval from now, so reopening the activity shortly after a fetch
        // could cost up to 5 more minutes. The one-off event switches to the
        // periodic schedule once its result arrives.
        if (YtanKey.present()) {
            var last = Background.getLastTemporalEventTime();
            var now = Time.now();
            if (last == null || now.subtract(last).value() >= SYNC_INTERVAL_SECONDS) {
                Background.registerForTemporalEvent(now);
            } else {
                Background.registerForTemporalEvent(last.add(new Time.Duration(SYNC_INTERVAL_SECONDS)));
            }
        }
        // Lets the YTAN Android app send the route (or wake the background
        // process) right after it was sent (YtanSyncService.onPhoneAppMessage());
        // with a key the 5-minute fetch above stays as the fallback for
        // everything else, without one this is the only way a route arrives.
        if (Background has :registerForPhoneAppMessageEvent) {
            Background.registerForPhoneAppMessageEvent();
        }
        _field = new YtanNavField();
        return [_field];
    }

    private function registerPeriodicSync() {
        Background.registerForTemporalEvent(new Time.Duration(SYNC_INTERVAL_SECONDS));
    }

    // The "watchKey" setting changed while the data field runs (e.g. a new
    // .SET file was read): fetch with it right away.
    function onSettingsChanged() {
        if (YtanKey.present()) {
            Background.registerForTemporalEvent(Time.now());
        }
        WatchUi.requestUpdate();
    }

    function getServiceDelegate() {
        return [new YtanSyncService()];
    }

    // Receives whatever YtanSyncService passed to Background.exit(): the
    // route payload, or {"e" => HTTP status} on failure.
    function onBackgroundData(data) {
        // After the one-off fetch from getInitialView() (whose registration
        // may already be gone, i.e. null), continue periodically.
        if (YtanKey.present() && !(Background.getTemporalEventRegisteredTime() instanceof Time.Duration)) {
            registerPeriodicSync();
        }
        if (!(data instanceof Dictionary)) {
            return;
        }
        if (data.hasKey("e")) {
            if (_field != null) {
                _field.setSyncError(data["e"]);
            }
            return;
        }
        var now = Time.now().value();
        Application.Storage.setValue("route", data);
        Application.Storage.setValue("synced", now);
        if (_field != null) {
            _field.setRoute(data);
            _field.setSynced(now);
        }
        WatchUi.requestUpdate();
    }
}
