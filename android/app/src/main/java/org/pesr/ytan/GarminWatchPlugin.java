package org.pesr.ytan;

import android.os.Handler;
import android.os.Looper;

import com.garmin.android.connectiq.ConnectIQ;
import com.garmin.android.connectiq.IQApp;
import com.garmin.android.connectiq.IQDevice;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.Iterator;
import java.util.List;
import java.util.Map;

/**
 * App-local plugin (same convention as AppInfoPlugin) that wakes the YTAN
 * Garmin data field (watch/) right after a route was sent in YTAN, so it
 * fetches the route within seconds instead of at its next 5-minute
 * background slot. Uses the Connect IQ Mobile SDK, which talks to the watch
 * through the Garmin Connect app on this phone.
 *
 * The message is only a wake-up ("sync"): the data field reacts in
 * YtanSyncService.onPhoneAppMessage() by fetching the route from the server
 * exactly like its periodic fetch does, so the server stays the only source
 * of the route. Everything here is best effort - if Garmin Connect is
 * missing, no watch is connected or a send fails, wake() still resolves
 * (sent: 0) and the watch picks the route up at its next regular fetch.
 *
 * send({payload}) goes one step further: the message carries the route
 * payload itself (the one GET /watch/payload returns for the signed-in user,
 * src/Service/WatchRoutePayload.php), so a watch build needs no watch key for
 * this path - the app's login is what says whose route it is. The data field
 * (YtanSyncService.onPhoneAppMessage()) takes such a message as the route.
 *
 * Proven on a real fenix 7X before this was built (todo.md "Routen an
 * Garmin Smartwatches schneller übertragen"): the SDK accepts the sideloaded
 * app, and the data field receives the message in the background even
 * while another data page is shown.
 */
@CapacitorPlugin(name = "GarminWatch")
public class GarminWatchPlugin extends Plugin {

    // watch/manifest.xml's application id, in the UUID form the SDK expects.
    private static final String WATCH_APP_ID = "cf4d0fb9-07f4-3f14-0f86-9d4f3b338b09";
    // Upper bound for one wake() - the SDK's own callbacks normally answer
    // within a second or two; this only guards against one never arriving.
    private static final long WAKE_TIMEOUT_MS = 8000;

    private ConnectIQ connectIQ;
    private boolean sdkReady = false;
    private boolean sdkInitializing = false;
    // wake() calls that arrived while the SDK was still initializing.
    private final List<PluginCall> pendingCalls = new ArrayList<>();
    private final Handler mainHandler = new Handler(Looper.getMainLooper());

    /**
     * Lets the JS side tell an APK with send() from an older one (calling a
     * method the plugin doesn't have rejects there).
     */
    @PluginMethod
    public void available(PluginCall call) {
        JSObject answer = new JSObject();
        answer.put("send", true);
        call.resolve(answer);
    }

    /**
     * Sends the route payload ({payload: {v, n, u, p, c, s?}}) to the watch -
     * same answer and best-effort behavior as wake().
     */
    @PluginMethod
    public void send(PluginCall call) {
        if (call.getObject("payload") == null) {
            call.reject("payload missing");
            return;
        }
        wake(call);
    }

    /**
     * Resolves {sent, devices, reason?}: sent = number of connected watches
     * that confirmed the message, devices = number of connected watches;
     * reason is set when nothing could be sent (e.g. "no_device",
     * "sdk_GARMIN_CONNECT_MOBILE_NOT_INSTALLED"). Never rejects. Without a
     * payload in the call the message is the plain "sync" wake-up.
     */
    @PluginMethod
    public void wake(PluginCall call) {
        mainHandler.post(() -> {
            if (sdkReady) {
                sendWake(call);
                return;
            }
            pendingCalls.add(call);
            if (!sdkInitializing) {
                initializeSdk();
            }
        });
    }

