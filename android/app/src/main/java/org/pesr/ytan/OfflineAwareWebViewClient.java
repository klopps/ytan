package org.pesr.ytan;

import android.content.Context;
import android.os.Handler;
import android.os.Looper;
import android.webkit.WebView;

import com.getcapacitor.Bridge;
import com.getcapacitor.BridgeWebViewClient;

/**
 * BridgeWebViewClient.onReceivedError() already calls view.loadUrl(errorPath)
 * for a failed main-frame request - but confirmed on-device that it (and
 * onPageStarted() below, tried as a replacement) don't reliably fire at all
 * for a navigation the app's own registered service worker (sw.php)
 * intercepts and fails on its own (respondWith() rejecting/resolving to a
 * non-Response) - e.g. offline.html's own "Erneut versuchen" button, while
 * still offline, sometimes landed on the native error interstitial with no
 * further WebViewClient callback of any kind firing afterward. That button
 * now checks navigator.onLine client-side first and simply doesn't attempt
 * the navigation while offline (see offline.html), which is the actual fix
 * for that specific case. This class's onPageStarted() redirect remains as
 * defense-in-depth for any other in-app navigation that fails while
 * offline: NetworkUtils.isAvailable() sidesteps needing any error callback,
 * since a navigation starting with no network is certain to fail regardless
 * of how it eventually surfaces, so it schedules a forced redirect after a
 * fixed delay unconditionally (mirrors MainActivity's identical approach
 * for the app's very first, pre-attach cold-start load, which this class's
 * onCreate-time attachment is always too late to handle itself).
 */
public class OfflineAwareWebViewClient extends BridgeWebViewClient {

    private static final long FORCE_REDIRECT_DELAY_MS = 500;

    private final Bridge bridge;
    private final Context context;

    public OfflineAwareWebViewClient(Bridge bridge, Context context) {
        super(bridge);
        this.bridge = bridge;
        this.context = context;
    }

    @Override
    public void onPageStarted(WebView view, String url, android.graphics.Bitmap favicon) {
        super.onPageStarted(view, url, favicon);

        String errorUrl = bridge.getErrorUrl();
        if (errorUrl != null && !url.equals(errorUrl) && !NetworkUtils.isAvailable(context)) {
            new Handler(Looper.getMainLooper()).postDelayed(
                () -> view.loadUrl(errorUrl),
                FORCE_REDIRECT_DELAY_MS
            );
        }
    }
}
