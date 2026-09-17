<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | <?= $t('page_title.set_password') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <script>window.YTAN_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
    <script src="<?= $baseUrl ?>/js/i18n.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/i18n.js') ?>"></script>
    <!-- helper.js just for togglePasswordVisibility() (the show/hide-password
         toggle, shared with the main SPA) - pulls in a lot of unrelated map/
         route helpers too, but that's a few KB and avoids a third copy of
         the toggle logic living only here. -->
    <script src="<?= $baseUrl ?>/js/helper.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/helper.js') ?>"></script>
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <!-- Needed for the show/hide-password toggle's eye icon
         (.material-icons-round) - app.php loads this too but this
         standalone page otherwise doesn't need it. -->
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/fonts.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/fonts.css') ?>" />
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
        /* Not input[type="password"] - the show/hide toggle switches the
           input's type to "text" while revealed, which would otherwise
           drop all of this styling right when it's toggled. */
        #setPasswordBox .password-input-wrapper input {
            width: calc(100% - 16px);
            box-sizing: border-box;
            border: 1px solid #d8d8e2;
            border-radius: 6px;
            padding: 6px 30px 6px 7px;
        }
        #setPasswordBox .password-toggle-btn { right: 6px; }
        #setPasswordMessage { color: #b3261e; margin: 10px 0; font-size: 13px; }
    </style>
</head>
<body>
    <div id="setPasswordBox">
        <h1><?= htmlspecialchars($appName) ?></h1>
        <p><?= $t('setpw.subtitle') ?></p>
        <div class="infoWindowElement">
            <div class="leftCol"><label for="password"><?= $t('setpw.password_label') ?> </label></div>
            <div class="rightCol"><div class="password-input-wrapper">
                <input id="password" type="password" autocomplete="new-password">
                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('password', this);" aria-label="<?= htmlspecialchars($t('common.show_password'), ENT_QUOTES) ?>"><i class="material-icons-round">visibility</i></button>
            </div></div>
        </div>
        <div class="infoWindowElement">
            <div class="leftCol"><label for="passwordConfirm"><?= $t('setpw.confirm_label') ?> </label></div>
            <div class="rightCol"><div class="password-input-wrapper">
                <input id="passwordConfirm" type="password" autocomplete="new-password">
                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('passwordConfirm', this);" aria-label="<?= htmlspecialchars($t('common.show_password'), ENT_QUOTES) ?>"><i class="material-icons-round">visibility</i></button>
            </div></div>
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
                    throw (result.payload.error || { message: t('setpw.generic_failure') });
                }
                localStorage.setItem('ytan_token', result.payload.token);
                window.location.href = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/";
            })
            .catch(function (err) {
                document.getElementById('setPasswordBtn').disabled = false;
                message.textContent = translateApiError(err);
            });
        }
    </script>
</body>
</html>
