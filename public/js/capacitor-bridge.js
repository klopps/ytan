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
    // App-local plugin (android/app/src/main/java/org/pesr/ytan/
    // AppInfoPlugin.java), same convention as LocationPermissions above -
    // may be absent on a build predating this feature (todo.md "App-
    // Backend-Kompatibilität"), guarded in getAppVersionCode() below.
    const AppInfo = window.Capacitor.Plugins.AppInfo;
    // App-local plugin (AppUpdatePlugin.java) - absent on APKs built before
    // the in-app update existed; native-app.js falls back to a download
    // link for those.
    const AppUpdate = window.Capacitor.Plugins.AppUpdate;
    // App-local plugin (GarminWatchPlugin.java) - absent on APKs built
    // before the watch wake-up existed; wakeGarminWatch() then resolves
    // null and the watch gets the route at its regular 5-minute fetch.
    const GarminWatch = window.Capacitor.Plugins.GarminWatch;

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

    /**
     * Shares a plain URL (a map/tour deep link, see shareMap()/shareTour()
     * in map-core.js/tour-admin.js) via Android's native share sheet.
     * navigator.share (the Web Share API) does exist inside the WebView,
     * but proved unreliable there in practice - confirmed on a real device,
     * it silently fell through to the clipboard-copy fallback instead of
     * ever opening a share dialog, the same class of "quietly a no-op
     * instead of the real native behavior" problem as downloadBlob()'s
     * <a download> click above. The Share plugin used by
     * saveAndShareFile() already works reliably for files, so it's used
     * here for a plain link too rather than trusting navigator.share.
     * @param {string} url
     * @param {string} title
     * @param {string} text
     */
    function shareLink(url, title, text) {
        if (!Share) {
            return Promise.reject(new Error('Share plugin not available'));
        }
        return Share.share({ title: title, text: text, url: url, dialogTitle: title });
    }

    // Memoized (not re-fetched per call) since api-client.js's
    // appVersionHeader() awaits this on every single API request - the
    // installed APK's versionCode can't change during a running session,
    // so one native round trip is enough.
    let appInfoPromise = null;

    /**
     * @returns {Promise<?{versionCode: number, versionName: string}>} the
     * installed APK's version (android/app/build.gradle) via the app-local
     * AppInfo plugin, or null if that plugin is absent (a build predating
     * it) or the native call fails - callers should treat null as "unknown",
     * matching checkLocationPermissionStatus()'s fail-open convention above.
     */
    function getAppInfo() {
        if (!AppInfo) {
            return Promise.resolve(null);
        }
        if (!appInfoPromise) {
            appInfoPromise = Promise.resolve(AppInfo.getInfo()).then(function (info) {
                if (!info || typeof info.versionCode === 'undefined') {
                    return null;
                }
                return { versionCode: Number(info.versionCode), versionName: info.versionName || '' };
            }).catch(function (err) {
                log('getAppInfo() failed', LOG_ERROR, err);
                return null;
            });
        }
        return appInfoPromise;
    }

    /**
     * @returns {Promise<?number>} the installed APK's versionCode, or null
     * if unknown - callers should NOT report a version then rather than
     * assuming incompatibility.
     */
    function getAppVersionCode() {
        return getAppInfo().then(function (info) {
            return info ? info.versionCode : null;
        });
    }

    /**
     * Wakes the YTAN Garmin data field through Garmin Connect so it fetches
     * its route now instead of at its next 5-minute slot.
     * @returns {Promise<?{sent: number, devices: number, reason: ?string}>}
     * the plugin's answer (sent = watches that confirmed it), or null if the
     * plugin is absent or the call fails. Never rejects - the watch's own
     * regular fetch is always the fallback.
     */
    function wakeGarminWatch() {
        if (!GarminWatch) {
            return Promise.resolve(null);
        }
        return Promise.resolve(GarminWatch.wake()).then(function (result) {
            log('wakeGarminWatch()', LOG_DEBUG, result);
            return result || null;
        }).catch(function (err) {
            log('wakeGarminWatch() failed', LOG_ERROR, err);
            return null;
        });
    }

    function canSelfUpdate() {
        return !!AppUpdate;
    }

    /**
     * Downloads the APK at `url` (must be HTTPS on the app's own server host,
     * enforced natively) and opens Android's installer for it. Resolves once
     * the installer is showing; Android replaces the app on the user's
     * confirmation.
     * @param {string} url
     * @param {function(number): void} onProgress download percentage
     */
    async function downloadAndInstallUpdate(url, onProgress) {
        if (!AppUpdate) {
            throw new Error('AppUpdate plugin not available');
        }
        const listener = await Promise.resolve(AppUpdate.addListener('downloadProgress', function (event) {
            onProgress(event.percent);
        }));
        try {
            await Promise.resolve(AppUpdate.downloadAndInstall({ url: url }));
        } finally {
            if (listener && typeof listener.remove === 'function') {
                listener.remove();
            }
        }
    }

    return {
        isAvailable: function () { return true; },
        startLocationWatch: startLocationWatch,
        stopLocationWatch: stopLocationWatch,
        checkLocationPermissionStatus: checkLocationPermissionStatus,
        openAppSettings: openAppSettings,
        saveAndShareFile: saveAndShareFile,
        shareLink: shareLink,
        getAppInfo: getAppInfo,
        getAppVersionCode: getAppVersionCode,
        canSelfUpdate: canSelfUpdate,
        downloadAndInstallUpdate: downloadAndInstallUpdate,
        wakeGarminWatch: wakeGarminWatch,
    };
})();
