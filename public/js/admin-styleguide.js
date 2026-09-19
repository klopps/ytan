/**
 * Theme toggle + toast-demo glue for the /admin/styleguide showcase page
 * (templates/admin-styleguide.php). Deliberately its own tiny
 * implementation rather than map-core.js's setTheme()/settings.js's
 * saveSettings() - those read ~20 hardcoded detail0..detail16 drawer-
 * checkbox IDs that don't exist on this page and would throw. Persisted
 * under its own localStorage key, independent of the main app's settings
 * cookie, so this page's theme choice never interacts with the SPA's.
 */
const STYLEGUIDE_THEME_STORAGE_KEY = 'ytan_styleguide_theme';

function setStyleguideTheme(theme) {
    var resolved = theme === 'dark' ? 'dark' : 'light';

    if (resolved === 'dark') {
        document.documentElement.dataset.theme = 'dark';
    } else {
        delete document.documentElement.dataset.theme;
    }

    // Bootstrap's own components (.form-control/.form-floating in the
    // Inputfelder section) use their own --bs-* variables, not our
    // --color-*/data-theme system - without this they'd stay stuck on
    // Bootstrap's light palette even after switching this page to dark.
    // Bootstrap 5.3+'s own dark mode is exactly this attribute.
    document.documentElement.setAttribute('data-bs-theme', resolved);

    try {
        localStorage.setItem(STYLEGUIDE_THEME_STORAGE_KEY, resolved);
    } catch (err) {
        // Private browsing / blocked storage - theme still applies for
        // this page view, just isn't remembered for the next one.
    }

    var input = document.getElementById(resolved === 'dark' ? 'styleguideThemeDark' : 'styleguideThemeLight');
    if (input) {
        input.checked = true;
    }
}

// Called once at the bottom of the page, after the DOM (incl. the theme
// radio inputs) exists. Only syncs the radio state to whatever the inline
// <head> snippet already applied to document.documentElement.dataset.theme
// (to avoid a flash of the wrong theme) - doesn't re-read localStorage.
function initStyleguideTheme() {
    setStyleguideTheme(document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light');
}

function styleguideShowToast(type) {
    var key = 'admin.styleguide.toast_demo_' + type;
    showToast(t(key), type);
}
