import Toybox.Application;
import Toybox.Background;
import Toybox.Lang;
import Toybox.Time;
import Toybox.WatchUi;

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
        // Fetch right away when the activity opens, if Garmin allows it (at
        // least 5 minutes since the last fetch) - otherwise the first route
        // would only arrive one full interval later. The one-off event
        // switches to the periodic schedule once its result arrives.
        var last = Background.getLastTemporalEventTime();
        var now = Time.now();
        if (last == null || now.subtract(last).value() >= SYNC_INTERVAL_SECONDS) {
            Background.registerForTemporalEvent(now);
        } else {
            registerPeriodicSync();
        }
        _field = new YtanNavField();
        return [_field];
    }

    private function registerPeriodicSync() {
        Background.registerForTemporalEvent(new Time.Duration(SYNC_INTERVAL_SECONDS));
    }

    function getServiceDelegate() {
        return [new YtanSyncService()];
    }

    // Receives whatever YtanSyncService passed to Background.exit(): the
    // route payload, or {"e" => HTTP status} on failure.
    function onBackgroundData(data) {
        // After the one-off fetch from getInitialView() (whose registration
        // may already be gone, i.e. null), continue periodically.
        if (!(Background.getTemporalEventRegisteredTime() instanceof Time.Duration)) {
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
