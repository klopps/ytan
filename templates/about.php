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
    <?php if (!empty($dataSources)): ?>
    <h2><?= $t('about.data_sources_title') ?></h2>
    <p><?= $t('about.data_sources_intro') ?></p>
    <ul>
        <?php foreach ($dataSources as $source): ?>
        <li>
            <?php if (!empty($source['url'])): ?>
            <a href="<?= htmlspecialchars($source['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($source['name']) ?></a>
            <?php else: ?>
            <?= htmlspecialchars($source['name']) ?>
            <?php endif; ?>
            <?php if (!empty($source['attribution'])): ?> - <?= htmlspecialchars($source['attribution']) ?><?php endif; ?>
            <?php if (!empty($source['license'])): ?>
            (<?php if (!empty($source['license_url'])): ?><a href="<?= htmlspecialchars($source['license_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($source['license']) ?></a><?php else: ?><?= htmlspecialchars($source['license']) ?><?php endif; ?>)
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <h2><?= $t('about.radar_title') ?></h2>
    <p><?= $t('about.radar_text', [
        'source_link' => '<a href="https://www.eumetnet.eu/observations/weather-radar-network/" target="_blank" rel="noopener">EUMETNET OPERA</a>',
        'license_link' => '<a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener">CC BY 4.0</a>',
    ]) ?></p>
    <h2><?= $t('about.wind_title') ?></h2>
    <p><?= $t('about.wind_text', [
        'source_link' => '<a href="https://www.dwd.de/DE/leistungen/opendata/opendata.html" target="_blank" rel="noopener">' . htmlspecialchars($t('about.wind_source_name')) . '</a>',
    ]) ?></p>
</body>
</html>
