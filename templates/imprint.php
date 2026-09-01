<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($appName) ?> | Impressum</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
</head>
<body>
    <h1>Impressum</h1>
    <h2>Angaben gemäß § 5 TMG:</h2>
    Christoph Steindorff
    <h3>Postanschrift</h3>
    Ohnhorster Weg 14<br>38527 Meine
    <h3>Kontakt</h3>
    Telefon: +49 5304 501120<br>
    <!-- TODO: replace with a YTAN-specific contact address if different from PESR's -->
    E-Mail: info@pesr.org
</body>
</html>
