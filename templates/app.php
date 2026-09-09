<!DOCTYPE html>
<html>
  <head>
    <title><?= htmlspecialchars($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8"/>

    <script>window.YTAN_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script>window.YTAN_GOOGLE_SEARCH_REQUIRES_LOGIN = <?= $googleSearchRequiresLogin ? 'true' : 'false' ?>;</script>

    <script src="./lib/measuretool-googlemap-v3/gmaps-measuretool.umd.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/measuretool-googlemap-v3/gmaps-measuretool.umd.js') ?>"></script>
    <script src="./lib/marked/marked.min.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/marked/marked.min.js') ?>"></script>
    <script src="./lib/markerWithLabel/markerwithlabel.min.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/markerWithLabel/markerwithlabel.min.js') ?>"></script>
    <script src="./lib/light-characteristics/lightcharacteristic.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/light-characteristics/lightcharacteristic.js') ?>"></script>

    <script src="./js/config.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/config.js') ?>"></script>
    <script src="./js/helper.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/helper.js') ?>"></script>
    <script src="./js/api-client.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/api-client.js') ?>"></script>
    <script src="./js/settings.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/settings.js') ?>"></script>
    <script src="./js/ui.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/ui.js') ?>"></script>
    <script src="./js/toast.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/toast.js') ?>"></script>
    <script src="./js/confirm-dialog.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/confirm-dialog.js') ?>"></script>
    <script src="./js/nav-menu.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/nav-menu.js') ?>"></script>
    <script src="./js/map-core.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/map-core.js') ?>"></script>
    <script src="./js/poi.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/poi.js') ?>"></script>
    <script src="./js/route.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/route.js') ?>"></script>
    <script src="./js/area.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/area.js') ?>"></script>
    <script src="./js/tour.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/tour.js') ?>"></script>
    <script src="./js/tour-admin.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/tour-admin.js') ?>"></script>
    <script src="./js/user.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/user.js') ?>"></script>
    <script src="./js/admin-user.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-user.js') ?>"></script>

    <link rel="stylesheet" type="text/css" href="./css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="stylesheet" type="text/css" href="./css/fonts.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/fonts.css') ?>" />

    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
    <link rel="icon" type="image/png" href="<?= $baseUrl ?>/favicon-16x16.png" sizes="16x16">
    <link rel="icon" type="image/png" href="<?= $baseUrl ?>/favicon-32x32.png" sizes="32x32">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $baseUrl ?>/apple-touch-icon.png">
    <link rel="manifest" href="<?= $baseUrl ?>/site.webmanifest">
    <meta name="theme-color" content="#60609F">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($appName) ?>">
  </head>
  <body>
    <div id="gotomylocation" onclick="panToGeolocation();">
      <i class="material-icons-round">my_location</i>
    </div>
    <div id="gdpr">
      <div id="policy-disclaimer">
        <p><?= htmlspecialchars($appName) ?> uses Google Maps. In addition, <?= htmlspecialchars($appName) ?> sets a cookie to store data for convenience purposes
        and to remember that you have agreed to these rules. Our <a href="<?= $baseUrl ?>/legal/datenschutz" target="_blank">privacy policy</a> applies.</p>

        <p>
        <input id="agreement" type="checkbox" name="agreement" onclick="toggleDisabled('startBtn')"><label for="agreement"><span></span>I have read and accept the above notice and the <a href="<?= $baseUrl ?>/legal/datenschutz" target="_blank">privacy policy</a>.</label>
        </p>
        <p>
          <button id="startBtn" class="startbtn" onclick="loadGoogleMaps('<?= htmlspecialchars($mapsApiKey) ?>')" disabled>START</button>
        </p>
      </div>
      <div class="logo_large"></div>
    </div>

    <div id="map"></div>
    <div id="sidemenu-toggle" onclick="toggleMenu()">
      <i class="material-icons-round" id="sidemenu-opener">menu</i>
    </div>

    <div id="mapSearchWrapper" class="map-search-wrapper">
      <div id="mapSearchContainer" class="map-search-container">
        <button id="mapSearchToggle" type="button" onclick="toggleMapSearch()" aria-label="Suche">
          <i class="material-icons-round">search</i>
        </button>
        <input type="text" id="mapSearchInput" class="map-search-input" placeholder="Ort suchen…" autocomplete="off">
      </div>
      <div id="mapSearchDropdown" class="map-search-dropdown" hidden>
        <div id="mapSearchModeToggle" class="map-search-mode-toggle" hidden>
          <button type="button" class="map-search-mode-btn" data-mode="google" onclick="setSearchMode('google')">Google</button>
          <button type="button" class="map-search-mode-btn" data-mode="own" onclick="setSearchMode('own')">POIs</button>
        </div>
        <ul id="mapSearchResultsList" class="map-search-results-list"></ul>
      </div>
    </div>

    <div id="sidemenu">

      <!-- ROOT MENU -->
      <div class="nav-screen nav-screen-active" data-nav-screen="root">
        <div class="nav-brand-row">
          <strong class="nav-brand-name"><?= htmlspecialchars($appName) ?></strong>
          <button type="button" class="nav-close-x" onclick="closeMenu();" aria-label="Close menu"><i class="material-icons-round">close</i></button>
        </div>
        <div class="nav-screen-body">
          <p class="nav-field-label">Map</p>
          <form name="maptype">
            <div class="nav-segmented">
              <input type="radio" id="maptype1" name="maptypeselector" onclick="editMapType(this)" value="hybrid" checked><label for="maptype1">HYBRID</label>
              <input type="radio" id="maptype2" name="maptypeselector" onclick="editMapType(this)" value="terrain"><label for="maptype2">TERRAIN</label>
              <input type="radio" id="maptype3" name="maptypeselector" onclick="editMapType(this)" value="satellite"><label for="maptype3">SAT</label>
            </div>
          </form>

          <ul class="nav-menu-list">
            <li><button type="button" class="nav-menu-row" onclick="navMenuGoTo('pois');"><i class="material-icons-round nav-menu-row-icon">place</i><span class="nav-menu-row-labels">POIs</span><i class="material-icons-round nav-menu-row-chevron">chevron_right</i></button></li>
            <li><button type="button" class="nav-menu-row" onclick="openTourAdminMenu();"><i class="material-icons-round nav-menu-row-icon">tour</i><span class="nav-menu-row-labels">Tours</span><i class="material-icons-round nav-menu-row-chevron">chevron_right</i></button></li>
            <li><button type="button" class="nav-menu-row" onclick="fitToPoiBounds();"><i class="material-icons-round nav-menu-row-icon">fit_screen</i><span class="nav-menu-row-labels">Fit POIs</span></button></li>
            <li><button type="button" class="nav-menu-row" onclick="shareMap();"><i class="material-icons-round nav-menu-row-icon">share</i><span class="nav-menu-row-labels">Share</span></button></li>
            <li id="userAdminMenuBtn" style="display:none;"><button type="button" class="nav-menu-row" onclick="navMenuGoTo('site-settings');"><i class="material-icons-round nav-menu-row-icon">settings</i><span class="nav-menu-row-labels">Site Settings</span><i class="material-icons-round nav-menu-row-chevron">chevron_right</i></button></li>
            <li><button type="button" class="nav-menu-row" onclick="showUserWindow();"><i class="material-icons-round nav-menu-row-icon">account_circle</i><span class="nav-menu-row-labels">Preferences<span class="nav-menu-row-sub" id="preferencesRowSub">Not signed in</span></span><i class="material-icons-round nav-menu-row-chevron">chevron_right</i></button></li>
          </ul>

          <div class="nav-divider"></div>

          <ul class="nav-menu-list nav-menu-footer">
            <li><button type="button" class="nav-menu-row" onclick="openLegalMenu('<?= $baseUrl ?>/about');"><i class="material-icons-round nav-menu-row-icon">info</i><span class="nav-menu-row-labels">About</span></button></li>
            <li><button type="button" class="nav-menu-row" onclick="openLegalMenu('<?= $baseUrl ?>/legal/imprint');"><i class="material-icons-round nav-menu-row-icon">description</i><span class="nav-menu-row-labels">Imprint</span></button></li>
            <li><button type="button" class="nav-menu-row" onclick="openLegalMenu('<?= $baseUrl ?>/legal/privacy');"><i class="material-icons-round nav-menu-row-icon">privacy_tip</i><span class="nav-menu-row-labels">Privacy Note</span></button></li>
            <li><button type="button" class="nav-menu-row" onclick="openCookieMenu();"><i class="material-icons-round nav-menu-row-icon">verified_user</i><span class="nav-menu-row-labels">Cookies</span></button></li>
          </ul>

          <div class="nav-version-text">v<?= htmlspecialchars($appVersion) ?></div>

          <div class="panel-logo" id="sidemenuLogo" onclick="handleSidemenuLogoClick();"></div>
        </div>
      </div>

      <!-- POIS -->
      <div class="nav-screen nav-screen-off-right" data-nav-screen="pois">
        <div class="nav-screen-header">
          <button type="button" class="nav-back" onclick="navMenuBack();"><i class="material-icons-round">arrow_back</i></button>
          <h3>POIs</h3>
        </div>
        <div class="nav-screen-body">
          <div class="nav-quick-actions">
            <button type="button" class="nav-chip-btn" onclick="selectAllPois();">Select all</button>
            <button type="button" class="nav-chip-btn" onclick="selectNonePois();">Select none</button>
          </div>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#8886c9"></span><span class="nav-toggle-text">POI</span><input type="checkbox" id="detail0" name="detail0" class="nav-switch-input" onclick="toggleMarkerByType(this, 0);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#5f9e6e"></span><span class="nav-toggle-text">Camps</span><input type="checkbox" id="detail1" name="detail1" class="nav-switch-input" onclick="toggleMarkerByType(this, 1);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#7a8fae"></span><span class="nav-toggle-text">Shelter</span><input type="checkbox" id="detail10" name="detail10" class="nav-switch-input" onclick="toggleMarkerByType(this, 10);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#4f9d68"></span><span class="nav-toggle-text">Campsite (commercial)</span><input type="checkbox" id="detail11" name="detail11" class="nav-switch-input" onclick="toggleMarkerByType(this, 11);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#c98a3b"></span><span class="nav-toggle-text">Landing Sites</span><input type="checkbox" id="detail2" name="detail2" class="nav-switch-input" onclick="toggleMarkerByType(this, 2);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#3f9dbf"></span><span class="nav-toggle-text">Drinking Water</span><input type="checkbox" id="detail3" name="detail3" class="nav-switch-input" onclick="toggleMarkerByType(this, 3);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#8c8c8c"></span><span class="nav-toggle-text">Toilets</span><input type="checkbox" id="detail4" name="detail4" class="nav-switch-input" onclick="toggleMarkerByType(this, 4);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#a2793f"></span><span class="nav-toggle-text">Historic Sites</span><input type="checkbox" id="detail5" name="detail5" class="nav-switch-input" onclick="toggleMarkerByType(this, 5);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#c0524a"></span><span class="nav-toggle-text">Danger Zones</span><input type="checkbox" id="detail6" name="detail6" class="nav-switch-input" onclick="toggleMarkerByType(this, 6);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#5aa568"></span><span class="nav-toggle-text">Natural Sights</span><input type="checkbox" id="detail7" name="detail7" class="nav-switch-input" onclick="toggleMarkerByType(this, 7);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#c77db0"></span><span class="nav-toggle-text">Shopping</span><input type="checkbox" id="detail8" name="detail8" class="nav-switch-input" onclick="toggleMarkerByType(this, 8);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#b08b3f"></span><span class="nav-toggle-text">Portages</span><input type="checkbox" id="detail9" name="detail9" class="nav-switch-input" onclick="toggleMarkerByType(this, 9);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#d15f6c"></span><span class="nav-toggle-text">Medical care</span><input type="checkbox" id="detail12" name="detail12" class="nav-switch-input" onclick="toggleMarkerByType(this, 12);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#6b7fc9"></span><span class="nav-toggle-text">Clubs / Institutions</span><input type="checkbox" id="detail13" name="detail13" class="nav-switch-input" onclick="toggleMarkerByType(this, 13);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#d1a63f"></span><span class="nav-toggle-text">Lights (Lighthouses, Sea Marks)</span><input type="checkbox" id="detail14" name="detail14" class="nav-switch-input" onclick="toggleMarkerByType(this, 14);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#5b6b8c"></span><span class="nav-toggle-text">Parking</span><input type="checkbox" id="detail15" name="detail15" class="nav-switch-input" onclick="toggleMarkerByType(this, 15);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#3f8f8f"></span><span class="nav-toggle-text">Fishing</span><input type="checkbox" id="detail16" name="detail16" class="nav-switch-input" onclick="toggleMarkerByType(this, 16);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <div class="nav-divider"></div>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#60609f"></span><span class="nav-toggle-text">Routes</span><input type="checkbox" id="detailroutes" name="detailroutes" class="nav-switch-input" onchange="toggleRoutes(this);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#8886c9"></span><span class="nav-toggle-text">Areas</span><input type="checkbox" id="detailareas" name="detailareas" class="nav-switch-input" onchange="toggleAreas(this);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#9c99ad"></span><span class="nav-toggle-text">Windshelter Indicators</span><input type="checkbox" id="detailwsi" name="detailwsi" class="nav-switch-input" onchange="toggleWsiMarkers(this);"><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
        </div>
      </div>

      <!-- SITE SETTINGS (admin only) -->
      <div class="nav-screen nav-screen-off-right" data-nav-screen="site-settings">
        <div class="nav-screen-header">
          <button type="button" class="nav-back" onclick="navMenuBack();"><i class="material-icons-round">arrow_back</i></button>
          <h3>Site Settings</h3>
        </div>
        <div class="nav-screen-body">
          <ul class="nav-menu-list">
            <li><button type="button" class="nav-menu-row" onclick="openUserAdminMenu();"><i class="material-icons-round nav-menu-row-icon">admin_panel_settings</i><span class="nav-menu-row-labels">Users<span class="nav-menu-row-sub">Manage accounts &amp; permissions</span></span><i class="material-icons-round nav-menu-row-chevron">chevron_right</i></button></li>
          </ul>
          <label class="nav-toggle-row">
            <span class="nav-toggle-text">Google search requires login</span>
            <input type="checkbox" id="settingGoogleSearchRequiresLogin" class="nav-switch-input" onclick="toggleGoogleSearchRequiresLogin(this)" <?= $googleSearchRequiresLogin ? 'checked' : '' ?>>
            <span class="nav-switch-track"><span class="nav-switch-thumb"></span></span>
          </label>
        </div>
      </div>

      <!-- PREFERENCES -->
      <div class="nav-screen nav-screen-off-right" data-nav-screen="preferences">
        <div class="nav-screen-header">
          <button type="button" class="nav-back" onclick="navMenuBack();"><i class="material-icons-round">arrow_back</i></button>
          <h3>Preferences</h3>
        </div>
        <div class="nav-screen-body">
          <p class="nav-field-label">Appearance</p>
          <form name="themeselector">
            <div class="nav-segmented">
              <input type="radio" id="theme1" name="themeselector" onclick="setTheme('light')" value="light" checked><label for="theme1">LIGHT</label>
              <input type="radio" id="theme2" name="themeselector" onclick="setTheme('dark')" value="dark"><label for="theme2">DARK</label>
            </div>
          </form>
          <p class="nav-field-label">Units</p>
          <form name="units">
            <div class="nav-segmented">
              <input type="radio" id="unit1" name="unitselector" onclick="editUnit(this)" value="metric" checked><label for="unit1">METRIC</label>
              <input type="radio" id="unit2" name="unitselector" onclick="editUnit(this)" value="nautical"><label for="unit2">NAUTICAL</label>
            </div>
          </form>
          <div class="nav-example-card">
            <div>
              <div class="nav-example-value" id="unitExampleValue">12.4km</div>
              <div class="nav-example-caption">Fynshav &ndash; S&oslash;by crossing</div>
            </div>
            <i class="material-icons-round">straighten</i>
          </div>
          <div class="nav-divider"></div>
          <div id="userWindow"></div>
        </div>
      </div>

    </div>

    <!-- COOKIE MENU ----------------------------------->
    <div id="cookiemenu" class="cookiemenu">
      <div class="cm_content">
        <h2><?= htmlspecialchars($appName) ?></h2>
        <div class="cookieConsentBody">
          <p>
            <?= htmlspecialchars($appName) ?> requires the use of cookies to function properly and you have consented to their use.
            Of course, you can revoke your consent and the cookie will be deleted.
            However, you will then no longer be able to use <?= htmlspecialchars($appName) ?>.
          </p>
          <div class="button" onclick="closeCookieMenu();"><i class="material-icons-round">check</i>&nbsp;I agree to the use of cookies</div>
          <div class="button" onclick="revokeConsent();"><i class="material-icons-round">not_interested</i>&nbsp;I would like to delete the cookies</div>
        </div>
        <div class="panel-logo"></div>
      </div>
    </div>

    <!-- LEGAL MENU (Imprint / Privacy Notice) ----------------------------------->
    <div id="legalmenu" class="cookiemenu">
      <div id="legalmenu-close-btn" class="panel-close-btn" onclick="closeLegalMenu();"><i class="material-icons-round">close</i></div>
      <div class="cm_content">
        <div id="legalmenu-content"></div>
        <div class="panel-logo"></div>
      </div>
    </div>

    <!-- USER ADMIN MENU (admin only) ----------------------------------->
    <div id="useradminmenu" class="cookiemenu">
      <div id="useradminmenu-close-btn" class="panel-close-btn" onclick="closeUserAdminMenu();"><i class="material-icons-round">close</i></div>
      <div class="cm_content">
        <h2>Users</h2>
        <div id="useradminmenu-form"></div>
        <div id="useradminmenu-list"></div>
        <div class="panel-logo"></div>
      </div>
    </div>

    <!-- TOUR ADMIN MENU (browse/search tours, view details, manage a
         tour's own metadata and route membership) ----------------------->
    <div id="touradminmenu" class="cookiemenu">
      <div id="touradminmenu-close-btn" class="panel-close-btn" onclick="closeTourAdminMenu();"><i class="material-icons-round">close</i></div>
      <div class="cm_content">
        <h2>Tours</h2>
        <div id="touradminmenu-body"></div>
        <div class="panel-logo"></div>
      </div>
    </div>

    <!-- TOUR MODE BADGE - shown on the main map while a tour is active
         (see tour.js: activateTourMode()/exitTourMode()). Positioned below
         the sidemenu-toggle/map-search row so it never overlaps them, and
         left-aligned rather than centered so it never crowds #editToolbar
         on the opposite corner - editToolbar stays fully usable while a
         tour is active, it's just a route filter, not an overlay. -->
    <div id="tourModeBadge" class="tour-mode-badge" style="display:none;">
      <i class="material-icons-round tour-mode-badge-icon">explore</i>
      <div class="tour-mode-badge-text">
        <span id="tourModeBadgeName" class="tour-mode-badge-name"></span>
        <span class="tour-mode-badge-sub">Tour Mode</span>
      </div>
      <div class="tour-mode-badge-close" onclick="exitTourMode();"><i class="material-icons-round">close</i></div>
    </div>

    <!-- EDIT ADDITIONAL TOOLBAR -------------------------------------->
    <div id="secondToolbar">
    </div>

    <!-- EDIT TOOLBAR -------------------------------------->
    <div id="editToolbar">
      <div id="routeButton" class="toolbar-icon-btn" title="Create Route" onclick="editRouteBtnClick('routeButton');"><svg viewBox="0 -960 960 960"><path d="M247-167q-47-47-47-113v-327q-35-13-57.5-43.5T120-720q0-50 35-85t85-35q50 0 85 35t35 85q0 39-22.5 69.5T280-607v327q0 33 23.5 56.5T360-200q33 0 56.5-23.5T440-280v-400q0-66 47-113t113-47q66 0 113 47t47 113v327q35 13 57.5 43.5T840-240q0 50-35 85t-85 35q-50 0-85-35t-35-85q0-39 22.5-70t57.5-43v-327q0-33-23.5-56.5T600-760q-33 0-56.5 23.5T520-680v400q0 66-47 113t-113 47q-66 0-113-47Zm-7-513q17 0 28.5-11.5T280-720q0-17-11.5-28.5T240-760q-17 0-28.5 11.5T200-720q0 17 11.5 28.5T240-680Zm480 480q17 0 28.5-11.5T760-240q0-17-11.5-28.5T720-280q-17 0-28.5 11.5T680-240q0 17 11.5 28.5T720-200ZM240-720Zm480 480Z"/></svg></div>
      <div id="poiButton" class="toolbar-icon-btn disabled" title="Create POI" onclick="editPoiBtnClick('poiButton');"><svg viewBox="0 -960 960 960"><path d="M440-520v80q0 17 11.5 28.5T480-400q17 0 28.5-11.5T520-440v-80h80q17 0 28.5-11.5T640-560q0-17-11.5-28.5T600-600h-80v-80q0-17-11.5-28.5T480-720q-17 0-28.5 11.5T440-680v80h-80q-17 0-28.5 11.5T320-560q0 17 11.5 28.5T360-520h80Zm40 334q122-112 181-203.5T720-552q0-109-69.5-178.5T480-800q-101 0-170.5 69.5T240-552q0 71 59 162.5T480-186Zm-28 74q-14-5-25-15-65-60-115-117t-83.5-110.5q-33.5-53.5-51-103T160-552q0-150 96.5-239T480-880q127 0 223.5 89T800-552q0 45-17.5 94.5t-51 103Q698-301 648-244T533-127q-11 10-25 15t-28 5q-14 0-28-5Zm28-448Z"/></svg></div>
      <div id="areaButton" class="toolbar-icon-btn" title="Create Area" onclick="editAreaBtnClick('areaButton');"><svg viewBox="0 -960 960 960"><path d="M298-200h364l123-369-305-213-305 213 123 369Zm0 80q-26 0-47-15t-29-40L99-543q-8-26 0-51t30-40l305-214q21-14 46-14t46 14l305 214q22 15 30 40t0 51L738-175q-8 25-29 40t-47 15H298Zm182-371Z"/></svg></div>
    </div>

    <script>
      let logLevel = <?= (int) $logLevel ?>;

      applyStoredTheme();

      if (getCookie("gdpr_accepted") == "yes") {
        loadGoogleMaps("<?= htmlspecialchars($mapsApiKey, ENT_QUOTES) ?>");
      } else {
        document.getElementById("gdpr").style.display = "block";
      }

      var user = initUser();

      if (Ytan.isLoggedIn()) {
        Ytan.get('/auth/me').then(answer => {
          user.id = answer.data.sub;
          user.username = answer.data.username;
          user.email = answer.data.email;
          user.firstname = answer.data.firstname;
          user.lastname = answer.data.lastname;
          user.is_admin = !!answer.data.is_admin;
          user.tour_create = !!answer.data.tour_create;
          user.tour_publish = !!answer.data.tour_publish;
          user.tour_manage = !!answer.data.tour_manage;
          user.tour_copy = !!answer.data.tour_copy;
          user.pending_email = answer.data.pending_email;
          user.pending_email_expires_at = answer.data.pending_email_expires_at;

          sessionStorage.setItem('user', JSON.stringify(user));
          updatePreferencesRowLabel();
          updateAdminMenuVisibility();
          updateGoogleSearchAllowed();
        }).catch(() => {
          // stored token is invalid/expired - fall back to the logged-out state
          Ytan.setToken(null);
          sessionStorage.removeItem('user');
        });
      }

      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register("<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/sw.js", {
          scope: "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/"
        }).catch(err => log('Service worker registration failed', LOG_WARN, err));
      }

      if (navigator.storage && navigator.storage.persist) {
        navigator.storage.persist().catch(err => log('navigator.storage.persist() failed', LOG_WARN, err));
      }
    </script>

    <div id="shield"></div>
    <div id="toastContainer" class="toast-container"></div>
  </body>
</html>
