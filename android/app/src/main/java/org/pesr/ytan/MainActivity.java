package org.pesr.ytan;

import android.content.Context;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.webkit.CookieManager;
import android.webkit.WebView;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    private static final long COLD_START_ERROR_WATCHDOG_DELAY_MS = 1500;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // LocationPermissionsPlugin is app-local source (not an npm
        // package under node_modules), so unlike @capacitor-community/
        // background-geolocation it isn't auto-registered by `cap sync` -
        // must be registered here before super.onCreate().
        registerPlugin(LocationPermissionsPlugin.class);
        super.onCreate(savedInstanceState);

        // See OfflineAwareWebViewClient: covers later navigation failures
        // (e.g. the offline.html "Erneut versuchen" button failing again).
        this.bridge.setWebViewClient(new OfflineAwareWebViewClient(this.bridge));

        // The very first page load - the one that fails on a cold start
        // with no connectivity at all - happens inside super.onCreate()
        // itself, using Capacitor's own default WebViewClient instance
        // (Bridge's constructor creates it and calls webView.loadUrl()
        // before this method ever gets a chance to install
        // OfflineAwareWebViewClient above). That default client's own
        // errorPath redirect - an immediate loadUrl() call made
        // synchronously from inside onReceivedError() - intermittently
        // loses a race against the WebView's native error interstitial
        // (chrome-error://chromewebdata/) committing (verified on-device
        // across repeated cold starts: sometimes it wins, usually not).
        // WebView.getUrl() can't reliably distinguish "stuck on the
        // interstitial" from "loaded fine" here either - it kept reporting
        // the original appUrl even while document.location was actually
        // chrome-error://chromewebdata/ (confirmed via CDP). Android's own
        // connectivity state is the one reliable signal: if there's no
        // network at all when the app launches, the initial load is
        // certain to fail, so just force the errorPath page after a fixed
        // delay unconditionally - no ambiguous state-comparison needed.
        if (!isNetworkAvailable()) {
            final WebView webView = this.bridge.getWebView();
            final String errorUrl = this.bridge.getErrorUrl();
            if (errorUrl != null) {
                new Handler(Looper.getMainLooper()).postDelayed(
                    () -> webView.loadUrl(errorUrl),
                    COLD_START_ERROR_WATCHDOG_DELAY_MS
                );
            }
        }
    }

    private boolean isNetworkAvailable() {
        ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
        if (cm == null) {
            return true;
        }
        Network network = cm.getActiveNetwork();
        if (network == null) {
            return false;
        }
        NetworkCapabilities capabilities = cm.getNetworkCapabilities(network);
        return capabilities != null && capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
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
