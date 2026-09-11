<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($appName) ?> | Set password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
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
        <p>Please choose a password for your account.</p>
        <div class="infoWindowElement">
            <div class="leftCol"><label for="password">Password: </label></div>
            <div class="rightCol"><input id="password" type="password" autocomplete="new-password"></div>
        </div>
        <div class="infoWindowElement">
            <div class="leftCol"><label for="passwordConfirm">Confirm: </label></div>
            <div class="rightCol"><input id="passwordConfirm" type="password" autocomplete="new-password"></div>
        </div>
        <div id="setPasswordMessage"></div>
        <p>
            <button id="setPasswordBtn" class="startbtn" onclick="submitPassword()">Set password &amp; log in</button>
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
                message.textContent = 'This link is missing its token and cannot be used.';
                return;
            }

            if (password !== passwordConfirm) {
                message.textContent = 'The two passwords do not match.';
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
                    throw new Error((result.payload.error && result.payload.error.message) || 'Setting the password failed.');
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
