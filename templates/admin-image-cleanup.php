<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | Image Cleanup</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
    <style>
        /* Same reasoning as admin.php/translate.php: a dev/admin-only tool,
           plain English chrome rather than looping through the app's t(). */
        html.standalone-page, html.standalone-page body {
            height: 100%;
            padding: 0;
        }
        body {
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

        #cleanupPage {
            max-width: 720px;
            width: 95%;
            margin: 40px auto 60px;
        }
        #cleanupPage h1 { font-size: 18px; font-family: var(--font-display); margin: 0 0 4px; }
        #cleanupPage .backLink { font-size: 13px; }
        #cleanupPage .backLink a { color: var(--color-accent); text-decoration: none; }
        #cleanupContent { margin-top: 20px; }
        #emptyState { color: var(--color-text-faint); font-size: 13px; }
        .errorText { color: var(--color-danger); }

        .cleanupSection {
            background: var(--color-bg-panel);
            border: 1px solid var(--color-border-subtle);
            border-radius: 10px;
            padding: 16px 18px;
            margin-bottom: 20px;
        }
        .cleanupSection h2 { font-size: 14px; margin: 0 0 4px; }
        .cleanupHint { font-size: 12px; color: var(--color-text-secondary); margin: 0 0 12px; }
        .cleanupActions { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; font-size: 13px; }
        .cleanupList { list-style: none; margin: 0; padding: 0; max-height: 320px; overflow-y: auto; }
        .cleanupList li { border-top: 1px solid var(--color-border-subtle); }
        .cleanupList li:first-child { border-top: none; }
        .cleanupList label { display: flex; align-items: center; gap: 10px; padding: 8px 4px; font-size: 12px; cursor: pointer; }
        .cleanupType { text-transform: capitalize; font-weight: 700; min-width: 46px; }
        .cleanupId { color: var(--color-text-secondary); min-width: 40px; }
        .cleanupFilename { font-family: monospace; color: var(--color-text-secondary); flex: 1; word-break: break-all; }
        .cleanupSize { color: var(--color-text-faint); white-space: nowrap; }
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

    <div id="cleanupPage" hidden>
        <p class="backLink"><a href="<?= $baseUrl ?>/admin">&larr; Admin</a></p>
        <h1>Image Cleanup</h1>
        <p class="cleanupHint">Compares uploaded photo files against the tour/POI/route/area database tables and lists anything out of sync.</p>
        <div id="cleanupContent"><p>Scanning…</p></div>
    </div>

    <script>window.YTAN_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script src="<?= $baseUrl ?>/js/api-client.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/api-client.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/admin-auth.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-auth.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/admin-image-cleanup.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-image-cleanup.js') ?>"></script>
</body>
</html>
