import Toybox.Background;
import Toybox.Communications;
import Toybox.Lang;
import Toybox.PersistedContent;
import Toybox.System;

// Fetches the route currently "sent to the watch" in YTAN, via the phone's
// Garmin Connect app. The payload (src/Service/WatchRoutePayload.php) is kept
// small because Background.exit() only accepts a few KB.
(:background)
class YtanSyncService extends System.ServiceDelegate {
    function initialize() {
        ServiceDelegate.initialize();
    }

    // Every 5 minutes (and once when the activity opens), see YtanWatchApp.
    function onTemporalEvent() {
        fetchRoute();
    }

    // A wake-up from the YTAN Android app (GarminWatchPlugin, Connect IQ
    // Mobile SDK) right after a route was sent in YTAN: fetch now instead of
    // at the next 5-minute slot. The message itself carries nothing - the
    // server stays the only source of the route, so both ways deliver the
    // same payload.
    function onPhoneAppMessage(msg) {
        fetchRoute();
    }

    private function fetchRoute() {
        Communications.makeWebRequest(
            YtanConfig.SERVER_URL + "/api/v1/watch/device/route",
            null,
            {
                :method => Communications.HTTP_REQUEST_METHOD_GET,
                :headers => { "X-Watch-Token" => YtanConfig.WATCH_TOKEN },
                :responseType => Communications.HTTP_RESPONSE_CONTENT_TYPE_JSON
            },
            method(:onReceive)
        );
    }

    function onReceive(responseCode as Number, data as Dictionary or String or PersistedContent.Iterator or Null) as Void {
        if (responseCode == 200 && data instanceof Dictionary) {
            Background.exit(data);
        } else {
            Background.exit({ "e" => responseCode });
        }
    }
}
