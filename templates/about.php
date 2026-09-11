<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | <?= $t('page_title.about') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
</head>
<body>
    <h1><?= $t('about.title') ?></h1>
    <p>
        <?= $t('about.p1', ['app' => htmlspecialchars($appName)]) ?>
    </p>
    <p>
        <?= $t('about.p2') ?>
    </p>
    <p>
        <?= $t('about.p3', [
            'doc_link' => '<a href="https://tjornkajak.se/paddla-kajak-och-talta-i-bohuslan-haftena" target="_blank">Sea kayaking and camping in Bohuslän Part A to C</a>',
            'author_link' => '<a href="https://tjornkajak.se/jens-marklund/" target="_blank">Jens Marklund</a>',
            'club_link' => '<a href="https://tjornkajak.se/" target="_blank">Tjörns Kajakklubb</a>',
        ]) ?>
    </p>
</body>
</html>
