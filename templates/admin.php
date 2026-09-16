<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
    <style>
        /* Same reasoning as translate.php: a dev/admin-only tool, plain
           English chrome rather than looping through the app's own t(). */
        html.standalone-page, html.standalone-page body {
            height: 100%;
            padding: 0;
        }
        body {
            display: flex;
            flex-direction: column;
            font-family: var(--font-body);
            background: var(--color-bg-page, #f3f4f8);
            color: var(--color-text);
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

        #adminMenu {
            max-width: 480px;
            width: 95%;
            margin: 60px auto;
        }
        .adminMenuHeader { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
        #adminMenu h1 { font-size: 18px; font-family: var(--font-display); margin: 0; }
        .adminBackLink {
            font-size: 12px;
            color: var(--color-text-secondary);
            text-decoration: none;
            border: 1px solid var(--color-border-subtle);
            border-radius: 8px;
            padding: 6px 10px;
            white-space: nowrap;
        }
        .adminBackLink:hover { border-color: var(--color-accent); color: var(--color-text); }
        .adminMenuList { display: flex; flex-direction: column; gap: 10px; }
        .adminMenuItem {
            display: block;
            background: var(--color-bg-panel);
            border: 1px solid var(--color-border-subtle);
            border-radius: 10px;
            padding: 14px 16px;
            color: var(--color-text);
            text-decoration: none;
        }
        .adminMenuItem:hover { border-color: var(--color-accent); }
        .adminMenuItem .adminMenuItemTitle { font-weight: 700; font-size: 14px; }
        .adminMenuItem .adminMenuItemDescription { font-size: 12px; color: var(--color-text-secondary); margin-top: 2px; }
        #adminMenuEmpty { color: var(--color-text-faint); font-size: 13px; }
        .adminSectionTitle { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-text-secondary); margin: 24px 0 10px; }
        .adminSettingsCard {
            background: var(--color-bg-panel);
            border: 1px solid var(--color-border-subtle);
            border-radius: 10px;
        }
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

    <div id="adminMenu" hidden>
        <div class="adminMenuHeader">
            <h1>Admin</h1>
            <a class="adminBackLink" href="<?= $baseUrl ?>/">&larr; Back to YTAN</a>
        </div>
        <div class="adminMenuList">
            <a class="adminMenuItem" href="<?= $baseUrl ?>/admin/users">
                <div class="adminMenuItemTitle">Users</div>
                <div class="adminMenuItemDescription">Manage accounts &amp; permissions</div>
            </a>
            <a class="adminMenuItem" href="<?= $baseUrl ?>/admin/image-cleanup">
                <div class="adminMenuItemTitle">Image cleanup</div>
                <div class="adminMenuItemDescription">Find and remove orphaned photo files/DB rows</div>
            </a>
            <?php if ($translateToolEnabled): ?>
            <a class="adminMenuItem" href="<?= $baseUrl ?>/admin/translate">
                <div class="adminMenuItemTitle">Translations</div>
                <div class="adminMenuItemDescription">Edit the app's EN/DE translation strings</div>
            </a>
            <?php endif; ?>
        </div>

        <p class="adminSectionTitle">Settings</p>
        <div class="adminSettingsCard">
            <label class="nav-toggle-row">
                <span class="nav-toggle-text">Google search requires login</span>
                <input type="checkbox" id="settingGoogleSearchRequiresLogin" class="nav-switch-input" <?= $googleSearchRequiresLogin ? 'checked' : '' ?>>
                <span class="nav-switch-track"><span class="nav-switch-thumb"></span></span>
            </label>
        </div>
    </div>

    <script>window.YTAN_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script src="<?= $baseUrl ?>/js/api-client.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/api-client.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/admin-auth.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-auth.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/admin.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin.js') ?>"></script>
</body>
</html>
