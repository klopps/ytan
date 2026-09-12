<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | Translations</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
    <style>
        /* This page does not use the app's own t()/$t() - it's a dev-only
           tool for editing the translation files themselves, so its own
           chrome stays in plain English rather than looping through the
           system it's editing. */
        html.standalone-page, html.standalone-page body {
            height: 100%;
            padding: 0;
            overflow: hidden;
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

        #toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            border-bottom: 1px solid var(--color-border-subtle);
            background: var(--color-bg-panel);
            flex-shrink: 0;
        }
        #toolbar h1 { font-size: 15px; margin: 0; font-family: var(--font-display); white-space: nowrap; }
        #searchInput {
            flex: 1;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            padding: 7px 10px;
            background: var(--color-bg-subtle);
            color: var(--color-text);
        }
        #dirtyCount { font-size: 12px; color: var(--color-warning); min-width: 90px; text-align: right; }
        #saveAllBtn:disabled { opacity: 0.5; cursor: default; }

        #panes {
            flex: 1;
            display: flex;
            min-height: 0;
        }
        #previewPane {
            width: 40%;
            min-width: 280px;
            border-right: 1px solid var(--color-border-subtle);
            display: flex;
        }
        #previewPane iframe { border: 0; width: 100%; height: 100%; }
        #editorPane {
            flex: 1;
            overflow-y: auto;
            padding: 8px 16px 40px;
        }
        @media (max-width: 900px) {
            #panes { flex-direction: column; }
            #previewPane { width: 100%; height: 40vh; border-right: none; border-bottom: 1px solid var(--color-border-subtle); }
        }

        .namespaceGroup { margin: 14px 0; }
        .namespaceGroup summary {
            cursor: pointer;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 13px;
            padding: 8px 4px;
            color: var(--color-accent);
        }
        .keyRow {
            border: 1px solid var(--color-border-subtle);
            border-radius: 8px;
            padding: 10px 12px;
            margin: 6px 0;
            background: var(--color-bg-panel);
        }
        .keyRow.dirty { border-color: var(--color-warning); }
        .keyRow .keyName {
            font-family: monospace;
            font-size: 12px;
            color: var(--color-text-secondary);
            margin-bottom: 6px;
            word-break: break-all;
        }
        .keyRow .fields { display: flex; gap: 10px; flex-wrap: wrap; }
        .keyRow .fieldCol { flex: 1; min-width: 220px; }
        .keyRow .fieldCol label { display: block; font-size: 11px; color: var(--color-text-faint); margin-bottom: 2px; }
        .keyRow textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            padding: 6px 7px;
            background: var(--color-bg-subtle);
            color: var(--color-text);
            font-family: var(--font-body);
            font-size: 13px;
            resize: vertical;
            min-height: 34px;
        }
        .keyRow .usage {
            font-size: 11px;
            color: var(--color-text-faint);
            margin-top: 6px;
        }
        .keyRow .usage.unused { color: var(--color-warning); }
        .keyRow .placeholderWarning {
            font-size: 11px;
            color: var(--color-warning);
            margin-top: 4px;
        }
        #emptyState { padding: 40px; text-align: center; color: var(--color-text-faint); }
    </style>
</head>
<body>
    <div id="loginBox" hidden>
        <h1><?= htmlspecialchars($appName) ?> Translations</h1>
        <div class="field">
            <label for="loginUsername">Username or email</label>
            <input id="loginUsername" type="text" autocomplete="username">
        </div>
        <div class="field">
            <label for="loginPassword">Password</label>
            <input id="loginPassword" type="password" autocomplete="current-password">
        </div>
        <button type="button" class="startbtn" onclick="submitLogin();">Log in</button>
        <div id="loginMessage"></div>
    </div>

    <div id="toolbar" hidden>
        <h1>Translations</h1>
        <input type="text" id="searchInput" placeholder="Filter by key or text…" oninput="applyFilter();">
        <span id="dirtyCount"></span>
        <button type="button" class="button" id="saveAllBtn" onclick="saveAll();" disabled>Save all</button>
    </div>
    <div id="panes" hidden>
        <div id="previewPane">
            <iframe id="livePreview" src="<?= $baseUrl ?>/" title="Live app preview"></iframe>
        </div>
        <div id="editorPane">
            <div id="emptyState">Loading…</div>
        </div>
    </div>

    <script>window.YTAN_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script src="<?= $baseUrl ?>/js/api-client.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/api-client.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/translate.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/translate.js') ?>"></script>
</body>
</html>
