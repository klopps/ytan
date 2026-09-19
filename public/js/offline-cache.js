/**
 * Local "last known good" cache for the four map-data collections
 * (POIs/Routes/Areas/Tours) - lets the app boot with real data after a cold
 * restart with no network, instead of an empty map. Phase 1 of todo.md's
 * "Offline-Funktionalität für die Android-App" - read-only caching only;
 * offline create/edit/delete (a local sync queue, generalizing
 * track-recorder.js's own `pending` store) and timestamp-based conflict
 * detection are later, separate phases.
 *
 * Deliberately NOT gated behind CapacitorBridge.isAvailable() the way
 * track-recorder.js is - IndexedDB works identically in the native Android
 * shell and a plain browser tab, and this feature is meant to help both.
 *
 * Same IndexedDB "thin Promise wrapper per transaction" pattern as
 * track-recorder.js's openRecordingDb()/putPending()/getAllPending()/
 * deletePending(), generalized from a single-purpose sync queue to a
 * generic per-(collection, scope, user) cache.
 */

const OFFLINE_CACHE_DB_NAME = 'ytan-offline-cache';
const OFFLINE_CACHE_DB_VERSION = 1;
const OFFLINE_CACHE_STORE = 'collections';
// Every anonymous/public-scope session shares one entry - public data is
// identical for everyone, no need to key it per browser session.
const OFFLINE_CACHE_PUBLIC_USER_KEY = 'public';

let offlineCacheDb = null;

function openOfflineCacheDb() {
    if (offlineCacheDb) {
        return Promise.resolve(offlineCacheDb);
    }
    return new Promise(function (resolve, reject) {
        const request = indexedDB.open(OFFLINE_CACHE_DB_NAME, OFFLINE_CACHE_DB_VERSION);
        request.onupgradeneeded = function () {
            const upgradeDb = request.result;
            if (!upgradeDb.objectStoreNames.contains(OFFLINE_CACHE_STORE)) {
                upgradeDb.createObjectStore(OFFLINE_CACHE_STORE, { keyPath: ['collection', 'scope', 'userId'] });
            }
        };
        request.onsuccess = function () {
            offlineCacheDb = request.result;
            resolve(offlineCacheDb);
        };
        request.onerror = function () {
            reject(request.error);
        };
    });
}

/**
 * The user-id component of every cache entry's key - the real numeric user
 * id (as a string) whenever it's known or at least recoverable from the
 * stored JWT, otherwise the shared public sentinel. Using this same
 * function for both writing and reading is what guarantees a different (or
 * logged-out) user can never read back another user's cached "mine_public"
 * data through the app - the key simply won't match.
 */
function offlineCacheUserIdKey() {
    const id = (typeof user !== 'undefined' && user.id !== null) ? user.id : Ytan.getStoredUserId();
    return (id !== null && typeof id !== 'undefined') ? String(id) : OFFLINE_CACHE_PUBLIC_USER_KEY;
}

/**
 * Fire-and-forget from the caller's perspective (callers don't await this -
 * a cache-write failure shouldn't block showing freshly-fetched data).
 */
function putCachedCollection(collection, scope, data) {
    openOfflineCacheDb().then(function (database) {
        const tx = database.transaction(OFFLINE_CACHE_STORE, 'readwrite');
        tx.objectStore(OFFLINE_CACHE_STORE).put({
            collection: collection,
            scope: scope,
            userId: offlineCacheUserIdKey(),
            data: data,
            cachedAt: Date.now(),
        });
    }).catch(function (err) {
        log('putCachedCollection(' + collection + ') failed', LOG_WARN, err);
    });
}

/**
 * @returns {Promise<{data: any, cachedAt: number}|null>}
 */
function getCachedCollection(collection, scope) {
    return openOfflineCacheDb().then(function (database) {
        return new Promise(function (resolve, reject) {
            const tx = database.transaction(OFFLINE_CACHE_STORE, 'readonly');
            const request = tx.objectStore(OFFLINE_CACHE_STORE).get([collection, scope, offlineCacheUserIdKey()]);
            request.onsuccess = function () {
                resolve(request.result ? { data: request.result.data, cachedAt: request.result.cachedAt } : null);
            };
            request.onerror = function () { reject(request.error); };
        });
    });
}

/**
 * Called on logout (user.js) as defense-in-depth: exact-key matching
 * already stops the app's own UI from ever reading a previous user's
 * cached private data back, but the bytes would otherwise still sit in
 * IndexedDB indefinitely, readable by anyone with direct device/DevTools
 * access to the same browser profile. Deletes every entry for that user
 * (any collection, any scope) - public-scope entries are untouched, they
 * don't belong to any one user.
 */
function clearOfflineCacheForUser(userId) {
    if (userId === null || typeof userId === 'undefined') {
        return Promise.resolve();
    }
    const key = String(userId);
    return openOfflineCacheDb().then(function (database) {
        return new Promise(function (resolve, reject) {
            const tx = database.transaction(OFFLINE_CACHE_STORE, 'readwrite');
            const request = tx.objectStore(OFFLINE_CACHE_STORE).openCursor();
            request.onsuccess = function () {
                const cursor = request.result;
                if (cursor) {
                    if (cursor.value.userId === key) {
                        cursor.delete();
                    }
                    cursor.continue();
                }
            };
            tx.oncomplete = resolve;
            tx.onerror = function () { reject(tx.error); };
        });
    });
}

// --- Fallback notice (one combined toast per burst, not one per collection) ---

const OFFLINE_NOTICE_DEBOUNCE_MS = 200;
let pendingOfflineNotices = []; // [{collection, cachedAt: number|null}]
let offlineNoticeTimer = null;

/**
 * Called by each of the 8 getXByUserId()/getPublicX() load functions
 * (poi.js/route.js/area.js/tour.js) from its .catch() branch, once per
 * collection that just fell back to (or failed to find) a cache entry.
 * initMap() fires up to 4 of these as independent, uncoordinated
 * fire-and-forget calls (no shared Promise.all to hook) - a short debounce
 * window coalesces whatever arrives in one burst into a single toast,
 * regardless of whether the burst came from initMap() (up to 4), a login
 * refresh (2), or a single manual reload of one collection.
 *
 * @param {string} collection
 * @param {number|null} cachedAt Date.now() the cache entry was written, or
 *   null if there was no cache entry at all for this collection.
 */
function notifyOfflineFallback(collection, cachedAt) {
    pendingOfflineNotices.push({ collection: collection, cachedAt: cachedAt });
    clearTimeout(offlineNoticeTimer);
    offlineNoticeTimer = setTimeout(flushOfflineNotices, OFFLINE_NOTICE_DEBOUNCE_MS);
}

function flushOfflineNotices() {
    const notices = pendingOfflineNotices;
    pendingOfflineNotices = [];
    if (notices.length === 0) {
        return;
    }

    const withCache = notices.filter(function (n) { return n.cachedAt !== null; });
    if (withCache.length > 0) {
        const oldest = Math.min.apply(null, withCache.map(function (n) { return n.cachedAt; }));
        const oldestDate = new Date(oldest);
        showToast(t('offline.showing_cached_data', {
            date: oldestDate.toLocaleDateString(),
            time: oldestDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
        }), 'info');
    } else {
        showToast(t('offline.no_cached_data'), 'error');
    }
}
