<?php
/**
 * End-user documentation (/help). One page holding every chapter: the online
 * version (table of contents, search, keyword chips) and the printable
 * document are the same markup, @media print in help.css turns it into a
 * book (cover + table of contents, one chapter per page).
 *
 * Variables: $appName, $baseUrl, $rootDir, $t, $chapters, $keywordIndex,
 * $embed (inside the app's panel iframe: no own back link), $guideInFallbackLocale,
 * $guideTranslator (the guide's language), $tNote (UI-language texts only).
 */
$assetV = static fn (string $path): string => \Ytan\App::assetVersion($rootDir, $path);
$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$title = $t('help.title');
?>
<!DOCTYPE html>
<html class="standalone-page help-page<?= $embed ? ' help-embed' : '' ?>" lang="<?= $e($guideTranslator->locale()) ?>">
<head>
    <title><?= $e($appName) ?> | <?= $e($t('page_title.help')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= $assetV('/css/style.css') ?>" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/fonts.css?v=<?= $assetV('/css/fonts.css') ?>" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/help.css?v=<?= $assetV('/css/help.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">
    <script>
        // Same theme as the app: inside the app's panel the parent page's,
        // standalone the one stored in the settings cookie.
        (function () {
            try {
                var dark = false;
                if (window.parent !== window && window.parent.document.documentElement.dataset.theme === 'dark') {
                    dark = true;
                } else {
                    var m = document.cookie.match(/(?:^|; )settings=([^;]*)/);
                    dark = !!m && JSON.parse(decodeURIComponent(m[1])).theme === 'dark';
                }
                if (dark) { document.documentElement.dataset.theme = 'dark'; }
            } catch (err) { /* light theme */ }
        })();
    </script>
</head>
<body>
    <header class="help-header">
        <?php if (!$embed): ?>
            <a class="help-icon-btn" href="<?= $baseUrl ?>/" title="<?= $e($t('help.back_to_app')) ?>" aria-label="<?= $e($t('help.back_to_app')) ?>"><i class="material-icons-round">arrow_back</i></a>
        <?php endif; ?>
        <h1 class="help-header-title"><?= $e($title) ?></h1>
        <div class="help-search">
            <i class="material-icons-round help-search-icon">search</i>
            <input type="search" id="helpSearch" placeholder="<?= $e($t('help.search_placeholder')) ?>" aria-label="<?= $e($t('help.search_placeholder')) ?>" autocomplete="off" enterkeyhint="search">
            <button type="button" id="helpSearchClear" class="help-search-clear" title="<?= $e($t('help.search_clear')) ?>" aria-label="<?= $e($t('help.search_clear')) ?>" hidden><i class="material-icons-round">close</i></button>
        </div>
        <button type="button" id="helpTocToggle" class="help-icon-btn help-toc-toggle" aria-expanded="false" aria-controls="helpToc" title="<?= $e($t('help.toc_toggle')) ?>" aria-label="<?= $e($t('help.toc_toggle')) ?>"><i class="material-icons-round">menu_book</i></button>
        <button type="button" class="help-icon-btn" onclick="window.print();" title="<?= $e($t('help.print')) ?>" aria-label="<?= $e($t('help.print')) ?>"><i class="material-icons-round">print</i></button>
    </header>

    <div class="help-layout">
        <nav id="helpToc" class="help-toc" aria-label="<?= $e($t('help.contents')) ?>">
            <ol class="help-toc-list">
                <?php foreach ($chapters as $n => $chapter): ?>
                    <li data-chapter="<?= $e($chapter['slug']) ?>">
                        <a href="#chapter-<?= $e($chapter['slug']) ?>"><span class="help-toc-num"><?= $n + 1 ?></span><?= $e($chapter['title']) ?></a>
                        <?php if ($chapter['sections'] !== []): ?>
                            <ul>
                                <?php foreach ($chapter['sections'] as $section): ?>
                                    <li data-section="<?= $e($section['id']) ?>"><a href="#<?= $e($section['id']) ?>"><?= $e($section['title']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <li class="help-toc-index"><a href="#keyword-index"><span class="help-toc-num"><i class="material-icons-round">local_offer</i></span><?= $e($t('help.keyword_index')) ?></a></li>
            </ol>
        </nav>

        <main class="help-content" id="helpContent">
            <?php if ($guideInFallbackLocale): ?>
                <p class="help-note"><?= $e($tNote('help.fallback_note')) ?></p>
            <?php endif; ?>

            <div class="help-cover">
                <div class="help-cover-logo"></div>
                <h1><?= $e($title) ?></h1>
                <p class="help-cover-sub"><?= $e($appName) ?></p>
                <h2 class="help-cover-contents"><?= $e($t('help.contents')) ?></h2>
                <ol class="help-cover-toc">
                    <?php foreach ($chapters as $chapter): ?>
                        <li><?= $e($chapter['title']) ?></li>
                    <?php endforeach; ?>
                    <li><?= $e($t('help.keyword_index')) ?></li>
                </ol>
            </div>

            <div class="help-no-results" id="helpNoResults" hidden><?= $e($t('help.search_none')) ?></div>
            <div class="help-result-count" id="helpResultCount" hidden></div>

            <?php foreach ($chapters as $n => $chapter): ?>
                <section class="help-chapter" id="chapter-<?= $e($chapter['slug']) ?>" data-chapter="<?= $e($chapter['slug']) ?>">
                    <h2 class="help-chapter-title"><span class="help-chapter-num"><?= $n + 1 ?></span><?= $e($chapter['title']) ?></h2>
                    <?php if ($chapter['keywords'] !== []): ?>
                        <p class="help-keywords">
                            <?php foreach ($chapter['keywords'] as $keyword): ?>
                                <button type="button" class="help-chip" data-keyword="<?= $e($keyword) ?>"><?= $e($keyword) ?></button>
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                    <div class="help-body"><?= $chapter['html'] ?></div>
                </section>
            <?php endforeach; ?>

            <section class="help-chapter help-keyword-index" id="keyword-index" data-chapter="keyword-index">
                <h2 class="help-chapter-title"><span class="help-chapter-num"><i class="material-icons-round">local_offer</i></span><?= $e($t('help.keyword_index')) ?></h2>
                <div class="help-body">
                    <dl class="help-index-list">
                        <?php foreach ($keywordIndex as $keyword => $entries): ?>
                            <dt><button type="button" class="help-chip" data-keyword="<?= $e($keyword) ?>"><?= $e($keyword) ?></button></dt>
                            <dd>
                                <?php foreach ($entries as $i => $entry): ?>
                                    <?= $i > 0 ? ', ' : '' ?><a href="#chapter-<?= $e($entry['slug']) ?>"><?= $e($entry['title']) ?></a>
                                <?php endforeach; ?>
                            </dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </section>

            <div class="panel-logo"></div>
        </main>
    </div>

    <button type="button" id="helpToTop" class="help-to-top" title="<?= $e($t('help.back_to_top')) ?>" aria-label="<?= $e($t('help.back_to_top')) ?>" hidden><i class="material-icons-round">arrow_upward</i></button>

    <script>window.HELP_I18N = <?= json_encode(['results' => $t('help.search_results', ['count' => '{count}'])], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
    <script src="<?= $baseUrl ?>/js/help.js?v=<?= $assetV('/js/help.js') ?>"></script>
</body>
</html>
