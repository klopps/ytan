/**
 * Side menu, toolbars, cookie menu and generic edit-button state.
 */

function showToolbar() {
    document.getElementById("editToolbar").style.display = "inline-block";
}

function hideToolbar() {
    document.getElementById("editToolbar").style.display = "none";
}


/**
 * Full-screen panels (cookie/legal/user-admin menus) sit visually behind
 * editToolbar's z-index, so it must be hidden for as long as any one of
 * them is open. Tracked with a counter (rather than a plain boolean) so two
 * panels opened back-to-back can't have the second one's close prematurely
 * reveal the toolbar while the first is still open. Uses `visibility`
 * rather than `display` so it doesn't fight the unrelated street-view
 * show/hide logic in map-core.js, which toggles `display` on the same
 * element.
 */
var openPanelCount = 0;

function panelOpened() {
    openPanelCount++;
    document.getElementById("editToolbar").style.visibility = "hidden";
}

function panelClosed() {
    openPanelCount = Math.max(0, openPanelCount - 1);
    if (openPanelCount === 0) {
        document.getElementById("editToolbar").style.visibility = "";
    }
}


/**
 * Cookie-Menu
 */

function openCookieMenu() {
    closeMenu();
    document.getElementById("cookiemenu").style.width = "100%";
    panelOpened();
}

function closeCookieMenu() {
    document.getElementById("cookiemenu").style.width = "0%";
    panelClosed();
}


/**
 * Legal-Menu (Imprint / Privacy Notice)
 *
 * Content stays in its own template file (templates/impressum.php,
 * templates/datenschutz.php); we just fetch the rendered page and show
 * its body inline instead of navigating to it.
 */

function openLegalMenu(url) {
    closeMenu();
    var container = document.getElementById("legalmenu-content");
    container.innerHTML = "";
    document.getElementById("legalmenu").style.width = "100%";
    document.getElementById("legalmenu-close-btn").style.display = "flex";
    panelOpened();

    fetch(url)
        .then(function (response) { return response.text(); })
        .then(function (html) {
            var doc = new DOMParser().parseFromString(html, "text/html");
            container.innerHTML = doc.body.innerHTML;
        })
        .catch(function (err) {
            container.innerHTML = "<p>Failed to load content.</p>";
            log('openLegalMenu() failed', LOG_ERROR, err);
        });
}

function closeLegalMenu() {
    document.getElementById("legalmenu").style.width = "0%";
    document.getElementById("legalmenu-close-btn").style.display = "none";
    panelClosed();
}


/**
 * Menü
 */

function closeMenu() {
    document.getElementById("sidemenu").style.marginLeft = "-335px";
    // Reopening should always land back on the main menu, not wherever the
    // user last drilled into - delayed until #sidemenu's own 0.5s slide-out
    // transition has finished, so the screen-stack snapping back to root is
    // never visible mid-close.
    if (typeof navMenuReset === 'function') {
        window.setTimeout(navMenuReset, 500);
    }
}

function openMenu() {
    document.getElementById("sidemenu").style.marginLeft = "0";
}

function hideMenu() {
    document.getElementById("sidemenu").style.display = "none";
    document.getElementById("sidemenu-toggle").style.display = "none";
}

function unhideMenu() {
    document.getElementById("sidemenu").style.display = "block";
    document.getElementById("sidemenu-toggle").style.display = "flex";
}

function toggleMenu() {
    var sidemenu = document.getElementById("sidemenu");

    if (sidemenu.style.marginLeft.charAt(0) == "0") {
        closeMenu();
    } else {
        openMenu();
    }
}

function hideEditToolbar() {
    document.getElementById("editToolbar").style.display = "none";
}

function unhideEditToolbar() {
    document.getElementById("editToolbar").style.display = "block";
}

function togglePanel(panelId, indicatorId) {
    var panel = document.getElementById(panelId);
    var indicator = document.getElementById(indicatorId);

    if (panel.style.maxHeight) {
        panel.style.maxHeight = null;
        indicator.style.transform = "rotate(0deg)";
    } else {
        panel.style.maxHeight = panel.scrollHeight + "px";
        indicator.style.transform = "rotate(180deg)";
    }
}

function toggleMeasure(elementId) {
    if (document.getElementById(elementId).checked) {
        measureTool.start();
        document.getElementById("btn_save_route").disabled = false;
    } else {
        measureTool.end();
        document.getElementById("btn_save_route").disabled = true;
    }
}

function toggleChecked(element) {
    element.checked = !element.checked;
}

function toggleDisabled(elementId) {
    var element = document.getElementById(elementId);
    element.disabled = !element.disabled;
}

function revokeConsent() {
    deleteCookie("settings");
    deleteCookie("gdpr_accepted");
    window.location.assign(window.location.href);
}


/**
 * Same glyphs as the #editToolbar create-buttons in app.php (exact
 * Material Symbols Rounded path data - "route"/"add_location"/"pentagon" -
 * the vendored icon font doesn't have route/pentagon, see CLAUDE.md).
 * Kept here too since this toolbar's content is generated dynamically.
 */
