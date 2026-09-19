<?php
/**
 * Gestaltungs-Vergleichsseite (todo.md: "Standards für alle Gestaltungs-
 * elemente festlegen") - zeigt eine Kernauswahl der im öffentlichen Bereich
 * verwendeten UI-Elemente nebeneinander, um Inkonsistenzen sichtbar zu
 * machen, bevor sie angeglichen werden. Bewusst KEINE der fünf anderen
 * /admin/*-Seiten, die alle admin-shell-header.php/-footer.php (AdminLTE)
 * durchlaufen - jene Shell lädt explizit kein style.css/fonts.css ("to
 * avoid two competing CSS systems colliding", siehe deren eigener
 * Kommentar), genau das aber ist hier der Zweck der Seite. Eigener
 * <html><head> analog zu templates/set-password.php, admin-gated über das
 * bestehende, framework-agnostische initAdminAuth() aus admin-auth.js.
 *
 * Lädt zusätzlich ein vendored Bootstrap-CSS (nur auf dieser Seite) für den
 * Floating-Labels-Abschnitt - bewusst VOR style.css eingebunden, damit bei
 * gleicher Spezifität style.css' eigene Klassen gewinnen und nur Bootstraps
 * eigene, hier nicht überschriebene Klassen (.form-floating/.form-control)
 * unverändert durchschlagen.
 */
?>
<!doctype html>
<html lang="<?= htmlspecialchars($translator->locale()) ?>" class="standalone-page">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $t('admin.styleguide.title') ?> | <?= htmlspecialchars($appName) ?> Admin</title>

    <!-- Verhindert einen Theme-Flash beim Laden, analog zum Inline-Snippet
         in admin-shell-header.php - eigener localStorage-Key, unabhängig
         vom Settings-Cookie der Haupt-App. -->
    <script>
        (function () {
            var dark = false;
            try {
                dark = localStorage.getItem('ytan_styleguide_theme') === 'dark';
            } catch (err) {}
            if (dark) {
                document.documentElement.dataset.theme = 'dark';
            }
            document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
        })();
    </script>

    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/bootstrap/bootstrap.min.css?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/bootstrap/bootstrap.min.css') ?>" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/style.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/style.css') ?>" />
    <link rel="stylesheet" type="text/css" href="<?= $baseUrl ?>/css/fonts.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/fonts.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">

    <script>window.YTAN_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script>window.YTAN_LOCALE = "<?= htmlspecialchars($translator->locale(), ENT_QUOTES) ?>";</script>
    <script>window.YTAN_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>

    <style>
        body {
            margin: 0;
            background: var(--color-bg-page);
            color: var(--color-text);
            font-family: var(--font-body);
        }
        .styleguide-login-box {
            width: 95%;
            max-width: 360px;
            background-color: var(--color-bg-panel);
            border-radius: 16px;
            box-shadow: var(--shadow-panel);
            margin: 80px auto;
            padding: 28px 32px;
            box-sizing: border-box;
        }
        .styleguide-login-box h1 {
            font-family: var(--font-display);
            font-size: 20px;
            text-align: center;
            margin-top: 0;
        }
        .styleguide-login-box .nav-btn-primary {
            width: 100%;
            box-sizing: border-box;
            margin-top: 4px;
        }
        .styleguide-header {
            padding: 20px;
            border-bottom: 1px solid var(--color-border-subtle);
            background: var(--color-bg-panel);
        }
        .styleguide-header h1 {
            font-family: var(--font-display);
            margin: 0 0 4px;
        }
        .styleguide-header-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .styleguide-jumpnav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
        }
        .styleguide-jumpnav a {
            font-size: 13px;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid var(--color-border-subtle);
            color: var(--color-text-secondary);
            text-decoration: none;
        }
        .styleguide-section {
            padding: 28px 20px;
            border-bottom: 1px solid var(--color-border-subtle);
            max-width: 1000px;
        }
        .styleguide-section h2 {
            font-family: var(--font-display);
            margin-bottom: 4px;
        }
        .styleguide-source {
            font-size: 12px;
            color: var(--color-text-faint);
            margin: 0 0 18px;
        }
        .styleguide-source code {
            background: var(--color-bg-subtle);
            padding: 1px 5px;
            border-radius: 4px;
        }
        .styleguide-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-start;
        }
        .styleguide-swatch {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
        }
        .styleguide-swatch-label {
            font-size: 11px;
            font-family: monospace;
            color: var(--color-text-faint);
        }
        .styleguide-field {
            width: 240px;
        }
        /* Bootstrap ships its own .toast component (display:none until a
           .show class is added) - since public/js/toast.js's real toasts
           use the exact same class name but toggle .toast-visible instead,
           Bootstrap's rule silently wins on this page (equal specificity,
           later in the cascade) and the demo toasts never became visible
           until this override was added. Rescues style.css's own .toast
           display value; loads after both stylesheets so it wins the tie. */
        #toastContainer.toast-container { display: flex; }
        #toastContainer .toast { display: flex; }
    </style>