    private void initializeSdk() {
        sdkInitializing = true;
        connectIQ = ConnectIQ.getInstance(getContext(), ConnectIQ.IQConnectType.WIRELESS);
        // autoUI false: no SDK dialog pushing the user to install Garmin
        // Connect - a phone without it simply doesn't get the fast path.
        connectIQ.initialize(getContext(), false, new ConnectIQ.ConnectIQListener() {
            @Override
            public void onSdkReady() {
                sdkInitializing = false;
                sdkReady = true;
                List<PluginCall> calls = new ArrayList<>(pendingCalls);
                pendingCalls.clear();
                for (PluginCall pending : calls) {
                    sendWake(pending);
                }
            }

            @Override
            public void onInitializeError(ConnectIQ.IQSdkErrorStatus status) {
                sdkInitializing = false;
                List<PluginCall> calls = new ArrayList<>(pendingCalls);
                pendingCalls.clear();
                for (PluginCall pending : calls) {
                    resolveNothingSent(pending, 0, "sdk_" + status);
                }
            }

            @Override
            public void onSdkShutDown() {
                sdkReady = false;
            }
        });
    }

    private void sendWake(PluginCall call) {
        List<IQDevice> devices;
        try {
            devices = connectIQ.getConnectedDevices();
        } catch (Exception e) {
            resolveNothingSent(call, 0, "devices_" + e.getClass().getSimpleName());
            return;
        }
        if (devices == null || devices.isEmpty()) {
            resolveNothingSent(call, 0, "no_device");
            return;
        }

        WakeResult result = new WakeResult(call, devices.size());
        mainHandler.postDelayed(result::finish, WAKE_TIMEOUT_MS);
        IQApp app = new IQApp(WATCH_APP_ID);
        Object message = "sync";
        JSObject payload = call.getObject("payload");
        if (payload != null) {
            try {
                message = toMessage(payload);
            } catch (JSONException e) {
                resolveNothingSent(call, devices.size(), "payload_" + e.getClass().getSimpleName());
                return;
            }
        }
        for (IQDevice device : devices) {
            try {
                connectIQ.sendMessage(device, app, message, (d, a, status) ->
                    result.answer(status == ConnectIQ.IQMessageStatus.SUCCESS));
            } catch (Exception e) {
                result.answer(false);
            }
        }
    }

    /**
     * JSON -> what the Connect IQ SDK serializes for the watch: Map, List,
     * String, number, Boolean (a JSONObject itself isn't accepted).
     */
    private static Object toMessage(Object value) throws JSONException {
        if (value instanceof JSONObject) {
            JSONObject object = (JSONObject) value;
            Map<String, Object> map = new HashMap<>();
            Iterator<String> keys = object.keys();
            while (keys.hasNext()) {
                String key = keys.next();
                map.put(key, toMessage(object.get(key)));
            }
            return map;
        }
        if (value instanceof JSONArray) {
            JSONArray array = (JSONArray) value;
            List<Object> list = new ArrayList<>();
            for (int i = 0; i < array.length(); i++) {
                list.add(toMessage(array.get(i)));
            }
            return list;
        }
        if (value == JSONObject.NULL) {
            return null;
        }
        return value;
    }

    private static void resolveNothingSent(PluginCall call, int devices, String reason) {
        JSObject answer = new JSObject();
        answer.put("sent", 0);
        answer.put("devices", devices);
        answer.put("reason", reason);
        call.resolve(answer);
    }

    /** Collects one answer per device, resolves once all are in (or on timeout). */
    private static final class WakeResult {
        private final PluginCall call;
        private final int devices;
        private int answers = 0;
        private int sent = 0;
        private boolean finished = false;

        WakeResult(PluginCall call, int devices) {
            this.call = call;
            this.devices = devices;
        }

        synchronized void answer(boolean success) {
            answers++;
            if (success) {
                sent++;
            }
            if (answers >= devices) {
                finish();
            }
        }

        synchronized void finish() {
            if (finished) {
                return;
            }
            finished = true;
            JSObject answer = new JSObject();
            answer.put("sent", sent);
            answer.put("devices", devices);
            if (sent == 0) {
                answer.put("reason", answers < devices ? "timeout" : "send_failed");
            }
            call.resolve(answer);
        }
    }

    @Override
    protected void handleOnDestroy() {
        if (connectIQ != null) {
            try {
                connectIQ.shutdown(getContext());
            } catch (Exception ignored) {
                // Already shut down - nothing to release.
            }
        }
        super.handleOnDestroy();
    }
}
