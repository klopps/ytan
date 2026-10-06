/**
 * Android app distribution and in-app update (ported from the Kochbuch
 * project's native-app.js). The APK and its version.json are published to
 * public/app/ by bin\publish-app.bat.
 *
 * Inside the Capacitor app:
 *  - the installed app's version is appended to the drawer's web version
 *    ("v<web> / <app>") - the web code always comes live from the server,
 *    so only the native shell knows its own version;
 *  - on every start, version.json is compared with the installed
 *    versionCode; if the server has a newer one, a banner and a highlighted
 *    drawer row offer the update, which AppUpdatePlugin downloads and hands
 *    to Android's installer. An APK predating AppUpdatePlugin can't do that
 *    and gets the download link instead.
 *
 * In a browser/PWA on Android: a drawer row links to the APK, hidden when
 * navigator.getInstalledRelatedApps() reports the app as installed (needs
 * site.webmanifest's related_applications + the APK's asset_statements;
 * Chrome on Android only - elsewhere the row just stays visible) and when
 * no APK is published on this host at all.
 */
const NativeApp = (() => {
    const APP_PACKAGE_ID = 'org.pesr.ytan';

    let latest = null;

    function siteBaseUrl() {
        return (window.YTAN_API_BASE || '/api/v1').replace(/\/api\/v1$/, '');
    }

    function apkUrl(version) {
        return new URL((version && version.url) || 'app/ytan.apk', location.origin + siteBaseUrl() + '/').href;
    }

    function isNativeApp() {
        return typeof CapacitorBridge !== 'undefined' && CapacitorBridge.isAvailable();
    }

    async function fetchPublishedVersion() {
        const response = await fetch(siteBaseUrl() + '/app/version.json', { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    }

    function showRow(id) {
        const row = document.getElementById(id);
        if (row) {
            row.style.display = '';
        }
        return row;
    }

    function showInstalledVersion(info) {
        const target = document.querySelector('.nav-version-text');
        if (target && info.versionName) {
            target.textContent = target.textContent.trim() + ' / ' + info.versionName;
        }
    }

    async function checkForUpdate(info) {
        let version;
        try {
            version = await fetchPublishedVersion();
        } catch (e) {
            log('App update check failed', LOG_WARN, e);
            return;
        }
        if (!(Number(version.versionCode) > info.versionCode)) {
            return;
        }

        latest = version;
        showRow('appUpdateMenuRow');
        showBanner();
        renderDownloadState(null);
    }

    const PROGRESS_MARKUP = '<span class="app-update-progress"><span class="app-update-progress-bar"></span></span>';

    /**
     * Progress is shown as a bar along the banner's bottom edge and under the
     * drawer row's label - never as changing text, whose width would jitter
     * with every percent step and squeeze the banner's layout.
     * @param {?number} percent null = idle, 0-100 = downloading
     */
    function renderDownloadState(percent) {
        const downloading = percent !== null;
        const idleLabel = latest ? t('app.app_update.install_to', { version: latest.versionName }) : '';

        const row = document.getElementById('appUpdateMenuRow');
        if (row) {
            row.classList.toggle('is-downloading', downloading);
            row.querySelector('.app-update-row-label').textContent = downloading ? t('app.app_update.downloading') : idleLabel;
        }

        const banner = document.getElementById('appNewVersionBanner');
        if (banner) {
            banner.classList.toggle('is-downloading', downloading);
            banner.querySelector('.app-new-version-banner-text').textContent = downloading
                ? t('app.app_update.downloading')
                : t('app.app_update.banner', { version: latest.versionName });
            banner.querySelector('.app-new-version-banner-btn').disabled = downloading;
        }

        document.querySelectorAll('.app-update-progress-bar').forEach((bar) => {
            bar.style.width = (downloading ? Math.max(0, Math.min(100, percent)) : 0) + '%';
        });
    }

    function showBanner() {
        let banner = document.getElementById('appNewVersionBanner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'appNewVersionBanner';
            banner.className = 'app-new-version-banner';
            document.body.appendChild(banner);
        }
        banner.innerHTML =
            '<i class="material-icons-round app-new-version-banner-icon">system_update</i>' +
            '<span class="app-new-version-banner-text"></span>' +
            '<button type="button" class="app-new-version-banner-btn"></button>' +
            '<button type="button" class="app-new-version-banner-close"><i class="material-icons-round">close</i></button>' +
            PROGRESS_MARKUP;
        const installBtn = banner.querySelector('.app-new-version-banner-btn');
        installBtn.textContent = t('app.app_update.install_now');
        installBtn.addEventListener('click', installUpdate);
        const closeBtn = banner.querySelector('.app-new-version-banner-close');
        closeBtn.setAttribute('aria-label', t('app.app_update.dismiss'));
        closeBtn.addEventListener('click', () => banner.remove());
    }

    let installing = false;

    async function installUpdate() {
        if (!latest || installing) {
            return;
        }
        const url = apkUrl(latest);
        if (!CapacitorBridge.canSelfUpdate()) {
            showToast(t('app.app_update.manual', { url: url }), 'info');
            return;
        }

        installing = true;
        renderDownloadState(0);
        try {
            await CapacitorBridge.downloadAndInstallUpdate(url, renderDownloadState);
        } catch (e) {
            log('App update failed', LOG_ERROR, e);
            showToast(t('app.app_update.failed'), 'error');
        } finally {
            installing = false;
            renderDownloadState(null);
        }
    }

    async function initInstallRow() {
        if (!/Android/i.test(navigator.userAgent)) {
            return;
        }
        if (typeof navigator.getInstalledRelatedApps === 'function') {
            try {
                const apps = await navigator.getInstalledRelatedApps();
                if (apps.some((app) => app.id === APP_PACKAGE_ID)) {
                    return;
                }
            } catch (e) {
                log('getInstalledRelatedApps() failed', LOG_WARN, e);
            }
        }
        let version;
        try {
            version = await fetchPublishedVersion();
        } catch (e) {
            return; // no APK published on this host
        }
        const row = showRow('appInstallMenuRow');
        if (row) {
            row.querySelector('.nav-menu-row-sub').textContent = 'v' + version.versionName;
            row.querySelector('button').addEventListener('click', () => {
                window.location.href = apkUrl(version);
            });
        }
    }

    async function init() {
        if (!isNativeApp()) {
            initInstallRow();
            return;
        }
        const info = await CapacitorBridge.getAppInfo();
        if (!info) {
            return; // APK too old to report its own version
        }
        showInstalledVersion(info);
        checkForUpdate(info);
    }

    return { init, installUpdate };
})();

document.addEventListener('DOMContentLoaded', () => NativeApp.init());
