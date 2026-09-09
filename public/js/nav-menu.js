/**
 * Drill-down navigation inside #sidemenu: a stack of named "screens" that
 * slide in from the right (navMenuGoTo) and back out (navMenuBack), instead
 * of one long flat list. #sidemenu itself still only opens/closes as a
 * whole via openMenu()/closeMenu() (ui.js) - this module only manages which
 * screen is showing inside it.
 */
var navMenuStack = ['root'];

function navMenuScreenEl(screenId) {
    return document.querySelector('.nav-screen[data-nav-screen="' + screenId + '"]');
}

function navMenuGoTo(screenId) {
    var next = navMenuScreenEl(screenId);
    if (!next) {
        log('navMenuGoTo(' + screenId + '): unknown screen', LOG_WARN);
        return;
    }

    var current = navMenuScreenEl(navMenuStack[navMenuStack.length - 1]);
    current.classList.remove('nav-screen-active');
    current.classList.add('nav-screen-off-left');
    next.classList.remove('nav-screen-off-left', 'nav-screen-off-right');
    next.classList.add('nav-screen-active');

    navMenuStack.push(screenId);
}

function navMenuBack() {
    if (navMenuStack.length < 2) {
        return;
    }

    var current = navMenuScreenEl(navMenuStack.pop());
    var prev = navMenuScreenEl(navMenuStack[navMenuStack.length - 1]);

    current.classList.remove('nav-screen-active');
    current.classList.add('nav-screen-off-right');
    prev.classList.remove('nav-screen-off-left');
    prev.classList.add('nav-screen-active');
}

/**
 * Snaps every screen but root back to its initial off-screen position, with
 * no transition, so the next open shows the main menu instead of wherever
 * navigation last left off. Called from closeMenu() (ui.js) once the drawer
 * has (or is about to have) slid fully out of view, so the reset itself is
 * never visible to the user.
 */
function navMenuReset() {
    document.querySelectorAll('.nav-screen').forEach(function (screen) {
        if (screen.dataset.navScreen === 'root') {
            screen.classList.add('nav-screen-active');
            screen.classList.remove('nav-screen-off-left', 'nav-screen-off-right');
        } else {
            screen.classList.remove('nav-screen-active', 'nav-screen-off-left');
            screen.classList.add('nav-screen-off-right');
        }
    });
    navMenuStack = ['root'];
}

/**
 * Fallback "hard reload" escape hatch: clicking the drawer's bottom logo
 * (#sidemenuLogo, the root screen's .panel-logo) 4 times in quick succession
 * reloads the page from scratch - useful if the SPA ever gets into a stuck
 * state a normal drawer/panel close can't recover from. Wired up via this
 * one element's own onclick in app.php rather than a global handler on the
 * .panel-logo class, since that same markup is reused, deliberately
 * non-interactive, by every other full-screen panel (cookie/legal/admin/tours).
 */
var sidemenuLogoClickCount = 0;
var sidemenuLogoClickTimer = null;

function handleSidemenuLogoClick() {
    sidemenuLogoClickCount++;
    clearTimeout(sidemenuLogoClickTimer);

    if (sidemenuLogoClickCount >= 4) {
        location.reload();
        return;
    }

    sidemenuLogoClickTimer = setTimeout(function () {
        sidemenuLogoClickCount = 0;
    }, 600);
}
