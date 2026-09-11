<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | <?= $t('page_title.set_password') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <script>window.YTAN_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE) ?>;</script>
    <script src="<?= $baseUrl ?>/js/i18n.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/i18n.js') ?>"></script>
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
    <style>
        body { background-color: #f3f4f8; }
        #setPasswordBox {
            width: 95%;
            max-width: 400px;
            background-color: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.1);
            margin: 80px auto;
            padding: 28px 32px;
            box-sizing: border-box;
        }
        #setPasswordBox h1 { font-size: 20px; color: #2c2c3d; text-align: center; }
        /* See forgot-password.php's identical override for why - a longer
           translated label (e.g. German "Bestätigung:") overflows
           .leftCol/.rightCol's default fixed-width float layout and
           visually collides with the input next to it. */
        #setPasswordBox .leftCol { width: 100%; float: none; margin-bottom: 4px; }
        #setPasswordBox .rightCol { width: 100%; }
        #setPasswordBox input[type="password"] {
            width: calc(100% - 16px);
            border: 1px solid #d8d8e2;
            border-radius: 6px;
            padding: 6px 7px;
        }
        #setPasswordMessage { color: #b3261e; margin: 10px 0; font-size: 13px; }
    </style>
</head>
<body>
    <div id="setPasswordBox">
        <h1><?= htmlspecialchars($appName) ?></h1>
        <p><?= $t('setpw.subtitle') ?></p>
        <div class="infoWindowElement">
            <div class="leftCol"><label for="password"><?= $t('setpw.password_label') ?> </label></div>
            <div class="rightCol"><input id="password" type="password" autocomplete="new-password"></div>
        </div>
        <div class="infoWindowElement">
            <div class="leftCol"><label for="passwordConfirm"><?= $t('setpw.confirm_label') ?> </label></div>
            <div class="rightCol"><input id="passwordConfirm" type="password" autocomplete="new-password"></div>
        </div>
        <div id="setPasswordMessage"></div>
        <p>
            <button id="setPasswordBtn" class="startbtn" onclick="submitPassword()"><?= $t('setpw.submit_button') ?></button>
        </p>
    </div>

    <script>
        var token = <?= json_encode($_GET['token'] ?? '') ?>;
        var apiBase = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";

        function submitPassword() {
            var password = document.getElementById('password').value;
            var passwordConfirm = document.getElementById('passwordConfirm').value;
            var message = document.getElementById('setPasswordMessage');
            message.textContent = '';

            if (!token) {
                message.textContent = t('common.missing_token');
                return;
            }

            if (password !== passwordConfirm) {
                message.textContent = t('setpw.password_mismatch');
                return;
            }

            document.getElementById('setPasswordBtn').disabled = true;

            fetch(apiBase + '/auth/set-password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: token, password: password })
            })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            })
            .then(function (result) {
                if (!result.ok) {
                    throw new Error((result.payload.error && result.payload.error.message) || t('setpw.generic_failure'));
                }
                localStorage.setItem('ytan_token', result.payload.token);
                window.location.href = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/";
            })
            .catch(function (err) {
                document.getElementById('setPasswordBtn').disabled = false;
                message.textContent = err.message;
            });
        }
    </script>
</body>
</html>
