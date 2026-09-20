/**
 * Browser-only "cold start" splash screen - shown when the page is loaded
 * after the app hasn't been used for at least
 * SPLASHSCREEN_IDLE_THRESHOLD_SECONDS (config.js), hidden again after
 * SPLASHSCREEN_DISPLAY_DURATION_MS regardless of whether the map has
 * finished loading. Skipped entirely inside the native Capacitor Android
 * shell (CapacitorBridge.isAvailable()), which already shows its own native
 * splash screen before this page even starts loading.
 *
 * "Last used" is tracked in localStorage rather than sessionStorage - a
 * sessionStorage value is gone the moment the tab/app is actually closed,
 * which is exactly the "hasn't been used" case this is meant to detect.
 */
const SPLASHSCREEN_LAST_ACTIVE_KEY = 'ytan_last_active';

function initSplashScreen() {
  const el = document.getElementById('splashScreen');
  if (!el) {
    return;
  }

  if (typeof CapacitorBridge !== 'undefined' && CapacitorBridge.isAvailable()) {
    el.style.display = 'none';
    return;
  }

  const lastActive = parseInt(localStorage.getItem(SPLASHSCREEN_LAST_ACTIVE_KEY) || '0', 10);
  const idleMs = Date.now() - lastActive;

  if (lastActive && idleMs < SPLASHSCREEN_IDLE_THRESHOLD_SECONDS * 1000) {
    el.style.display = 'none';
  } else {
    setTimeout(function () {
      el.classList.add('splash-hidden');
      setTimeout(function () {
        el.style.display = 'none';
      }, 400); // matches #splashScreen's own opacity transition duration
    }, SPLASHSCREEN_DISPLAY_DURATION_MS);
  }

  markAppActive();

  // Keep the stored timestamp fresh while the app is actually in use, so a
  // tab left open for a long single session doesn't get flagged as "idle"
  // the moment it's reloaded or reopened. Refreshed periodically rather than
  // only on unload since mobile browsers/PWAs don't reliably fire
  // beforeunload/pagehide when the OS simply kills a backgrounded process.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      markAppActive();
    }
  });
  window.addEventListener('pagehide', markAppActive);
  setInterval(function () {
    if (document.visibilityState === 'visible') {
      markAppActive();
    }
  }, 30_000);
}

function markAppActive() {
  localStorage.setItem(SPLASHSCREEN_LAST_ACTIVE_KEY, String(Date.now()));
}
