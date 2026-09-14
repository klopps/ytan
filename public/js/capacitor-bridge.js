/**
 * TEMPORARY proof-of-concept for the Capacitor Android shell's background
 * GPS capability - NOT the real route-recording feature (that's a separate,
 * later step, see todo.md "Capacitor-App-Hülle"). This file exists only to
 * prove that @capacitor-community/background-geolocation actually keeps
 * delivering location updates while the screen is locked/app backgrounded,
 * which is the entire reason this native shell exists (a plain web
 * page/PWA cannot do this reliably - see the "Regenradar"/GPS discussion in
 * todo.md's history).
 *
 * Loaded unconditionally in templates/app.php like every other script here,
 * but everything below is a no-op in a normal browser tab or an
 * installed-PWA context: `window.Capacitor` only exists inside the
 * Capacitor-wrapped Android app (injected by its native runtime), so this
 * has zero effect on the regular web app.
 *
 * Manual test, since there is no UI for this yet: open the app inside the
 * Capacitor Android shell, then from a WebView devtools console (or a
 * temporary button) call `capacitorBridgeStartTestWatch()`, lock the
 * screen/switch away for a few minutes, unlock and check the console/toast
 * history for location updates that arrived while backgrounded. Stop with
 * `capacitorBridgeStopTestWatch()`.
 */
(function () {
    if (!window.Capacitor || typeof window.Capacitor.isNativePlatform !== 'function' || !window.Capacitor.isNativePlatform()) {
        return;
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
    let testWatcherId = null;

    window.capacitorBridgeStartTestWatch = function () {
        if (testWatcherId !== null) {
            log('capacitorBridgeStartTestWatch(): already watching', LOG_INFO);
            return;
        }
        BackgroundGeolocation.addWatcher(
            {
                backgroundTitle: 'YTAN (Test)',
                backgroundMessage: 'Testet Hintergrund-GPS - zum Beenden die App-Benachrichtigung antippen.',
                requestPermissions: true,
                stale: false,
                distanceFilter: 20,
            },
            function (location, error) {
                if (error) {
                    log('capacitor-bridge test watcher error', LOG_ERROR, error);
                    showToast('Background-GPS-Testfehler: ' + error.message, 'error');
                    return;
                }
                const msg = 'GPS-Update: ' + location.latitude.toFixed(5) + ', ' + location.longitude.toFixed(5) + ' (' + new Date(location.time).toLocaleTimeString() + ')';
                log(msg, LOG_INFO, location);
                showToast(msg, 'info');
            }
        ).then(function (id) {
            testWatcherId = id;
            log('capacitorBridgeStartTestWatch(): watcher started, id=' + id, LOG_INFO);
        }).catch(function (err) {
            log('capacitorBridgeStartTestWatch(): failed to start', LOG_ERROR, err);
        });
    };

    window.capacitorBridgeStopTestWatch = function () {
        if (testWatcherId === null) {
            return;
        }
        BackgroundGeolocation.removeWatcher({ id: testWatcherId }).then(function () {
            log('capacitorBridgeStopTestWatch(): watcher removed, id=' + testWatcherId, LOG_INFO);
            testWatcherId = null;
        });
    };
})();
