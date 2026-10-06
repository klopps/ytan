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

    function onTemporalEvent() {
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
