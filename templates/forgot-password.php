<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | <?= $t('page_title.forgot_password') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <script>window.YTAN_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE) ?>;</script>
    <script src="<?= $baseUrl ?>/js/i18n.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/i18n.js') ?>"></script>
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
    <style>
        body { background-color: #f3f4f8; }
        #forgotPasswordBox {
            width: 95%;
            max-width: 400px;
            background-color: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.1);
            margin: 80px auto;
            padding: 28px 32px;
            box-sizing: border-box;
        }
        #forgotPasswordBox h1 { font-size: 20px; color: #2c2c3d; text-align: center; }
        /* .leftCol/.rightCol's default fixed-width float layout (style.css)
           is sized for short English labels - a longer translated label
           (e.g. German "Benutzername / E-Mail:") overflows the 74px column
           and visually collides with the input next to it. Stacking the
           label above the input instead sidesteps any column-width
           assumption entirely, regardless of label length/language. */
        #forgotPasswordBox .leftCol { width: 100%; float: none; margin-bottom: 4px; }
        #forgotPasswordBox .rightCol { width: 100%; }
        #forgotPasswordBox input[type="text"] {
            width: calc(100% - 16px);
            border: 1px solid #d8d8e2;
            border-radius: 6px;
            padding: 6px 7px;
        }
        #forgotPasswordMessage { margin: 10px 0; font-size: 13px; }
    </style>
</head>
<body>
    <div id="forgotPasswordBox">
        <h1><?= htmlspecialchars($appName) ?></h1>
        <p><?= $t('forgot.subtitle') ?></p>
        <div class="infoWindowElement">
            <div class="leftCol"><label for="identifier"><?= $t('forgot.identifier_label') ?> </label></div>
            <div class="rightCol"><input id="identifier" type="text" autocomplete="username"></div>
        </div>
        <div id="forgotPasswordMessage"></div>
        <p>
            <button id="forgotPasswordBtn" class="startbtn" onclick="submitForgotPassword()"><?= $t('forgot.send_button') ?></button>
        </p>
        <p><a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/"><?= $t('common.back_to_app', ['app' => htmlspecialchars($appName)]) ?></a></p>
    </div>

    <script>
        var apiBase = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";

        function submitForgotPassword() {
            var identifier = document.getElementById('identifier').value;
            var message = document.getElementById('forgotPasswordMessage');
            var btn = document.getElementById('forgotPasswordBtn');

            if (!identifier) {
                message.textContent = t('forgot.missing_identifier');
                return;
            }

            btn.disabled = true;

            fetch(apiBase + '/auth/forgot-password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ identifier: identifier })
            })
            .catch(function () {
                // deliberately ignored below - always show the same generic message
            })
            .then(function () {
                message.textContent = t('forgot.success');
            });
        }
    </script>
</body>
</html>