</head>
<body>

    <!-- LOGIN-BOX - eigenes, schlankes Markup im .nav-field/.password-input-
         wrapper/.nav-btn-primary-Stil des öffentlichen Bereichs statt
         AdminLTEs Bootstrap-Login-Karte (public/js/user.js's Muster). Der
         Passwort-Toggle-Button ist von Hand nachgebaut statt über
         passwordToggleButtonHtml() erzeugt - dieselbe Konvention wie
         templates/set-password.php, da diese Datei zur Render-Zeit kein JS
         ausführen kann. -->
    <div id="loginBox" class="styleguide-login-box" hidden>
        <h1><?= htmlspecialchars($appName) ?> Styleguide</h1>
        <div class="nav-field">
            <label for="loginUsername"><?= $t('admin.login.username') ?></label>
            <input id="loginUsername" type="text" autocomplete="username">
        </div>
        <div class="nav-field">
            <label for="loginPassword"><?= $t('admin.login.password') ?></label>
            <div class="password-input-wrapper">
                <input id="loginPassword" type="password" autocomplete="current-password">
                <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('loginPassword', this);" aria-label="<?= htmlspecialchars($t('common.show_password'), ENT_QUOTES) ?>"><i class="material-icons-round">visibility</i></button>
            </div>
        </div>
        <button type="button" class="nav-btn-primary" onclick="submitAdminLogin();"><i class="material-icons-round">login</i>&nbsp;<?= $t('admin.login.submit') ?></button>
        <div id="loginMessage" class="nav-form-message"></div>
    </div>

    <div id="styleguideContent" hidden>
        <div class="styleguide-header">
            <div class="styleguide-header-row">
                <div>
                    <h1><?= $t('admin.styleguide.title') ?></h1>
                    <p><?= $t('admin.styleguide.intro') ?></p>
                </div>
                <div class="nav-segmented" id="styleguideThemeToggle">
                    <input type="radio" id="styleguideThemeLight" name="styleguideTheme" value="light" onclick="setStyleguideTheme('light');">
                    <label for="styleguideThemeLight"><?= $t('admin.theme.light') ?></label>
                    <input type="radio" id="styleguideThemeDark" name="styleguideTheme" value="dark" onclick="setStyleguideTheme('dark');">
                    <label for="styleguideThemeDark"><?= $t('admin.theme.dark') ?></label>
                </div>
            </div>
            <nav class="styleguide-jumpnav">
                <a href="#sg-typography"><?= $t('admin.styleguide.section_typography') ?></a>
                <a href="#sg-buttons"><?= $t('admin.styleguide.section_buttons') ?></a>
                <a href="#sg-inputs"><?= $t('admin.styleguide.section_inputs') ?></a>
                <a href="#sg-badges"><?= $t('admin.styleguide.section_badges') ?></a>
                <a href="#sg-checkboxes"><?= $t('admin.styleguide.section_checkboxes') ?></a>
                <a href="#sg-toasts"><?= $t('admin.styleguide.section_toasts') ?></a>
                <a href="#sg-warnings"><?= $t('admin.styleguide.section_warnings') ?></a>
            </nav>
        </div>

        <!-- TYPOGRAFIE -->
        <section class="styleguide-section" id="sg-typography">
            <h2><?= $t('admin.styleguide.section_typography') ?></h2>
            <p class="styleguide-source"><?= $t('admin.styleguide.source_label') ?> <code>--font-display</code> ('Sora'), <code>--font-body</code> ('Plus Jakarta Sans') - <code>public/css/style.css</code>, kein durchgängiges h1-h6-Regelwerk (Überschriften werden je Panel einzeln gesetzt, z.B. <code>.cm-panel-title</code>).</p>
            <h1 style="font-family:var(--font-display);">H1 - Sora</h1>
            <h2 style="font-family:var(--font-display);">H2 - Sora</h2>
            <h3 style="font-family:var(--font-display);">H3 - Sora</h3>
            <p style="font-family:var(--font-body);">Fließtext in Plus Jakarta Sans (--font-body). Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p>
            <p style="font-family:var(--font-body); font-size:13px; color:var(--color-text-secondary);">Kleinerer Sekundärtext (13px, --color-text-secondary), z.B. in Listen/Untertiteln.</p>
        </section>

        <!-- BUTTONS -->
        <section class="styleguide-section" id="sg-buttons">
            <h2><?= $t('admin.styleguide.section_buttons') ?></h2>
            <p class="styleguide-source"><?= $t('admin.styleguide.source_label') ?> 13+ eigenständige Button-Klassen in <code>public/css/style.css</code> - keine gemeinsame Basisklasse.</p>
            <div class="styleguide-row">
                <div class="styleguide-swatch">
                    <button type="button" class="nav-btn-primary"><i class="material-icons-round">check</i>&nbsp;Primary</button>
                    <span class="styleguide-swatch-label">.nav-btn-primary</span>
                </div>
                <div class="styleguide-swatch">
                    <button type="button" class="nav-btn-secondary"><i class="material-icons-round">edit</i>&nbsp;Secondary</button>
                    <span class="styleguide-swatch-label">.nav-btn-secondary</span>
                </div>
                <div class="styleguide-swatch">
                    <button type="button" class="nav-btn-secondary nav-btn-danger"><i class="material-icons-round">logout</i>&nbsp;Danger</button>
                    <span class="styleguide-swatch-label">.nav-btn-secondary.nav-btn-danger</span>
                </div>
                <div class="styleguide-swatch">
                    <button type="button" class="startbtn">Start</button>
                    <span class="styleguide-swatch-label">.startbtn</span>
                </div>
                <div class="styleguide-swatch">
                    <button type="button" class="button">Cancel</button>
                    <span class="styleguide-swatch-label">.button</span>
                </div>
                <div class="styleguide-swatch">
                    <button type="button" class="button button-danger">Delete</button>
                    <span class="styleguide-swatch-label">.button.button-danger</span>
                </div>
                <div class="styleguide-swatch">
                    <button type="button" class="nav-chip-btn">Alle auswählen</button>
                    <span class="styleguide-swatch-label">.nav-chip-btn</span>
                </div>
                <div class="styleguide-swatch">
                    <button type="button" class="nav-back"><i class="material-icons-round">arrow_back</i></button>
                    <span class="styleguide-swatch-label">.nav-back</span>
                </div>
                <div class="styleguide-swatch">
                    <button type="button" class="toolbar-icon-btn" style="position:static;"><i class="material-icons-round">add_location</i></button>
                    <span class="styleguide-swatch-label">.toolbar-icon-btn (Original: Inline-SVG statt Icon-Font, siehe <code>templates/app.php</code>)</span>
                </div>
            </div>
        </section>

        <!-- INPUTFELDER / PASSWORTFELDER -->
        <section class="styleguide-section" id="sg-inputs">
            <h2><?= $t('admin.styleguide.section_inputs') ?></h2>
            <p class="styleguide-source"><?= $t('admin.styleguide.source_label') ?> <code>.nav-field input</code> (<code>public/css/style.css</code>) für das bestehende App-Design; <code>.form-floating</code> ist Bootstraps Floating-Label-Komponente (neu vendored, nur auf dieser Seite geladen).</p>
            <div class="styleguide-row">
                <div class="styleguide-swatch">
                    <div class="nav-field styleguide-field">
                        <label for="sgPlainText"><?= $t('admin.styleguide.label_text_field') ?></label>
                        <input id="sgPlainText" type="text" placeholder="Platzhalter">
                    </div>
                    <span class="styleguide-swatch-label">.nav-field input</span>
                </div>
                <div class="styleguide-swatch">
                    <div class="nav-field styleguide-field">
                        <label for="sgPlainPassword"><?= $t('admin.login.password') ?></label>
                        <div class="password-input-wrapper">
                            <input id="sgPlainPassword" type="password" autocomplete="off">
                            <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('sgPlainPassword', this);" aria-label="<?= htmlspecialchars($t('common.show_password'), ENT_QUOTES) ?>"><i class="material-icons-round">visibility</i></button>
                        </div>
                    </div>
                    <span class="styleguide-swatch-label">.nav-field .password-input-wrapper</span>
                </div>
                <div class="styleguide-swatch">
                    <div class="form-floating styleguide-field">
                        <input type="text" class="form-control" id="sgFloatingText" placeholder=" ">
                        <label for="sgFloatingText"><?= $t('admin.styleguide.floating_label_text') ?></label>
                    </div>
                    <span class="styleguide-swatch-label">.form-floating (Bootstrap)</span>
                </div>
                <div class="styleguide-swatch">
                    <div class="form-floating password-input-wrapper styleguide-field">
                        <input type="password" class="form-control" id="sgFloatingPassword" autocomplete="off" placeholder=" ">
                        <label for="sgFloatingPassword"><?= $t('admin.styleguide.floating_label_password') ?></label>
                        <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('sgFloatingPassword', this);" aria-label="<?= htmlspecialchars($t('common.show_password'), ENT_QUOTES) ?>"><i class="material-icons-round">visibility</i></button>
                    </div>
                    <span class="styleguide-swatch-label">.form-floating + .password-input-wrapper</span>
                </div>
            </div>
        </section>

        <!-- BADGES -->
        <section class="styleguide-section" id="sg-badges">
            <h2><?= $t('admin.styleguide.section_badges') ?></h2>
            <p class="styleguide-source"><?= $t('admin.styleguide.source_label') ?> vier unterschiedlich benannte "Badge"-Klassen in <code>public/css/style.css</code>, je eigenständig pro Feature entstanden.</p>
            <div class="styleguide-row">
                <div class="styleguide-swatch">
                    <span class="admin-badge">Öffentlich</span>
                    <span class="styleguide-swatch-label">.admin-badge</span>
                </div>
                <div class="styleguide-swatch">
                    <div class="search-remove-badge" title="Entfernen"><i class="material-icons-round">close</i></div>
                    <span class="styleguide-swatch-label">.search-remove-badge</span>
                </div>
            </div>
        </section>

        <!-- CHECKBOXEN / RADIOBUTTONS / PILLSWITCHES -->
        <section class="styleguide-section" id="sg-checkboxes">
            <h2><?= $t('admin.styleguide.section_checkboxes') ?></h2>
            <p class="styleguide-source"><?= $t('admin.styleguide.source_label') ?> <code>input[type="checkbox"] + label::before</code>-Hack, <code>.nav-segmented</code> (Radio-Pills), <code>.nav-toggle-row</code> (Pillswitch) - alle in <code>public/css/style.css</code>. (<code>.course</code>/<code>.slider</code>/<code>.star</code>-Checkbox-Varianten existieren im CSS, werden aber aktuell nirgends im Code verwendet.)</p>
            <div class="styleguide-row">
                <div class="styleguide-swatch">
                    <input id="sgCheckbox" type="checkbox" checked><label for="sgCheckbox">Checkbox</label>
                    <span class="styleguide-swatch-label">input[type=checkbox] + label</span>
                </div>
                <div class="styleguide-swatch">
                    <div class="nav-segmented">
                        <input type="radio" id="sgRadio1" name="sgRadio" value="a" checked><label for="sgRadio1">Option A</label>
                        <input type="radio" id="sgRadio2" name="sgRadio" value="b"><label for="sgRadio2">Option B</label>
                    </div>
                    <span class="styleguide-swatch-label">.nav-segmented</span>
                </div>
                <div class="styleguide-swatch">
                    <label class="nav-toggle-row"><span class="nav-toggle-text">Pillswitch</span><input type="checkbox" id="sgSwitch" class="nav-switch-input" checked><span class="nav-switch-track"><span class="nav-switch-thumb"></span></span></label>
                    <span class="styleguide-swatch-label">.nav-toggle-row</span>
                </div>
            </div>
        </section>

        <!-- TOASTS -->
        <section class="styleguide-section" id="sg-toasts">
            <h2><?= $t('admin.styleguide.section_toasts') ?></h2>
            <p class="styleguide-source"><?= $t('admin.styleguide.source_label') ?> <code>public/js/toast.js</code> - die einzige bereits durchgängig wiederverwendete Komponente. Live-Demo, kein Screenshot.</p>
            <div class="styleguide-row">
                <button type="button" class="nav-btn-secondary" onclick="styleguideShowToast('info');">Info</button>
                <button type="button" class="nav-btn-secondary" onclick="styleguideShowToast('success');">Success</button>
                <button type="button" class="nav-btn-secondary" onclick="styleguideShowToast('warning');">Warning</button>
                <button type="button" class="nav-btn-secondary" onclick="styleguideShowToast('error');">Error</button>
            </div>
        </section>

        <!-- WARNUNGEN -->
        <section class="styleguide-section" id="sg-warnings">
            <h2><?= $t('admin.styleguide.section_warnings') ?></h2>
            <p class="styleguide-source"><?= $t('admin.styleguide.source_label') ?> <code>.nav-form-message</code> (<code>public/css/style.css</code>) - Warn-Variante ist aktuell kein eigener Klassenzusatz, sondern ein <code>style="color: var(--color-warning);"</code>-Inline-Override direkt im aufrufenden Code (<code>public/js/user.js</code>, <code>pendingEmailNotice</code>).</p>
            <div class="styleguide-row" style="flex-direction:column; gap:10px;">
                <div class="nav-form-message">Standard-Meldung (.nav-form-message, ohne Override)</div>
                <div class="nav-form-message" style="color: var(--color-warning);">Warnung (.nav-form-message, style="color: var(--color-warning)")</div>
                <div class="nav-form-message" style="color: var(--color-danger);">Fehler (.nav-form-message, style="color: var(--color-danger)")</div>
            </div>
        </section>

        <p style="padding:0 20px 40px; font-size:13px; color:var(--color-text-faint);"><?= $t('admin.styleguide.missing_categories_note') ?></p>
    </div>

    <div id="toastContainer" class="toast-container"></div>

    <script src="<?= $baseUrl ?>/js/config.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/config.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/i18n.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/i18n.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/helper.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/helper.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/api-client.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/api-client.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/toast.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/toast.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/admin-auth.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-auth.js') ?>"></script>
    <script src="<?= $baseUrl ?>/js/admin-styleguide.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-styleguide.js') ?>"></script>
    <script>
        initStyleguideTheme();
        initAdminAuth({ contentId: 'styleguideContent' });
    </script>
</body>
</html>
