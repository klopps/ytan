/**
 * Thin wrapper around the Capacitor Android shell's background-geolocation
 * plugin, used by public/js/track-recorder.js for the live GPS route
 * recording feature. Everything below is a no-op in a normal browser tab
 * or an installed-PWA context: `window.Capacitor` only exists inside the
 * Capacitor-wrapped Android app (injected by its native runtime), so this
 * has zero effect on the regular web app.
 *
 * Background delivery was proven reliable on real hardware this session -
 * see todo.md "Capacitor-App-Hülle für Android" for the live-device proof
 * (location updates kept arriving 6+ minutes after screen-lock/USB
 * disconnect, past the plugin's documented 5-minute cutoff, confirming
 * capacitor.config.json's android.useLegacyBridge:true actually fixes it).
 */
const CapacitorBridge = (function () {
    if (!window.Capacitor || typeof window.Capacitor.isNativePlatform !== 'function' || !window.Capacitor.isNativePlatform()) {
        return { isAvailable: function () { return false; } };
    }

    // Capacitor's native Android runtime auto-registers every bundled native
    // plugin directly onto window.Capacitor.Plugins - no JS-side
    // registerPlugin() call needed (that helper only exists in the
    // @capacitor/core npm module for bundler-based projects and is NOT part
    // of the injected runtime bridge object; confirmed live via Chrome
    // DevTools against the running WebView - window.Capacitor.registerPlugin
    // is undefined there, but window.Capacitor.Plugins.BackgroundGeolocation
    // already exists).
    const BackgroundGeolocation = window.Capacitor.Plugins.BackgroundGeolocation;

    /**
     * With android.useLegacyBridge:true (capacitor.config.json - required to
     * avoid the plugin's documented "updates stop after 5 minutes" bug),
     * plugin calls do NOT reliably return a real Promise the way the
     * plugin's own TypeScript definitions claim - confirmed live via Chrome
     * DevTools: addWatcher() returned the watcher id as a plain synchronous
     * string, not a thenable. Handles both shapes defensively rather than
     * assuming either.
     */
    function resolveMaybePromise(value, onResolve) {
        if (value && typeof value.then === 'function') {
            value.then(onResolve);
        } else {
            onResolve(value);
        }
    }

    /**
     * @param {{distanceFilter: number, backgroundTitle: string, backgroundMessage: string}} options
     * @param {function(?{latitude,longitude,accuracy,altitude,speed,time}, ?{message}): void} onLocation
     * @param {function(string): void} onStarted called once with the watcher id, needed to stopLocationWatch() later
     */
    function startLocationWatch(options, onLocation, onStarted) {
        const result = BackgroundGeolocation.addWatcher(
            {
                backgroundTitle: options.backgroundTitle,
                backgroundMessage: options.backgroundMessage,
                requestPermissions: true,
                stale: false,
                distanceFilter: options.distanceFilter,
            },
            function (location, error) {
                onLocation(location, error);
            }
        );
        resolveMaybePromise(result, onStarted);
    }

    function stopLocationWatch(id) {
        return new Promise(function (resolve) {
            resolveMaybePromise(BackgroundGeolocation.removeWatcher({ id: id }), resolve);
        });
    }

    return {
        isAvailable: function () { return true; },
        startLocationWatch: startLocationWatch,
        stopLocationWatch: stopLocationWatch,
    };
})();