const TOOLBAR_ICON_SVG = {
    routeButton: '<svg viewBox="0 -960 960 960"><path d="M247-167q-47-47-47-113v-327q-35-13-57.5-43.5T120-720q0-50 35-85t85-35q50 0 85 35t35 85q0 39-22.5 69.5T280-607v327q0 33 23.5 56.5T360-200q33 0 56.5-23.5T440-280v-400q0-66 47-113t113-47q66 0 113 47t47 113v327q35 13 57.5 43.5T840-240q0 50-35 85t-85 35q-50 0-85-35t-35-85q0-39 22.5-70t57.5-43v-327q0-33-23.5-56.5T600-760q-33 0-56.5 23.5T520-680v400q0 66-47 113t-113 47q-66 0-113-47Zm-7-513q17 0 28.5-11.5T280-720q0-17-11.5-28.5T240-760q-17 0-28.5 11.5T200-720q0 17 11.5 28.5T240-680Zm480 480q17 0 28.5-11.5T760-240q0-17-11.5-28.5T720-280q-17 0-28.5 11.5T680-240q0 17 11.5 28.5T720-200ZM240-720Zm480 480Z"/></svg>',
    poiButton: '<svg viewBox="0 -960 960 960"><path d="M440-520v80q0 17 11.5 28.5T480-400q17 0 28.5-11.5T520-440v-80h80q17 0 28.5-11.5T640-560q0-17-11.5-28.5T600-600h-80v-80q0-17-11.5-28.5T480-720q-17 0-28.5 11.5T440-680v80h-80q-17 0-28.5 11.5T320-560q0 17 11.5 28.5T360-520h80Zm40 334q122-112 181-203.5T720-552q0-109-69.5-178.5T480-800q-101 0-170.5 69.5T240-552q0 71 59 162.5T480-186Zm-28 74q-14-5-25-15-65-60-115-117t-83.5-110.5q-33.5-53.5-51-103T160-552q0-150 96.5-239T480-880q127 0 223.5 89T800-552q0 45-17.5 94.5t-51 103Q698-301 648-244T533-127q-11 10-25 15t-28 5q-14 0-28-5Zm28-448Z"/></svg>',
    areaButton: '<svg viewBox="0 -960 960 960"><path d="M298-200h364l123-369-305-213-305 213 123 369Zm0 80q-26 0-47-15t-29-40L99-543q-8-26 0-51t30-40l305-214q21-14 46-14t46 14l305 214q22 15 30 40t0 51L738-175q-8 25-29 40t-47 15H298Zm182-371Z"/></svg>',
};

/**
 * The small toolbar shown while creating/editing a Route, POI or Area.
 * Styled to match #editToolbar (same pill shape/background/shadow) and
 * positioned a fixed gap to its left, recomputed on every show since it
 * depends on #editToolbar's rendered width.
 */
function showSecondToolbar(elementId) {
    var secondToolbar = document.getElementById('secondToolbar');

    log('showSecondToolbar(' + elementId + ')', LOG_INFO);

    if (!['areaButton', 'poiButton', 'routeButton'].includes(elementId)) {
       return;
    }

    var labels = {
        routeButton: { title: 'Editing Route', action: 'END EDITING', onclick: 'showRouteEditWindow(' + measureTool.index + ', null)' },
        poiButton: { title: 'Editing POI', action: 'CANCEL', onclick: 'cancelEditPoi()' },
        areaButton: { title: 'Editing Area', action: 'END EDITING', onclick: 'showAreaEditWindow(' + measureTool.index + ', null)' },
    };
    var label = labels[elementId];

    secondToolbar.innerHTML =
        '<div class="second-toolbar-icon" title="' + label.title + '">' + TOOLBAR_ICON_SVG[elementId] + '</div>' +
        '<button type="button" class="second-toolbar-end-btn" onclick="' + label.onclick + '">' + label.action + '</button>';

    var editToolbarRect = document.getElementById('editToolbar').getBoundingClientRect();
    secondToolbar.style.right = (editToolbarRect.width + 10 + 8) + 'px';
    secondToolbar.style.display = "flex";
}

function hideSecondToolbar() {
    document.getElementById('secondToolbar').style.display = "none";
}

function disablePoiButton() {
    document.getElementById('poiButton').classList.remove('active');
    document.getElementById('poiButton').classList.add('disabled');
    google.maps.event.removeListener(mapClickListener);
    map.setOptions({draggableCursor:''});
}

function enablePoiButton() {
    document.getElementById('poiButton').classList.remove('active');
    document.getElementById('poiButton').classList.remove('disabled');
}

function disableRouteButton() {
    document.getElementById('routeButton').classList.remove('active');
    document.getElementById('routeButton').classList.add('disabled');
}

function enableRouteButton() {
    document.getElementById('routeButton').classList.remove('disabled');
}

function disableAreaButton() {
    document.getElementById('areaButton').classList.remove('active');
    document.getElementById('areaButton').classList.add('disabled');
}

function enableAreaButton() {
    document.getElementById('areaButton').classList.remove('disabled');
}
