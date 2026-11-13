<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | <?= $t('page_title.confirm_account_deletion') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta charset="utf-8" />
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?>>window.YTAN_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl ?>/js/csp-events.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/csp-events.js') ?>"></script>
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl ?>/js/i18n.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/i18n.js') ?>"></script>
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
    <style>
        body { background-color: var(--color-bg-page); }
        #accountDeletionBox {
            width: 95%;
            max-width: 420px;
            background-color: var(--color-bg-panel);
            color: var(--color-text);
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.1);
            margin: 80px auto;
            padding: 28px 32px;
            box-sizing: border-box;
        }
        #accountDeletionBox h1 { font-size: 20px; text-align: center; }
        #accountDeletionMessage { color: var(--color-danger); margin: 10px 0; font-size: 13px; }
        #accountDeletionBtn { background-color: var(--color-danger); }
    </style>
</head>
<body>
    <div id="accountDeletionBox">
        <h1><?= htmlspecialchars($appName) ?></h1>
        <div id="accountDeletionContent">
            <p><?= $t('account_deletion.page_intro') ?></p>
            <p><strong><?= $t('account_deletion.irreversible') ?></strong></p>
            <div id="accountDeletionMessage"></div>
            <p>
                <button id="accountDeletionBtn" class="startbtn" data-on-click="submitAccountDeletion()"><?= $t('account_deletion.page_button') ?></button>
            </p>
        </div>
    </div>

    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?>>
        var token = <?= json_encode($_GET['token'] ?? '') ?>;
        var apiBase = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";
        var homeUrl = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/";

        function submitAccountDeletion() {
            var message = document.getElementById('accountDeletionMessage');
            message.textContent = '';

            if (!token) {
                message.textContent = t('common.missing_token');
                return;
            }

            document.getElementById('accountDeletionBtn').disabled = true;

            fetch(apiBase + '/auth/confirm-account-deletion', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: token })
            })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            })
            .then(function (result) {
                if (!result.ok) {
                    throw (result.payload.error || { message: t('account_deletion.generic_failure') });
                }
                try {
                    localStorage.removeItem('ytan_token');
                    sessionStorage.removeItem('user');
                } catch (e) {
                    // storage blocked - the server has deleted the account anyway
                }
                var box = document.getElementById('accountDeletionContent');
                box.innerHTML = '';
                var done = document.createElement('p');
                done.textContent = t('account_deletion.done');
                var link = document.createElement('a');
                link.href = homeUrl;
                link.textContent = t('account_deletion.back_home');
                box.appendChild(done);
                box.appendChild(link);
            })
            .catch(function (err) {
                document.getElementById('accountDeletionBtn').disabled = false;
                message.textContent = translateApiError(err);
            });
        }
    </script>
</body>
</html>
