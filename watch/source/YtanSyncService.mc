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
        // Only registered when the foreground saw a key. If the background
        // process sees none (e.g. the "watchKey" setting isn't readable
        // here), say so as "YTAN -1" instead of staying at "YTAN ?".
        if (!YtanKey.present()) {
            Background.exit({ "e" => -1 });
            return;
        }
        fetchRoute();
    }

    // A message from the YTAN Android app (GarminWatchPlugin, Connect IQ
    // Mobile SDK) right after a route was sent in YTAN:
    //  - the route payload itself (GET /watch/payload, same shape and same
    //    "v" as the server's answer to the watch key): hand it on as it is -
    //    this is how a build without a key gets its route;
    //  - anything else (the plain "sync" wake-up of older app versions):
    //    fetch now instead of at the next 5-minute slot, if there is a key.
    function onPhoneAppMessage(msg) {
        var data = msg.data;
        if (data instanceof Dictionary && data.hasKey("v") && data.hasKey("p")) {
            Background.exit(data);
            return;
        }
        fetchRoute();
    }

    private function fetchRoute() {
        if (!YtanKey.present()) {
            // Nothing to fetch with - the app sends the route (see above).
            Background.exit(null);
            return;
        }
        Communications.makeWebRequest(
            YtanConfig.SERVER_URL + "/api/v1/watch/device/route",
            null,
            {
                :method => Communications.HTTP_REQUEST_METHOD_GET,
                :headers => { "X-Watch-Token" => YtanKey.token() },
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
