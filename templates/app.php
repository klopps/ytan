<!DOCTYPE html>
<html>
  <head>
    <title><?= htmlspecialchars($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8"/>

    <script>window.YTAN_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script>window.YTAN_GOOGLE_SEARCH_REQUIRES_LOGIN = <?= $googleSearchRequiresLogin ? 'true' : 'false' ?>;</script>
    <script>window.YTAN_LOCALE = "<?= htmlspecialchars($translator->locale(), ENT_QUOTES) ?>";</script>
    <script>window.YTAN_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>

    <script src="./lib/measuretool-googlemap-v3/gmaps-measuretool.umd.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/measuretool-googlemap-v3/gmaps-measuretool.umd.js') ?>"></script>
    <script src="./lib/marked/marked.min.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/marked/marked.min.js') ?>"></script>
    <script src="./lib/markerWithLabel/markerwithlabel.min.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/markerWithLabel/markerwithlabel.min.js') ?>"></script>
    <script src="./lib/light-characteristics/lightcharacteristic.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/light-characteristics/lightcharacteristic.js') ?>"></script>

    <script src="./js/config.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/config.js') ?>"></script>
    <script src="./js/i18n.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/i18n.js') ?>"></script>
    <script src="./js/helper.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/helper.js') ?>"></script>
    <script src="./js/api-client.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/api-client.js') ?>"></script>
    <script src="./js/settings.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/settings.js') ?>"></script>
    <script src="./js/ui.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/ui.js') ?>"></script>
    <script src="./js/toast.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/toast.js') ?>"></script>
    <script src="./js/photo-upload.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/photo-upload.js') ?>"></script>
    <script src="./js/capacitor-bridge.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/capacitor-bridge.js') ?>"></script>
    <script src="./js/confirm-dialog.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/confirm-dialog.js') ?>"></script>
    <script src="./js/nav-menu.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/nav-menu.js') ?>"></script>
    <script src="./js/map-core.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/map-core.js') ?>"></script>
    <script src="./js/weather.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/weather.js') ?>"></script>
    <script src="./js/poi.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/poi.js') ?>"></script>
    <script src="./js/route.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/route.js') ?>"></script>
    <script src="./js/track-recorder.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/track-recorder.js') ?>"></script>
    <script src="./js/area.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/area.js') ?>"></script>
    <script src="./js/tour.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/tour.js') ?>"></script>
    <script src="./js/tour-admin.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/tour-admin.js') ?>"></script>
    <script src="./js/user.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/user.js') ?>"></script>
    <!-- admin-user.js moved to /admin/users (templates/admin-users.php) -
         no longer part of the main SPA's script chain. -->

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
        <p><?= $t('app.gdpr.intro', [
          'app' => htmlspecialchars($appName),
          'link_open' => '<a href="' . htmlspecialchars($baseUrl, ENT_QUOTES) . '/legal/privacy" target="_blank">',
          'link_close' => '</a>',
        ]) ?></p>

        <p>
        <input id="agreement" type="checkbox" name="agreement" onclick="toggleDisabled('startBtn')"><label for="agreement"><span></span><?= $t('app.gdpr.checkbox', [
          'link_open' => '<a href="' . htmlspecialchars($baseUrl, ENT_QUOTES) . '/legal/privacy" target="_blank">',
          'link_close' => '</a>',
        ]) ?></label>
        </p>
        <p>
          <button id="startBtn" class="startbtn" onclick="loadGoogleMaps('<?= htmlspecialchars($mapsApiKey) ?>')" disabled><?= $t('app.gdpr.start') ?></button>
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
        <button id="mapSearchToggle" type="button" onclick="toggleMapSearch()" aria-label="<?= htmlspecialchars($t('app.search.aria_label'), ENT_QUOTES) ?>">
          <i class="material-icons-round">search</i>
        </button>
        <input type="text" id="mapSearchInput" class="map-search-input" placeholder="<?= htmlspecialchars($t('app.search.placeholder'), ENT_QUOTES) ?>" autocomplete="off">
      </div>
      <div id="mapSearchDropdown" class="map-search-dropdown" hidden>
        <div id="mapSearchModeToggle" class="map-search-mode-toggle" hidden>
          <button type="button" class="map-search-mode-btn" data-mode="google" onclick="setSearchMode('google')">Google</button>
          <button type="button" class="map-search-mode-btn" data-mode="own" onclick="setSearchMode('own')"><?= $t('app.nav.pois') ?></button>
        </div>
        <ul id="mapSearchResultsList" class="map-search-results-list"></ul>
      </div>
    </div>

    <div id="sidemenu">

      <!-- ROOT MENU -->
      <div class="nav-screen nav-screen-active" data-nav-screen="root">
        <div class="nav-brand-row">
          <strong class="nav-brand-name"><?= htmlspecialchars($appName) ?></strong>
          <button type="button" class="nav-close-x" onclick="closeMenu();" aria-label="<?= htmlspecialchars($t('app.nav.close_menu'), ENT_QUOTES) ?>"><i class="material-icons-round">close</i></button>
        </div>
        <div class="nav-screen-body">
          <p class="nav-field-label"><?= $t('app.map.label') ?></p>
          <form name="maptype">
            <div class="nav-segmented">
              <input type="radio" id="maptype1" name="maptypeselector" onclick="editMapType(this)" value="hybrid" checked><label for="maptype1"><?= $t('app.map.hybrid') ?></label>
              <input type="radio" id="maptype2" name="maptypeselector" onclick="editMapType(this)" value="terrain"><label for="maptype2"><?= $t('app.map.terrain') ?></label>
              <input type="radio" id="maptype3" name="maptypeselector" onclick="editMapType(this)" value="satellite"><label for="maptype3"><?= $t('app.map.sat') ?></label>
            </div>
          </form>

          <ul class="nav-menu-list">
            <li><button type="button" class="nav-menu-row" onclick="navMenuGoTo('pois');"><i class="material-icons-round nav-menu-row-icon">place</i><span class="nav-menu-row-labels"><?= $t('app.nav.pois') ?></span><i class="material-icons-round nav-menu-row-chevron">chevron_right</i></button></li>
            <li><button type="button" class="nav-menu-row" onclick="openTourAdminMenu();"><i class="material-icons-round nav-menu-row-icon">tour</i><span class="nav-menu-row-labels"><?= $t('app.nav.tours') ?></span><i class="material-icons-round nav-menu-row-chevron">chevron_right</i></button></li>
            <!-- Native Capacitor shell only - hidden by default, shown by
                 track-recorder.js's initTrackRecorder() (no-op in a normal
                 browser/PWA, matching capacitor-bridge.js's own guard). -->
            <li id="trackRecorderMenuRow" style="display:none;"><button type="button" class="nav-menu-row" onclick="openTrackRecorderScreen();"><i class="material-icons-round nav-menu-row-icon">fiber_manual_record</i><span class="nav-menu-row-labels"><?= $t('app.nav.track_recorder') ?></span><i class="material-icons-round nav-menu-row-chevron">chevron_right</i></button></li>
            <li><button type="button" class="nav-menu-row" onclick="shareMap();"><i class="material-icons-round nav-menu-row-icon">share</i><span class="nav-menu-row-labels"><?= $t('app.nav.share') ?></span></button></li>
            <li><button type="button" class="nav-menu-row" onclick="showUserWindow();"><i class="material-icons-round nav-menu-row-icon">account_circle</i><span class="nav-menu-row-labels"><?= $t('app.nav.profile') ?><span class="nav-menu-row-sub" id="profileRowSub"><?= $t('app.nav.not_signed_in') ?></span></span><i class="material-icons-round nav-menu-row-chevron">chevron_right</i></button></li>
            <li><button type="button" class="nav-menu-row" onclick="navMenuGoTo('preferences');"><i class="material-icons-round nav-menu-row-icon">tune</i><span class="nav-menu-row-labels"><?= $t('app.nav.preferences') ?></span><i class="material-icons-round nav-menu-row-chevron">chevron_right</i></button></li>
            <li id="adminMenuBtn" style="display:none;"><button type="button" class="nav-menu-row" onclick="window.location.href='<?= $baseUrl ?>/admin';"><i class="material-icons-round nav-menu-row-icon">settings</i><span class="nav-menu-row-labels"><?= $t('app.nav.administration') ?></span><i class="material-icons-round nav-menu-row-chevron">chevron_right</i></button></li>
          </ul>

          <div class="nav-divider"></div>

          <ul class="nav-menu-list nav-menu-footer">
            <li><button type="button" class="nav-menu-row" onclick="openLegalMenu('<?= $baseUrl ?>/about', '<?= $t('app.nav.about') ?>');"><i class="material-icons-round nav-menu-row-icon">info</i><span class="nav-menu-row-labels"><?= $t('app.nav.about') ?></span></button></li>
            <li><button type="button" class="nav-menu-row" onclick="openLegalMenu('<?= $baseUrl ?>/legal/imprint', '<?= $t('app.nav.imprint') ?>');"><i class="material-icons-round nav-menu-row-icon">description</i><span class="nav-menu-row-labels"><?= $t('app.nav.imprint') ?></span></button></li>
            <li><button type="button" class="nav-menu-row" onclick="openLegalMenu('<?= $baseUrl ?>/legal/privacy', '<?= $t('app.nav.privacy_note') ?>');"><i class="material-icons-round nav-menu-row-icon">privacy_tip</i><span class="nav-menu-row-labels"><?= $t('app.nav.privacy_note') ?></span></button></li>
            <li><button type="button" class="nav-menu-row" onclick="openCookieMenu();"><i class="material-icons-round nav-menu-row-icon">verified_user</i><span class="nav-menu-row-labels"><?= $t('app.nav.cookies') ?></span></button></li>
          </ul>

          <div class="nav-version-text">v<?= htmlspecialchars($appVersion) ?></div>

          <div class="panel-logo" id="sidemenuLogo" onclick="handleSidemenuLogoClick();"></div>
        </div>
      </div>

      <!-- POIS -->
      <div class="nav-screen nav-screen-off-right" data-nav-screen="pois">
        <div class="nav-screen-header">
          <button type="button" class="nav-back" onclick="navMenuBack();"><i class="material-icons-round">arrow_back</i></button>
          <h3><?= $t('app.nav.pois') ?></h3>
        </div>
        <div class="nav-screen-body">
          <div class="nav-quick-actions">
            <button type="button" class="nav-chip-btn" onclick="selectAllPois();"><?= $t('app.pois.select_all') ?></button>
            <button type="button" class="nav-chip-btn" onclick="selectNonePois();"><?= $t('app.pois.select_none') ?></button>
          </div>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#8886c9"></span><span class="nav-toggle-text"><?= $t('app.poitype.0') ?></span><input type="checkbox" id="detail0" name="detail0" class="nav-switch-input" onclick="toggleMarkerByType(this, 0);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#5f9e6e"></span><span class="nav-toggle-text"><?= $t('app.poitype.1') ?></span><input type="checkbox" id="detail1" name="detail1" class="nav-switch-input" onclick="toggleMarkerByType(this, 1);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#7a8fae"></span><span class="nav-toggle-text"><?= $t('app.poitype.10') ?></span><input type="checkbox" id="detail10" name="detail10" class="nav-switch-input" onclick="toggleMarkerByType(this, 10);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#4f9d68"></span><span class="nav-toggle-text"><?= $t('app.poitype.11') ?></span><input type="checkbox" id="detail11" name="detail11" class="nav-switch-input" onclick="toggleMarkerByType(this, 11);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#c98a3b"></span><span class="nav-toggle-text"><?= $t('app.poitype.2') ?></span><input type="checkbox" id="detail2" name="detail2" class="nav-switch-input" onclick="toggleMarkerByType(this, 2);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#3f9dbf"></span><span class="nav-toggle-text"><?= $t('app.poitype.3') ?></span><input type="checkbox" id="detail3" name="detail3" class="nav-switch-input" onclick="toggleMarkerByType(this, 3);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#8c8c8c"></span><span class="nav-toggle-text"><?= $t('app.poitype.4') ?></span><input type="checkbox" id="detail4" name="detail4" class="nav-switch-input" onclick="toggleMarkerByType(this, 4);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#a2793f"></span><span class="nav-toggle-text"><?= $t('app.poitype.5') ?></span><input type="checkbox" id="detail5" name="detail5" class="nav-switch-input" onclick="toggleMarkerByType(this, 5);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#c0524a"></span><span class="nav-toggle-text"><?= $t('app.poitype.6') ?></span><input type="checkbox" id="detail6" name="detail6" class="nav-switch-input" onclick="toggleMarkerByType(this, 6);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#5aa568"></span><span class="nav-toggle-text"><?= $t('app.poitype.7') ?></span><input type="checkbox" id="detail7" name="detail7" class="nav-switch-input" onclick="toggleMarkerByType(this, 7);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#c77db0"></span><span class="nav-toggle-text"><?= $t('app.poitype.8') ?></span><input type="checkbox" id="detail8" name="detail8" class="nav-switch-input" onclick="toggleMarkerByType(this, 8);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#b08b3f"></span><span class="nav-toggle-text"><?= $t('app.poitype.9') ?></span><input type="checkbox" id="detail9" name="detail9" class="nav-switch-input" onclick="toggleMarkerByType(this, 9);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#d15f6c"></span><span class="nav-toggle-text"><?= $t('app.poitype.12') ?></span><input type="checkbox" id="detail12" name="detail12" class="nav-switch-input" onclick="toggleMarkerByType(this, 12);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#6b7fc9"></span><span class="nav-toggle-text"><?= $t('app.poitype.13') ?></span><input type="checkbox" id="detail13" name="detail13" class="nav-switch-input" onclick="toggleMarkerByType(this, 13);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#d1a63f"></span><span class="nav-toggle-text"><?= $t('app.poitype.14') ?></span><input type="checkbox" id="detail14" name="detail14" class="nav-switch-input" onclick="toggleMarkerByType(this, 14);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#5b6b8c"></span><span class="nav-toggle-text"><?= $t('app.poitype.15') ?></span><input type="checkbox" id="detail15" name="detail15" class="nav-switch-input" onclick="toggleMarkerByType(this, 15);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#3f8f8f"></span><span class="nav-toggle-text"><?= $t('app.poitype.16') ?></span><input type="checkbox" id="detail16" name="detail16" class="nav-switch-input" onclick="toggleMarkerByType(this, 16);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <div class="nav-divider"></div>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#60609f"></span><span class="nav-toggle-text"><?= $t('app.detail.routes') ?></span><input type="checkbox" id="detailroutes" name="detailroutes" class="nav-switch-input" onchange="toggleRoutes(this);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#8886c9"></span><span class="nav-toggle-text"><?= $t('app.detail.areas') ?></span><input type="checkbox" id="detailareas" name="detailareas" class="nav-switch-input" onchange="toggleAreas(this);" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <label class="nav-toggle-row"><span class="nav-swatch" style="background:#9c99ad"></span><span class="nav-toggle-text"><?= $t('app.detail.wsi') ?></span><input type="checkbox" id="detailwsi" name="detailwsi" class="nav-switch-input" onchange="toggleWsiMarkers(this);"><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
          <div class="nav-divider"></div>
          <ul class="nav-menu-list">
            <li><button type="button" class="nav-menu-row" onclick="fitToPoiBounds(); closeMenu();"><i class="material-icons-round nav-menu-row-icon">fit_screen</i><span class="nav-menu-row-labels"><?= $t('app.pois.fit_all') ?></span></button></li>
          </ul>
        </div>
      </div>

      <!-- PREFERENCES -->
      <div class="nav-screen nav-screen-off-right" data-nav-screen="preferences">
        <div class="nav-screen-header">
          <button type="button" class="nav-back" onclick="navMenuBack();"><i class="material-icons-round">arrow_back</i></button>
          <h3><?= $t('app.nav.preferences') ?></h3>
        </div>
        <div class="nav-screen-body">
          <p class="nav-field-label"><?= $t('app.preferences.appearance') ?></p>
          <form name="themeselector">
            <div class="nav-segmented">
              <input type="radio" id="theme1" name="themeselector" onclick="setTheme('light')" value="light" checked><label for="theme1"><?= $t('app.theme.light') ?></label>
              <input type="radio" id="theme2" name="themeselector" onclick="setTheme('dark')" value="dark"><label for="theme2"><?= $t('app.theme.dark') ?></label>
            </div>
          </form>
          <p class="nav-field-label"><?= $t('app.preferences.units') ?></p>
          <form name="units">
            <div class="nav-segmented">
              <input type="radio" id="unit1" name="unitselector" onclick="editUnit(this)" value="metric" checked><label for="unit1"><?= $t('app.unit.metric') ?></label>
              <input type="radio" id="unit2" name="unitselector" onclick="editUnit(this)" value="nautical"><label for="unit2"><?= $t('app.unit.nautical') ?></label>
            </div>
          </form>
          <div class="nav-example-card">
            <div>
              <div class="nav-example-value" id="unitExampleValue">12.4km</div>
              <div class="nav-example-caption"><?= $t('app.preferences.example_caption') ?></div>
            </div>
            <i class="material-icons-round">straighten</i>
          </div>
          <p class="nav-field-label"><?= $t('app.preferences.wind_unit') ?></p>
          <form name="windunit">
            <div class="nav-segmented">
              <input type="radio" id="windunit1" name="windunitselector" onclick="editWindUnit(this)" value="bft"><label for="windunit1"><?= $t('app.wind_unit.bft') ?></label>
              <input type="radio" id="windunit2" name="windunitselector" onclick="editWindUnit(this)" value="ms"><label for="windunit2"><?= $t('app.wind_unit.ms') ?></label>
              <input type="radio" id="windunit3" name="windunitselector" onclick="editWindUnit(this)" value="kmh" checked><label for="windunit3"><?= $t('app.wind_unit.kmh') ?></label>
              <input type="radio" id="windunit4" name="windunitselector" onclick="editWindUnit(this)" value="kn"><label for="windunit4"><?= $t('app.wind_unit.kn') ?></label>
            </div>
          </form>
          <p class="nav-field-label"><?= $t('app.preferences.route_smoothing') ?></p>
          <form name="routesmoothing">
            <div class="nav-segmented">
              <input type="radio" id="routesmoothing1" name="routesmoothingselector" onclick="editRouteSmoothing(this)" value="smooth" checked><label for="routesmoothing1"><?= $t('app.route_smoothing.smooth') ?></label>
              <input type="radio" id="routesmoothing2" name="routesmoothingselector" onclick="editRouteSmoothing(this)" value="straight"><label for="routesmoothing2"><?= $t('app.route_smoothing.straight') ?></label>
            </div>
          </form>
          <p class="nav-field-label"><?= $t('app.preferences.language') ?></p>
          <form name="language">
            <div class="nav-segmented">
              <input type="radio" id="language1" name="languageselector" onclick="setLanguage('en')" value="en" <?= $translator->locale() === 'en' ? 'checked' : '' ?>><label for="language1"><?= $t('app.language.en') ?></label>
              <input type="radio" id="language2" name="languageselector" onclick="setLanguage('de')" value="de" <?= $translator->locale() === 'de' ? 'checked' : '' ?>><label for="language2"><?= $t('app.language.de') ?></label>
            </div>
          </form>
        </div>
      </div>

      <!-- TRACK RECORDER - native Capacitor shell only (see
           trackRecorderMenuRow above). Body content is entirely
           JS-rendered by track-recorder.js's renderTrackRecorderScreen()
           (idle/recording/paused states), same split-static-shell/dynamic-
           body approach as e.g. the Tours panel. -->
      <div class="nav-screen nav-screen-off-right" data-nav-screen="record">
        <div class="nav-screen-header">
          <button type="button" class="nav-back" onclick="navMenuBack();"><i class="material-icons-round">arrow_back</i></button>
          <h3><?= $t('app.nav.track_recorder') ?></h3>
        </div>
        <div class="nav-screen-body" id="trackRecorderScreenBody"></div>
      </div>

      <!-- PROFILE -->
      <div class="nav-screen nav-screen-off-right" data-nav-screen="profile">
        <div class="nav-screen-header">
          <button type="button" class="nav-back" onclick="navMenuBack();"><i class="material-icons-round">arrow_back</i></button>
          <h3><?= $t('app.nav.profile') ?></h3>
        </div>
        <div class="nav-screen-body">
          <div id="userWindow"></div>
        </div>
      </div>

    </div>

    <!-- COOKIE MENU ----------------------------------->
    <div id="cookiemenu" class="cookiemenu">
      <div class="cm_content cm_content-compact">
        <div class="cm-panel-header">
          <button type="button" class="nav-back" onclick="closeCookieMenu();"><i class="material-icons-round">arrow_back</i></button>
          <h2 class="cm-panel-title"><?= $t('app.nav.cookies') ?></h2>
        </div>
        <div class="cookieConsentBody">
          <p>
            <?= $t('app.cookies.body', ['app' => htmlspecialchars($appName)]) ?>
          </p>
          <div class="button" onclick="closeCookieMenu();"><i class="material-icons-round">check</i>&nbsp;<?= $t('app.cookies.agree') ?></div>
          <div class="button" onclick="revokeConsent();"><i class="material-icons-round">not_interested</i>&nbsp;<?= $t('app.cookies.delete') ?></div>
        </div>
        <div class="panel-logo"></div>
      </div>
    </div>

    <!-- LEGAL MENU (About / Imprint / Privacy Notice) ----------------------------------->
    <div id="legalmenu" class="cookiemenu">
      <div class="cm_content cm_content-compact">
        <div class="cm-panel-header">
          <button type="button" class="nav-back" onclick="closeLegalMenu();"><i class="material-icons-round">arrow_back</i></button>
          <h2 id="legalmenu-title" class="cm-panel-title"></h2>
        </div>
        <div id="legalmenu-content"></div>
        <div class="panel-logo"></div>
      </div>
    </div>

    <!-- User admin menu moved to /admin/users (templates/admin-users.php) -
         no longer part of the main SPA. -->

    <!-- TOUR ADMIN MENU (browse/search tours, view details, manage a
         tour's own metadata and route membership) ----------------------->
    <div id="touradminmenu" class="cookiemenu">
      <div class="cm_content cm_content-compact">
        <div class="cm-panel-header" id="touradminmenu-header">
          <button type="button" class="nav-back" id="touradminmenu-back" style="display:none;"><i class="material-icons-round">arrow_back</i></button>
          <h2 id="touradminmenu-title" class="cm-panel-title"><?= $t('app.nav.tours') ?></h2>
          <div id="touradminmenu-action"></div>
        </div>
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
        <span class="tour-mode-badge-sub"><?= $t('app.tour_mode.label') ?></span>
      </div>
      <div class="tour-mode-badge-close" onclick="exitTourMode();"><i class="material-icons-round">close</i></div>
    </div>

    <!-- TRACK RECORDING BADGE - native Capacitor shell only, shown while a
         GPS route recording is active/paused (track-recorder.js). Same
         default top offset as #tourModeBadge (only one of the two normally
         shows at once); if Tour Mode is ALSO active, track-recorder.js's
         positionRecordingBadge() moves this one below #tourModeBadge's live
         height instead, so they never overlap. Press-and-hold reopens the
         recording screen (no inline pause/stop here - those live in that
         screen, this is a status-and-shortcut badge, not a full control) -
         the hold itself is wired up in track-recorder.js's
         initTrackRecordingBadgePressHold(), not a plain onclick here, so a
         passing tap on the way to the map doesn't reopen it by accident. -->
    <div id="trackRecordingBadge" class="track-recording-badge" style="display:none;">
      <i class="material-icons-round track-recording-badge-icon">fiber_manual_record</i>
      <div class="tour-mode-badge-text">
        <div id="trackRecordingBadgeStats" class="track-recording-badge-stats">
          <span id="trackRecordingBadgeDuration"></span>
          <span id="trackRecordingBadgeDistance"></span>
        </div>
        <span id="trackRecordingBadgeStatus" class="tour-mode-badge-sub"></span>
      </div>
    </div>

    <!-- WEATHER TIMELINE PANEL - opened on demand via the map's right-click/
         long-press context menu ("Weather data for this location" - see
         weather.js/map-core.js's showMapContextMenu()), never shown
         automatically. A bottom sheet with a horizontally scrollable
         hourly strip (temperature/wind/waves) covering the next 7 days. -->
    <div id="weatherTimelinePanel" class="weather-timeline-panel" style="display:none;">
      <div class="weather-timeline-header">
        <span id="weatherTimelineTitle" class="weather-timeline-title"></span>
        <div class="weather-timeline-close" onclick="closeWeatherTimeline();" aria-label="<?= htmlspecialchars($t('weather.widget.close_aria_label'), ENT_QUOTES) ?>"><i class="material-icons-round">close</i></div>
      </div>
      <div id="weatherTimelineMarineNotice" class="weather-timeline-marine-notice" style="display:none;"><?= htmlspecialchars($t('weather.marine.unavailable'), ENT_QUOTES) ?></div>

      <!-- One icon + daily high per day covered by the forecast - built by
           weather.js's renderWeatherDayGlance(), not static markup. -->
      <div id="weatherTimelineDayGlance" class="weather-timeline-day-glance"></div>

      <!-- Week-long temperature curve + wind-strength bar behind the day
           table - renderWeatherWeekChart() fills the viewBox/content in. -->
      <div class="weather-timeline-week-chart">
        <svg id="weatherTimelineWeekChart" class="weather-timeline-week-chart-svg" viewBox="0 0 700 60" preserveAspectRatio="none"></svg>
      </div>

      <div class="weather-timeline-table-scroll" id="weatherTimelineTableScroll">
        <div id="weatherTimelineRowLabels" class="weather-timeline-row-labels"></div>
        <div id="weatherTimelineStrip" class="weather-timeline-strip"></div>
      </div>

      <a id="weatherTimelineAttribution" class="weather-timeline-attribution" style="display:none;" href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener"><?= htmlspecialchars($t('weather.timeline.osm_attribution'), ENT_QUOTES) ?></a>
    </div>

    <!-- Inline SVG pictograms for the weather timeline (condition icons,
         row-label icons) - not the vendored classic Material Icons Round
         font, which per this file's own icon-font caveat can't be assumed
         to have sun/cloud/rain/snow/etc glyphs. Referenced via <use> from
         public/js/weather.js. -->
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
      <symbol id="ic-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="4.6" fill="currentColor" stroke="none"/><path d="M12 2.5v2.4M12 19.1v2.4M4.2 4.2l1.7 1.7M18.1 18.1l1.7 1.7M2.5 12h2.4M19.1 12h2.4M4.2 19.8l1.7-1.7M18.1 5.9l1.7-1.7"/></symbol>
      <symbol id="ic-moon" viewBox="0 0 24 24" fill="currentColor"><path d="M14 3a9 9 0 108.9 10.4A7 7 0 0114 3z"/></symbol>
      <symbol id="ic-sunrise" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 10V4"/><path d="M9 7l3-3 3 3"/><path d="M5 17a7 7 0 0114 0"/><path d="M2.5 17h19"/></symbol>
      <symbol id="ic-sunset" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v6"/><path d="M9 7l3 3 3-3"/><path d="M5 17a7 7 0 0114 0"/><path d="M2.5 17h19"/></symbol>
      <symbol id="ic-cloud" viewBox="0 0 24 24" fill="currentColor"><path d="M7 18a5 5 0 01-.6-9.97A6 6 0 0118.3 9.1 4.5 4.5 0 0117.5 18H7z"/></symbol>
      <symbol id="ic-partly" viewBox="0 0 24 24"><circle cx="8.3" cy="8.3" r="3.6" fill="currentColor" opacity="0.85"/><path d="M9 19a4.6 4.6 0 01-.5-9.17A5.6 5.6 0 0119.2 10.3 4.1 4.1 0 0118.5 19H9z" fill="currentColor"/></symbol>
      <symbol id="ic-rain" viewBox="0 0 24 24"><path d="M6.6 14.3a4.4 4.4 0 01-.5-8.77A5.4 5.4 0 0116.9 6.3 3.9 3.9 0 0116.3 14H6.6z" fill="currentColor"/><path d="M8 16.5l-1.3 3M12.3 16.5L11 19.5M16.6 16.5l-1.3 3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></symbol>
      <symbol id="ic-drizzle" viewBox="0 0 24 24"><path d="M6.6 13.3a4.1 4.1 0 01-.5-8.17A5 5 0 0116.4 5.3 3.6 3.6 0 0115.8 13H6.6z" fill="currentColor" opacity="0.9"/><path d="M9 16.2l-.8 2M13 16.2l-.8 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" opacity="0.75"/></symbol>
      <symbol id="ic-snow" viewBox="0 0 24 24"><path d="M6.6 13.3a4.1 4.1 0 01-.5-8.17A5 5 0 0116.4 5.3 3.6 3.6 0 0115.8 13H6.6z" fill="currentColor" opacity="0.9"/><path d="M8.5 16.5v4M6.7 17.7l3.6 1.6M13.7 16.5v4M11.9 17.7l3.6 1.6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></symbol>
      <symbol id="ic-fog" viewBox="0 0 24 24"><path d="M7 14a4 4 0 01-.4-7.97A5 5 0 0116.8 7.1 3.6 3.6 0 0116.3 14H7z" fill="currentColor" opacity="0.85"/><path d="M5 17h14M6 20h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
      <symbol id="ic-storm" viewBox="0 0 24 24"><path d="M6.6 13a4 4 0 01-.5-7.97A5 5 0 0116.4 5 3.6 3.6 0 0115.9 13H6.6z" fill="currentColor"/><path d="M13 13l-3.4 5h2.6l-1.2 4L15 16h-2.6z" fill="currentColor"/></symbol>
      <symbol id="ic-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2" stroke-linecap="round"/></symbol>
      <symbol id="ic-thermo" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 14.5V5a2 2 0 10-4 0v9.5a4 4 0 104 0z"/></symbol>
      <symbol id="ic-drop" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3.2S5.5 10.8 5.5 15a6.5 6.5 0 0013 0c0-4.2-6.5-11.8-6.5-11.8z"/></symbol>
      <symbol id="ic-flag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 21V4M6 4h12l-3.2 4L18 12H6"/></symbol>
      <symbol id="ic-gust" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 8h11a2.5 2.5 0 100-5M3 13h15a2.5 2.5 0 110 5M3 18h9"/></symbol>
      <symbol id="ic-arrow" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l5 9.5-5-2.6-5 2.6z"/><path d="M12 22V9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" fill="none"/></symbol>
      <symbol id="ic-wave" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 15c1.6 0 1.6-2 3.2-2s1.6 2 3.2 2 1.6-2 3.2-2 1.6 2 3.2 2 1.6-2 3.2-2 1.6 2 3.2 2M2 19.5c1.6 0 1.6-2 3.2-2s1.6 2 3.2 2 1.6-2 3.2-2 1.6 2 3.2 2 1.6-2 3.2-2 1.6 2 3.2 2"/></symbol>
      <symbol id="ic-tide" viewBox="0 0 24 24"><path d="M15 3.3a5 5 0 106 6.1A6 6 0 0115 3.3z" fill="currentColor"/><path d="M2 18.5c1.6 0 1.6-2.2 3.2-2.2s1.6 2.2 3.2 2.2 1.6-2.2 3.2-2.2 1.6 2.2 3.2 2.2 1.6-2.2 3.2-2.2 1.6 2.2 3.2 2.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></symbol>
    </svg>

    <!-- EDIT ADDITIONAL TOOLBAR -------------------------------------->
    <div id="secondToolbar">
    </div>

    <!-- EDIT TOOLBAR -------------------------------------->
    <div id="editToolbar">
      <div id="routeButton" class="toolbar-icon-btn" title="<?= htmlspecialchars($t('app.toolbar.create_route'), ENT_QUOTES) ?>" onclick="editRouteBtnClick('routeButton');"><svg viewBox="0 -960 960 960"><path d="M247-167q-47-47-47-113v-327q-35-13-57.5-43.5T120-720q0-50 35-85t85-35q50 0 85 35t35 85q0 39-22.5 69.5T280-607v327q0 33 23.5 56.5T360-200q33 0 56.5-23.5T440-280v-400q0-66 47-113t113-47q66 0 113 47t47 113v327q35 13 57.5 43.5T840-240q0 50-35 85t-85 35q-50 0-85-35t-35-85q0-39 22.5-70t57.5-43v-327q0-33-23.5-56.5T600-760q-33 0-56.5 23.5T520-680v400q0 66-47 113t-113 47q-66 0-113-47Zm-7-513q17 0 28.5-11.5T280-720q0-17-11.5-28.5T240-760q-17 0-28.5 11.5T200-720q0 17 11.5 28.5T240-680Zm480 480q17 0 28.5-11.5T760-240q0-17-11.5-28.5T720-280q-17 0-28.5 11.5T680-240q0 17 11.5 28.5T720-200ZM240-720Zm480 480Z"/></svg></div>
      <div id="poiButton" class="toolbar-icon-btn disabled" title="<?= htmlspecialchars($t('app.toolbar.create_poi'), ENT_QUOTES) ?>" onclick="editPoiBtnClick('poiButton');"><svg viewBox="0 -960 960 960"><path d="M440-520v80q0 17 11.5 28.5T480-400q17 0 28.5-11.5T520-440v-80h80q17 0 28.5-11.5T640-560q0-17-11.5-28.5T600-600h-80v-80q0-17-11.5-28.5T480-720q-17 0-28.5 11.5T440-680v80h-80q-17 0-28.5 11.5T320-560q0 17 11.5 28.5T360-520h80Zm40 334q122-112 181-203.5T720-552q0-109-69.5-178.5T480-800q-101 0-170.5 69.5T240-552q0 71 59 162.5T480-186Zm-28 74q-14-5-25-15-65-60-115-117t-83.5-110.5q-33.5-53.5-51-103T160-552q0-150 96.5-239T480-880q127 0 223.5 89T800-552q0 45-17.5 94.5t-51 103Q698-301 648-244T533-127q-11 10-25 15t-28 5q-14 0-28-5Zm28-448Z"/></svg></div>
      <div id="areaButton" class="toolbar-icon-btn" title="<?= htmlspecialchars($t('app.toolbar.create_area'), ENT_QUOTES) ?>" onclick="editAreaBtnClick('areaButton');"><svg viewBox="0 -960 960 960"><path d="M298-200h364l123-369-305-213-305 213 123 369Zm0 80q-26 0-47-15t-29-40L99-543q-8-26 0-51t30-40l305-214q21-14 46-14t46 14l305 214q22 15 30 40t0 51L738-175q-8 25-29 40t-47 15H298Zm182-371Z"/></svg></div>
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
          user.route_view_recording = !!answer.data.route_view_recording;
          user.pending_email = answer.data.pending_email;
          user.pending_email_expires_at = answer.data.pending_email_expires_at;

          sessionStorage.setItem('user', JSON.stringify(user));
          updateProfileRowLabel();
          updateAdminMenuVisibility();
          updateGoogleSearchAllowed();

          // initMap() (map-core.js) is racing this same fetch - if it
          // already ran (loadGoogleMaps() above, and this took the slower
          // round trip), it captured user.id as still null and loaded only
          // the public POIs/routes/areas/tours, leaving the create-POI
          // button disabled. Load the user's own data and flip it on now
          // that we actually know who's logged in, instead of leaving the
          // UI stuck in its anonymous state for the rest of the session.
          if (typeof mapInitialized !== 'undefined' && mapInitialized) {
            getPoisByUserId(user.id);
            getRoutesByUserId(user.id);
            getAreasByUserId(user.id);
            getToursByUserId(user.id);
            enablePoiButton();
          }
        }).catch((err) => {
          if (err.status !== 401) {
            // A network-level failure (offline, flaky signal, server
            // temporarily unreachable - err.status is undefined there, see
            // api-client.js's networkError()) or a non-auth server error
            // doesn't mean the token itself is bad. Discarding it here would
            // force a fresh login the next time the app loads even though
            // the still-valid token was sitting right there - leave it in
            // place and just keep the UI in its logged-out state for this
            // one page load.
            log('Session check failed (not a 401), keeping stored token', LOG_WARN, err);
            return;
          }
          // A genuine 401 means the stored token really is invalid/expired.
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
