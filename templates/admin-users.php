<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($appName) ?> | Users</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />

    <script>window.YTAN_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script>window.YTAN_LOCALE = "<?= htmlspecialchars($translator->locale(), ENT_QUOTES) ?>";</script>
    <script>window.YTAN_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
    <script>let logLevel = <?= (int) $logLevel ?>;</script>

    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/fonts.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/fonts.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">

    <style>
        /*
         * Unlike /admin, /admin/translate and /admin/image-cleanup, this
         * page deliberately does NOT use the "standalone-page" plain-
         * English-chrome pattern - #useradminmenu below is the exact same
         * markup/CSS the main app uses for this panel (style.css's
         * .cookiemenu rules, written against the main app's own <html>/
         * <body> baseline), copied verbatim rather than re-styled, so it
         * needs that same baseline, not the standalone-page reset.
         */
        body {
            margin: 0;
            padding: 0;
            background: var(--color-bg-page, #f3f4f8);
            color: var(--color-text);
            font-family: var(--font-body);
        }

        #loginBox {
            width: 95%;
            max-width: 360px;
            background: var(--color-bg-panel);
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.1);
            margin: 80px auto;
            padding: 28px 32px;
            box-sizing: border-box;
        }
        #loginBox h1 { font-size: 18px; text-align: center; margin-top: 0; }
        #loginBox .field { margin-bottom: 14px; }
        #loginBox label { display: block; font-size: 12px; color: var(--color-text-secondary); margin-bottom: 4px; }
        #loginBox input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            padding: 7px 8px;
            background: var(--color-bg-panel);
            color: var(--color-text);
        }
        #loginBox button { width: 100%; margin-top: 6px; }
        #loginMessage { color: var(--color-danger); font-size: 13px; min-height: 16px; margin-top: 8px; }
    </style>
</head>
<body>
    <div id="loginBox" hidden>
        <h1><?= htmlspecialchars($appName) ?> Admin</h1>
        <div class="field">
            <label for="loginUsername">Username or email</label>
            <input id="loginUsername" type="text" autocomplete="username">
        </div>
        <div class="field">
            <label for="loginPassword">Password</label>
            <input id="loginPassword" type="password" autocomplete="current-password">
        </div>
        <button type="button" class="startbtn" onclick="submitAdminLogin();">Log in</button>
        <div id="loginMessage"></div>
    </div>

    <!-- Inert placeholders - admin-user.js/ui.js touch these unconditionally
         (panelOpened()/panelClosed(), pushMenuLeft()/unpushMenuLeft()) even
         though this page has no real toolbar/search bar/drawer. Kept
         completely unchanged rather than editing that shared code. -->
    <div id="adminUsersReady" hidden></div>
    <div id="editToolbar" hidden></div>
    <div id="mapSearchWrapper" hidden></div>
    <div id="sidemenu"></div>

    <!-- USER ADMIN MENU - identical markup to templates/app.php's, moved
         here verbatim (see admin-user.js, unchanged). -->
    <div id="useradminmenu" class="cookiemenu">
      <div class="cm_content cm_content-compact">
        <div class="cm-panel-header" id="useradminmenu-header">
          <button type="button" class="nav-back" id="useradminmenu-back" style="display:none;"><i class="material-icons-round">arrow_back</i></button>
          <h2 id="useradminmenu-title" class="cm-panel-title"><?= $t('app.settings.users') ?></h2>
          <div id="useradminmenu-action"></div>
        </div>
        <div id="useradminmenu-form"></div>
        <div id="useradminmenu-list"></div>
        <div class="panel-logo"></div>
      </div>
    </div>

    <div id="toastContainer" class="toast-container"></div>

    <script src="<?= $baseUrl ?>/js/config.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/config.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/i18n.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/i18n.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/helper.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/helper.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/api-client.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/api-client.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/settings.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/settings.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/ui.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/ui.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/toast.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/toast.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/confirm-dialog.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/confirm-dialog.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/admin-auth.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-auth.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/admin-user.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-user.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/admin-users.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-users.js') ?>"></script>
</body>
</html>
