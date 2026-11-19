<!DOCTYPE html>
<html class="standalone-page">
<head>
    <title><?= htmlspecialchars($appName) ?> | <?= $t('page_title.credits') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
</head>
<body>
    <h1><?= $t('credits.title') ?></h1>
    <p><?= $t('credits.intro', ['app' => htmlspecialchars($appName)]) ?></p>
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
    <h2><?= $t('about.tide_title') ?></h2>
    <?php /* renamed from about.tide_text 2026-11-16: merge-i18n keeps the server's value of an existing key, so a changed source list only reaches prod under a new key */ ?>
    <p><?= $t('about.tide_sources_text', [
        'bsh_link' => '<a href="https://gezeiten.bsh.de/" target="_blank" rel="noopener">' . htmlspecialchars($t('about.tide_bsh_name')) . '</a>',
        'dmi_link' => '<a href="https://www.dmi.dk/friedata/" target="_blank" rel="noopener">DMI</a>',
        'rws_link' => '<a href="https://rijkswaterstaatdata.nl/" target="_blank" rel="noopener">Rijkswaterstaat</a>',
        'imi_link' => '<a href="https://www.marine.ie/" target="_blank" rel="noopener">Marine Institute</a>',
        'ukho_link' => '<a href="https://www.admiralty.co.uk/" target="_blank" rel="noopener">UK Hydrographic Office (ADMIRALTY)</a>',
        'kartverket_link' => '<a href="https://www.kartverket.no/til-sjos/se-havniva" target="_blank" rel="noopener">Kartverket</a>',
        'ih_link' => '<a href="https://www.hidrografico.pt/" target="_blank" rel="noopener">Instituto Hidrográfico</a>',
        'meteogalicia_link' => '<a href="https://www.meteogalicia.gal/" target="_blank" rel="noopener">MeteoGalicia</a>',
        'abertos_link' => '<a href="https://abertos.xunta.gal" target="_blank" rel="noopener" title="Abert@s: Portal Open Data da Xunta de Galicia">Abert@s</a>',
        'ticon_link' => '<a href="https://doi.org/10.17882/109129" target="_blank" rel="noopener">TICON-4</a>',
        'tidesatlas_link' => '<a href="https://tidesatlas.com/" target="_blank" rel="noopener">TidesAtlas</a>',
        'ifremer_link' => '<a href="https://marc.ifremer.fr/" target="_blank" rel="noopener">Ifremer (MARC)</a>',
        'openmeteo_link' => '<a href="https://open-meteo.com/" target="_blank" rel="noopener">Open-Meteo</a>',
    ]) ?></p>
    <p><?= $t('about.river_gauges_text', [
        'pegelonline_link' => '<a href="https://www.pegelonline.wsv.de/" target="_blank" rel="noopener">PEGELONLINE</a>',
    ]) ?></p>
    <?php /* TidesAtlas "Free Web" plan: this exact line, in the page HTML (not added by JavaScript) - their condition for the free key */ ?>
    <p class="tidesatlas-credit">Tide data: <a href="https://tidesatlas.com/">TidesAtlas</a></p>
</body>
</html>
