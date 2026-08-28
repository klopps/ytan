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
 * Cookie-Menu
 */

function openCookieMenu() {
    document.getElementById("cookiemenu").style.width = "100%";
}

function closeCookieMenu() {
    document.getElementById("cookiemenu").style.width = "0%";
}


/**
 * Menü
 */

function closeMenu() {
    document.getElementById("sidemenu").style.marginLeft = "-195px";
    document.getElementById("sidemenu-toggle").style.left = "-2px";
    document.getElementById("sidemenu-opener").style.transform = "rotate(0deg)";
}

function openMenu() {
    document.getElementById("sidemenu").style.marginLeft = "0";
    document.getElementById("sidemenu-toggle").style.left = "193px";
    document.getElementById("sidemenu-opener").style.transform = "rotate(180deg)";
}

function hideMenu() {
    document.getElementById("sidemenu").style.display = "none";
    document.getElementById("sidemenu-toggle").style.display = "none";
}

function unhideMenu() {
    document.getElementById("sidemenu").style.display = "block";
    document.getElementById("sidemenu-toggle").style.display = "block";
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
 * The small toolbar shown while creating/editing a Route, POI or Area.
 */
function showSecondToolbar(elementId) {
    var secondToolbar = document.getElementById('secondToolbar');
    var content;

    log('showSecondToolbar(' + elementId + ')', LOG_DEFAULT);

    if (!['areaButton', 'poiButton', 'routeButton'].includes(elementId)) {
       return;
    }

    switch(elementId) {
        case "routeButton":
            content = '<div class="secondToolbarContent"><div class="pesrBtn pesrBtnRoute" title="Editing Route"></div>&nbsp;&nbsp;<button class="endBtn" onClick="showRouteEditWindow(' + measureTool.index + ', null)">END EDITING</button></div>';
            break;
        case "poiButton":
            content = '<div class="secondToolbarContent"><div class="pesrBtn pesrBtnPoi" title="Editing POI"></div>&nbsp;&nbsp;<button class="endBtn" onClick="cancelEditPoi()">CANCEL</button></div>';
            break;
        case "areaButton":
            content = '<div class="secondToolbarContent"><div class="pesrBtn pesrBtnArea" title="Editing Area"></div>&nbsp;&nbsp;<button class="endBtn" onClick="showAreaEditWindow(' + measureTool.index + ', null)">END EDITING</button></div>';
            break;
    }

    secondToolbar.innerHTML = content;
    secondToolbar.style.display = "block";
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
