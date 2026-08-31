<!DOCTYPE html>
<html>
  <head>
    <title><?= htmlspecialchars($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8"/>

    <script>window.YTAN_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>

    <script src="./lib/jquery/jquery-3.7.1.min.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/jquery/jquery-3.7.1.min.js') ?>"></script>
    <script src="./lib/selectize/selectize.min.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/selectize/selectize.min.js') ?>"></script>
    <script src="./lib/measuretool-googlemap-v3/gmaps-measuretool.umd.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/measuretool-googlemap-v3/gmaps-measuretool.umd.js') ?>"></script>
    <script src="./lib/marked/marked.min.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/marked/marked.min.js') ?>"></script>
    <script src="./lib/markerWithLabel/markerwithlabel.min.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/markerWithLabel/markerwithlabel.min.js') ?>"></script>
    <script src="./lib/light-characteristics/lightcharacteristic.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/light-characteristics/lightcharacteristic.js') ?>"></script>

    <script src="./js/config.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/config.js') ?>"></script>
    <script src="./js/helper.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/helper.js') ?>"></script>
    <script src="./js/api-client.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/api-client.js') ?>"></script>
    <script src="./js/settings.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/settings.js') ?>"></script>
    <script src="./js/ui.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/ui.js') ?>"></script>
    <script src="./js/map-core.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/map-core.js') ?>"></script>
    <script src="./js/poi.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/poi.js') ?>"></script>
    <script src="./js/route.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/route.js') ?>"></script>
    <script src="./js/area.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/area.js') ?>"></script>
    <script src="./js/tour.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/tour.js') ?>"></script>
    <script src="./js/user.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/user.js') ?>"></script>
    <script src="./js/admin-user.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-user.js') ?>"></script>

    <link rel="stylesheet" type="text/css" href="./css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="stylesheet" type="text/css" href="./css/fonts.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/fonts.css') ?>" />
    <link rel="stylesheet" type="text/css" href="./lib/selectize/selectize.default.min.css?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/selectize/selectize.default.min.css') ?>" />

    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
    <link rel="icon" type="image/png" href="<?= $baseUrl ?>/favicon-16x16.png" sizes="16x16">
    <link rel="icon" type="image/png" href="<?= $baseUrl ?>/favicon-32x32.png" sizes="32x32">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $baseUrl ?>/apple-touch-icon.png">
  </head>
  <body>
    <div id="iconlogo"></div>
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
      <img class="logo_large" src="images/ytan.svg">
    </div>

    <div id="map"></div>
    <div id="sidemenu-toggle" onclick="toggleMenu()">
      <i class="material-icons-round" id="sidemenu-opener">chevron_right</i>
    </div>

    <div id="sidemenu" class="sidemenu">
      <div>&nbsp;</div>
      <div class="panelheading">Map</div>
      <form name="maptype">
        <div class="radio-group">
          <input type="radio" id="maptype1" name="maptypeselector" onclick="editMapType(this)" value="hybrid" checked><label for="maptype1">HYBRID</label><input type="radio" id="maptype2" name="maptypeselector" onclick="editMapType(this)" value="terrain" ><label for="maptype2">TERRAIN</label><input type="radio" id="maptype3" name="maptypeselector" onclick="editMapType(this)" value="satellite" ><label for="maptype3">SAT</label>
        </div>
      </form>
      <div class="panelheading">Units</div>
      <form name="units">
        <div class="radio-group">
          <input type="radio" id="unit1" name="unitselector" onclick="editUnit(this)" value="metric" checked><label for="unit1">METRIC</label><input type="radio" id="unit2" name="unitselector" onclick="editUnit(this)" value="nautical" ><label for="unit2">NAUTICAL</label>
        </div>
      </form>

      <div class="panelheading" onclick="togglePanel('detailpanel','detailindicator')"><span class="middle">Show POIs ... <span class="roundedBorder"><i id="detailindicator" class="material-icons-round">expand_more</i></span></div>
      <div id="detailpanel">
        <input type="checkbox" id="detail0" name="detail0" onclick="toggleMarkerByType(this, 0);" checked><label for="detail0"><span></span>POI</label><br/>
        <input type="checkbox" id="detail1" name="detail1" onclick="toggleMarkerByType(this, 1);" checked><label for="detail1"><span></span>Camps</label><br/>
        <input type="checkbox" id="detail10" name="detail10" onclick="toggleMarkerByType(this, 10);" checked><label for="detail10"><span></span>Shelter</label><br/>
        <input type="checkbox" id="detail11" name="detail11" onclick="toggleMarkerByType(this, 11);" checked><label for="detail11"><span></span>Campsite (commercial)</label><br/>
        <input type="checkbox" id="detail2" name="detail2" onclick="toggleMarkerByType(this, 2);" checked><label for="detail2"><span></span>Landing Sites</label><br/>
        <input type="checkbox" id="detail3" name="detail3" onclick="toggleMarkerByType(this, 3);" checked><label for="detail3"><span></span>Drinking Water</label><br/>
        <div><input type="checkbox" id="detail4" name="detail4" onclick="toggleMarkerByType(this, 4);" checked><label for="detail4"><span></span>Toilets</label></div>
        <div><input type="checkbox" id="detail5" name="detail5" onclick="toggleMarkerByType(this, 5);" checked><label for="detail5"><span></span>Historic Sites</label></div>
        <div><input type="checkbox" id="detail6" name="detail6" onclick="toggleMarkerByType(this, 6);" checked><label for="detail6"><span></span>Danger Zones</label></div>
        <div><input type="checkbox" id="detail7" name="detail7" onclick="toggleMarkerByType(this, 7);" checked><label for="detail7"><span></span>Natural Sights</label></div>
        <div><input type="checkbox" id="detail8" name="detail8" onclick="toggleMarkerByType(this, 8);" checked><label for="detail8"><span></span>Shopping</label></div>
        <div><input type="checkbox" id="detail9" name="detail9" onclick="toggleMarkerByType(this, 9);" checked><label for="detail9"><span></span>Portages</label></div>
        <div><input type="checkbox" id="detail12" name="detail12" onclick="toggleMarkerByType(this, 12);" checked><label for="detail12"><span></span>Medical care</label></div>
        <div><input type="checkbox" id="detail13" name="detail13" onclick="toggleMarkerByType(this, 13);" checked><label for="detail13"><span></span>Clubs / Institutions</label></div>
        <div><input type="checkbox" id="detail14" name="detail14" onclick="toggleMarkerByType(this, 14);" checked><label for="detail14"><span></span>Lights (Lighthouses, Sea Marks)</label></div>
        <div><input type="checkbox" id="detail15" name="detail15" onclick="toggleMarkerByType(this, 15);" checked><label for="detail15"><span></span>Parking</label></div>
        <div><input type="checkbox" id="detail16" name="detail16" onclick="toggleMarkerByType(this, 16);" checked><label for="detail16"><span></span>Fishing</label></div>
        <div><input type="checkbox" id="detailroutes" name="detailroutes" onchange="toggleRoutes(this);" checked><label for="detailroutes"><span></span>Routes</label></div>
        <div><input type="checkbox" id="detailareas" name="detailareas" onchange="toggleAreas(this);" checked><label for="detailareas"><span></span>Areas</label></div>
        <div><input type="checkbox" id="detailwsi" name="detailwsi" onchange="toggleWsiMarkers(this);"><label for="detailwsi"><span></span>Windshelter Indicators</label></div>
      </div>

      <div id="tourselector">
        Tours
        <select id="select-tour" placeholder="Select a tour...">
          <option value="">No tour defined</option>
        </select>
        <div class="button" onclick="showSelectedTour();" disabled><i class="material-icons-round">fit_screen</i>&nbsp;Show Tour</div>
      </div>

      <div class="button" onclick="fitToPoiBounds();"><i class="material-icons-round">fit_screen</i>&nbsp;Fit POIs</div>
      <div class="button" onclick="shareMap();"><i class="material-icons-round">share</i>&nbsp;Share</div>
      <div class="button" id="userAdminMenuBtn" style="display:none;" onclick="openUserAdminMenu();"><i class="material-icons-round">admin_panel_settings</i>&nbsp;User</div>

      <div id="disclaimer">
        A lot of the information shown here is based on the documents
        "<a href="https://tjornkajak.se/paddla-kajak-och-talta-i-bohuslan-haftena">Sea kayaking and camping in Bohuslän Part A to C</a>"
        by <a href="https://tjornkajak.se/jens-marklund/">Jens Marklund</a>.
        These highly recommended documents can be downloaded from the website of <a href="https://tjornkajak.se/" target="_blank">Tjörns Kajakklubb</a>.
        Many thanks to Jens and his friends for this great work.
      </div>

      <div class="logo">
        <img style="width: 80%" src="images/ytan.svg">
      </div>

      <div id="legal">
        <span onclick="openLegalMenu('<?= $baseUrl ?>/legal/impressum');">Imprint</span> | <span onclick="openLegalMenu('<?= $baseUrl ?>/legal/datenschutz');">Privacy Notice</span> | <span onclick="openCookieMenu();">Cookies</span>
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
        <img class="panel-logo" src="images/ytan.svg">
      </div>
    </div>

    <!-- LEGAL MENU (Imprint / Privacy Notice) ----------------------------------->
    <div id="legalmenu" class="cookiemenu">
      <div id="legalmenu-close-btn" class="panel-close-btn" onclick="closeLegalMenu();"><i class="material-icons-round">close</i></div>
      <div class="cm_content">
        <div id="legalmenu-content"></div>
        <img class="panel-logo" src="images/ytan.svg">
      </div>
    </div>

    <!-- USER ADMIN MENU (admin only) ----------------------------------->
    <div id="useradminmenu" class="cookiemenu">
      <div id="useradminmenu-close-btn" class="panel-close-btn" onclick="closeUserAdminMenu();"><i class="material-icons-round">close</i></div>
      <div class="cm_content">
        <h2>User management</h2>
        <div id="useradminmenu-form"></div>
        <div id="useradminmenu-list"></div>
        <img class="panel-logo" src="images/ytan.svg">
      </div>
    </div>

    <!-- EDIT ADDITIONAL TOOLBAR -------------------------------------->
    <div id="secondToolbar">
    </div>

    <!-- EDIT TOOLBAR -------------------------------------->
    <div id="editToolbar">
      <div id="routeButton" class="pesrBtn pesrBtnRoute rightBorder" title="Create Route" onclick="editRouteBtnClick('routeButton');"></div>
      <div id="poiButton" class="pesrBtn pesrBtnPoi rightBorder disabled" title="Create POI" onclick="editPoiBtnClick('poiButton');"></div>
      <div id="areaButton" class="pesrBtn pesrBtnArea rightBorder" title="Create Area" onclick="editAreaBtnClick('areaButton');"></div>
      <div id="userButton" class="pesrBtn pesrBtnUser" title="Your settings" onclick="showUserWindow();"></div>
    </div>

    <!-- USER LOGIN/LOGOUT WINDOW -------------------------->
    <div id="userWindow">
    </div>

    <script>
      let logLevel = <?= (int) $logLevel ?>;

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

          sessionStorage.setItem('user', JSON.stringify(user));
          document.getElementById('userButton').classList.add('loggedin');
          updateAdminMenuVisibility();
        }).catch(() => {
          // stored token is invalid/expired - fall back to the logged-out state
          Ytan.setToken(null);
          sessionStorage.removeItem('user');
        });
      }
    </script>

    <div id="shield"></div>
  </body>
</html>
