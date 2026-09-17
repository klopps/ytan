package org.pesr.ytan;

import android.os.Bundle;
import android.webkit.CookieManager;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        // LocationPermissionsPlugin is app-local source (not an npm
        // package under node_modules), so unlike @capacitor-community/
        // background-geolocation it isn't auto-registered by `cap sync` -
        // must be registered here before super.onCreate().
        registerPlugin(LocationPermissionsPlugin.class);
        super.onCreate(savedInstanceState);
    }

    /**
     * Android's WebView CookieManager buffers cookie writes in memory and
     * only persists them to its on-disk store periodically or on an
     * explicit flush() - per Android's own docs, onDestroy() is NOT
     * guaranteed to run (e.g. the process is simply killed when swiped
     * away in the recent-apps list, or low-memory-killed in the
     * background), so document.cookie writes from public/js/settings.js
     * (the "settings"/"gdpr_accepted" cookies - language, theme, map
     * type, units, ...) could silently never make it to disk: they worked
     * for the rest of that session (reading them back from the in-memory
     * store), then were gone on the next cold start. localStorage/
     * IndexedDB (e.g. the JWT auth token, api-client.js) don't have this
     * problem - Chromium persists those more eagerly - which is why login
     * survived a restart while settings didn't, the actual symptom
     * reported. onPause() (not onStop()) is the safest hook: it's always
     * called before the process becomes eligible for killing, unlike
     * onStop() which the system can skip entirely under memory pressure.
     */
    @Override
    public void onPause() {
        super.onPause();
        CookieManager.getInstance().flush();
    }
}
