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
    // App-local plugin (android/app/src/main/java/org/pesr/ytan/
    // LocationPermissionsPlugin.java), not an npm package - may be absent
    // if a build predates this feature (nothing to auto-sync it in), so
    // every use below guards on it rather than assuming it exists.
    const LocationPermissions = window.Capacitor.Plugins.LocationPermissions;
    // Both npm packages (@capacitor/filesystem, @capacitor/share), same
    // auto-registration as BackgroundGeolocation above. May be absent on a
    // build predating the Touren-Dokument PDF download fix, hence the guard
    // in saveAndShareFile() below.
    const Filesystem = window.Capacitor.Plugins.Filesystem;
    const Share = window.Capacitor.Plugins.Share;

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

    /**
     * @returns {Promise<?{foregroundLocation, backgroundLocation, notifications, locationServicesEnabled, allGranted}>}
     * Resolves null (rather than rejecting) both when the plugin is absent
     * and when the native call itself fails - callers should treat null as
     * "can't tell" and fail open, not block recording on a broken check.
     */
    function checkLocationPermissionStatus() {
        if (!LocationPermissions) {
            return Promise.resolve(null);
        }
        return Promise.resolve(LocationPermissions.checkStatus()).catch(function (err) {
            log('checkLocationPermissionStatus() failed', LOG_ERROR, err);
            return null;
        });
    }

    // Opens Android's "App info" settings screen for this app - exposed by
    // @capacitor-community/background-geolocation itself (its Java source's
    // openSettings() PluginMethod), reused here rather than duplicating it
    // in LocationPermissionsPlugin.
    function openAppSettings() {
        return Promise.resolve(BackgroundGeolocation.openSettings());
    }

    /**
     * The Android WebView has no download manager for blob: URLs, so
     * helper.js's downloadBlob() can't just click an <a download> link the
     * way a normal browser tab does (silently a no-op there - confirmed on
     * a real device with the Touren-Dokument PDF download). Instead, write
     * the file into the app's cache dir via the Filesystem plugin, then
     * hand its resulting file:// uri to the Share plugin, which internally
     * rewrites it to a content:// uri via Capacitor's own FileProvider and
     * opens Android's native share sheet - letting the user save it to
     * Downloads, open it in a PDF viewer, etc.
     * @param {string} base64Data
     * @param {string} filename
     */
    function saveAndShareFile(base64Data, filename) {
        if (!Filesystem || !Share) {
            return Promise.reject(new Error('Filesystem/Share plugin not available'));
        }
        return Filesystem.writeFile({
            path: filename,
            data: base64Data,
            directory: 'CACHE',
            recursive: true,
        }).then(function (result) {
            return Share.share({ url: result.uri, dialogTitle: filename });
        });
    }

    return {
        isAvailable: function () { return true; },
        startLocationWatch: startLocationWatch,
        stopLocationWatch: stopLocationWatch,
        checkLocationPermissionStatus: checkLocationPermissionStatus,
        openAppSettings: openAppSettings,
        saveAndShareFile: saveAndShareFile,
    };
})();
