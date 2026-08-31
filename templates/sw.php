<?php
/**
 * Service worker for the offline app shell. Served dynamically (not a
 * static public/sw.js) so the cache name and precached URLs can be derived
 * from the same filemtime()-based versioning as \Ytan\App::assetVersion()
 * uses for the <script>/<link> tags in app.php - any change to a shell file
 * changes this script's byte content, which makes the browser install a new
 * service worker and drop the old cache automatically.
 */
$shellFiles = [
    '/lib/jquery/jquery-3.7.1.min.js',
    '/lib/selectize/selectize.min.js',
    '/lib/measuretool-googlemap-v3/gmaps-measuretool.umd.js',
    '/lib/marked/marked.min.js',
    '/lib/markerWithLabel/markerwithlabel.min.js',
    '/lib/light-characteristics/lightcharacteristic.js',
    '/lib/map-label-rotated/rotated-label.js',
    '/lib/sectorlight-overlay/sectorlight-overlay.js',
    '/js/config.js',
    '/js/helper.js',
    '/js/api-client.js',
    '/js/settings.js',
    '/js/ui.js',
    '/js/toast.js',
    '/js/confirm-dialog.js',
    '/js/map-core.js',
    '/js/poi.js',
    '/js/route.js',
    '/js/area.js',
    '/js/tour.js',
    '/js/user.js',
    '/js/admin-user.js',
    '/css/style.css',
    '/css/fonts.css',
    '/lib/selectize/selectize.default.min.css',
    '/favicon.ico',
    '/favicon-16x16.png',
    '/favicon-32x32.png',
    '/apple-touch-icon.png',
    '/android-chrome-192x192.png',
    '/android-chrome-512x512.png',
    '/site.webmanifest',
    '/images/ytan.svg',
];

$versionSum = 0;
$precacheUrls = [$baseUrl . '/'];
foreach ($shellFiles as $file) {
    $v = \Ytan\App::assetVersion($rootDir, $file);
    $versionSum += (int) $v;
    $precacheUrls[] = $baseUrl . $file . '?v=' . $v;
}
$cacheName = 'ytan-shell-' . $versionSum;
$navigationFallbackUrl = $baseUrl . '/';
?>
const CACHE_NAME = <?= json_encode($cacheName) ?>;
const PRECACHE_URLS = <?= json_encode($precacheUrls) ?>;
const NAVIGATION_FALLBACK_URL = <?= json_encode($navigationFallbackUrl) ?>;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key.startsWith('ytan-shell-') && key !== CACHE_NAME)
                .map((key) => caches.delete(key))
        )).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Cross-origin (Google Maps etc.) and the REST API always go to the
    // network - map tiles/scripts aren't ours to cache, and API responses
    // must never be served stale.
    if (url.origin !== self.location.origin || url.pathname.includes('/api/')) {
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => caches.match(NAVIGATION_FALLBACK_URL))
        );
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => {
            if (cached) {
                return cached;
            }
            return fetch(event.request).then((response) => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                }
                return response;
            });
        })
    );
});
