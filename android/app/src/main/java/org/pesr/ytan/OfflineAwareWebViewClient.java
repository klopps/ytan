package org.pesr.ytan;

import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebView;

import com.getcapacitor.Bridge;
import com.getcapacitor.BridgeWebViewClient;

/**
 * BridgeWebViewClient.onReceivedError() already calls view.loadUrl(errorPath)
 * for a failed main-frame request, but on a cold start with no connectivity
 * at all that call - made synchronously from inside onReceivedError, before
 * the WebView's own native error interstitial (chrome-error://chromewebdata/)
 * has actually finished committing - reliably loses the race and the
 * interstitial wins instead (reproducible on-device: the exact same
 * loadUrl(errorPath) call succeeds when triggered later by a plain reload).
 * Neither a view.post()'d retry from inside onReceivedError, nor waiting for
 * the next onPageFinished() alone, wins the race every time - both were
 * tried on-device and each still intermittently lost to the interstitial.
 * Combining both - onPageFinished() as the primary trigger, plus a fixed
 * postDelayed() fallback timer armed at the same time as a safety net -
 * covers whichever ends up firing first; a flag guarded by
 * clearAndGetPending() ensures only one of the two ever actually redirects.
 */
public class OfflineAwareWebViewClient extends BridgeWebViewClient {

    private static final long FALLBACK_DELAY_MS = 500;

    private final Bridge bridge;
    private volatile boolean pendingErrorRedirect = false;

    public OfflineAwareWebViewClient(Bridge bridge) {
        super(bridge);
        this.bridge = bridge;
    }

    @Override
    public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
        super.onReceivedError(view, request, error);

        if (bridge.getErrorUrl() != null && request.isForMainFrame()) {
            pendingErrorRedirect = true;
            view.postDelayed(() -> redirectIfPending(view), FALLBACK_DELAY_MS);
        }
    }

    @Override
    public void onPageFinished(WebView view, String url) {
        super.onPageFinished(view, url);
        redirectIfPending(view);
    }

    private synchronized void redirectIfPending(WebView view) {
        if (!pendingErrorRedirect) {
            return;
        }
        pendingErrorRedirect = false;
        String errorUrl = bridge.getErrorUrl();
        if (errorUrl != null) {
            view.loadUrl(errorUrl);
        }
    }
}
