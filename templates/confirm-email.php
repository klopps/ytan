<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | <?= $t('page_title.confirm_email') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <script>window.YTAN_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE) ?>;</script>
    <script src="<?= $baseUrl ?>/js/i18n.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/i18n.js') ?>"></script>
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
    <style>
        body { background-color: #f3f4f8; }
        #confirmEmailBox {
            width: 95%;
            max-width: 400px;
            background-color: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.1);
            margin: 80px auto;
            padding: 28px 32px;
            box-sizing: border-box;
        }
        #confirmEmailBox h1 { font-size: 20px; color: #2c2c3d; text-align: center; }
        #confirmEmailMessage { color: #b3261e; margin: 10px 0; font-size: 13px; }
    </style>
</head>
<body>
    <div id="confirmEmailBox">
        <h1><?= htmlspecialchars($appName) ?></h1>
        <p><?= $t('confirmemail.subtitle') ?></p>
        <div id="confirmEmailMessage"></div>
        <p>
            <button id="confirmEmailBtn" class="startbtn" onclick="submitConfirmEmail()"><?= $t('confirmemail.button') ?></button>
        </p>
    </div>

    <script>
        var token = <?= json_encode($_GET['token'] ?? '') ?>;
        var apiBase = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";

        function submitConfirmEmail() {
            var message = document.getElementById('confirmEmailMessage');
            message.textContent = '';

            if (!token) {
                message.textContent = t('common.missing_token');
                return;
            }

            document.getElementById('confirmEmailBtn').disabled = true;

            fetch(apiBase + '/auth/confirm-email-change', {
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
                    throw new Error((result.payload.error && result.payload.error.message) || t('confirmemail.generic_failure'));
                }
                localStorage.setItem('ytan_token', result.payload.token);
                window.location.href = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/";
            })
            .catch(function (err) {
                document.getElementById('confirmEmailBtn').disabled = false;
                message.textContent = err.message;
            });
        }
    </script>
</body>
</html>
