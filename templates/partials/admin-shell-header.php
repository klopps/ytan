<?php
/**
 * Shared AdminLTE shell for every /admin/* page - opening half (doctype
 * through the start of the page-specific content area). Paired with
 * admin-shell-footer.php; together they replace what used to be five
 * separately-duplicated <html>/<head>/login-box blocks. A page uses this
 * as:
 *
 *   $pageTitle = $t('admin.nav.dashboard');
 *   $adminActiveNav = 'dashboard';
 *   $pageScripts = ['/js/admin-dashboard.js']; // read by the footer
 *   require $rootDir . '/templates/partials/admin-shell-header.php';
 *   // ... page body ...
 *   require $rootDir . '/templates/partials/admin-shell-footer.php';
 *
 * Expects $rootDir/$appName/$baseUrl/$translator/$logLevel/$translateToolEnabled
 * closure-captured by the route (src/App.php), same convention every other
 * template already follows. The whole app-wrapper (sidebar+navbar+content)
 * and the login card are both rendered unconditionally and toggled by
 * admin-auth.js's initAdminAuth() via #loginBox / #adminAppWrapper hidden
 * attributes - no server-side auth check, this is a client-side JWT gate
 * like every other admin page.
 *
 * Deliberately stock AdminLTE styling (its own accent color/typography) per
 * "usual conventions" rather than a reskin onto YTAN's own design tokens -
 * only the sidebar brand text and page titles are YTAN-specific. Toasts/
 * confirmations on these pages use Bootstrap's own toast/modal components
 * (see admin-common.js), not the main SPA's toast.js/confirm-dialog.js -
 * those depend on style.css/fonts.css classes this area deliberately
 * doesn't load, to avoid two competing CSS systems colliding.
 */
$t = fn (string $key, array $vars = []) => $translator->t($key, $vars);

$adminNavItems = [
    ['key' => 'dashboard', 'icon' => 'bi-speedometer2', 'label' => $t('admin.nav.dashboard'), 'href' => $baseUrl . '/admin'],
    ['key' => 'users', 'icon' => 'bi-people', 'label' => $t('admin.nav.users'), 'href' => $baseUrl . '/admin/users'],
    ['key' => 'image_cleanup', 'icon' => 'bi-images', 'label' => $t('admin.nav.image_cleanup'), 'href' => $baseUrl . '/admin/image-cleanup'],
    ['key' => 'translate', 'icon' => 'bi-translate', 'label' => $t('admin.nav.translations'), 'href' => $baseUrl . '/admin/translate', 'visible' => $translateToolEnabled],
    ['key' => 'settings', 'icon' => 'bi-gear', 'label' => $t('admin.nav.settings'), 'href' => $baseUrl . '/admin/settings'],
];
?>
<!doctype html>
<html lang="<?= htmlspecialchars($translator->locale()) ?>">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars($appName) ?> Admin</title>
    <meta name="color-scheme" content="light dark" />

    <!-- Prevents a flash of the wrong theme on load - mirrors AdminLTE's
         own recommended inline snippet, reading the same localStorage key
         its color-mode toggle (in the navbar below) writes to. -->
    <script>
        (function () {
            var root = document.documentElement;
            var stored = null;
            try { stored = localStorage.getItem('lte-theme'); } catch (err) {}
            var resolved = 'light';
            if (stored === 'dark' || stored === 'light') {
                resolved = stored;
            } else if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                resolved = 'dark';
            }
            root.setAttribute('data-bs-theme', resolved);
            root.style.colorScheme = resolved;
        })();
    </script>

    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/bootstrap-icons/bootstrap-icons.min.css?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/bootstrap-icons/bootstrap-icons.min.css') ?>" />
    <link rel="stylesheet" href="<?= $baseUrl ?>/lib/adminlte/adminlte.min.css?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/adminlte/adminlte.min.css') ?>" />
    <link rel="stylesheet" href="<?= $baseUrl ?>/css/admin.css?v=<?= \Ytan\App::assetVersion($rootDir, '/css/admin.css') ?>" />
    <link rel="shortcut icon" href="<?= $baseUrl ?>/favicon.ico">

    <script>window.YTAN_API_BASE = "<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>/api/v1";</script>
    <script>window.YTAN_LOCALE = "<?= htmlspecialchars($translator->locale(), ENT_QUOTES) ?>";</script>
    <script>window.YTAN_TRANSLATIONS = <?= json_encode($translator->all(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
    <script>let logLevel = <?= (int) $logLevel ?>;</script>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">

    <!-- LOGIN CARD - shown by admin-auth.js when no valid admin session -->
    <div id="loginBox" class="admin-login-page" hidden>
        <div class="card admin-login-card">
            <div class="card-body">
                <p class="login-box-msg fw-bold fs-4 text-center mb-4"><?= htmlspecialchars($appName) ?> Admin</p>
                <div class="mb-3">
                    <label for="loginUsername" class="form-label"><?= $t('admin.login.username') ?></label>
                    <input id="loginUsername" type="text" class="form-control" autocomplete="username">
                </div>
                <div class="mb-3">
                    <label for="loginPassword" class="form-label"><?= $t('admin.login.password') ?></label>
                    <input id="loginPassword" type="password" class="form-control" autocomplete="current-password">
                </div>
                <button type="button" class="btn btn-primary d-block w-100" onclick="submitAdminLogin();"><?= $t('admin.login.submit') ?></button>
                <div id="loginMessage" class="text-danger small mt-2"></div>
            </div>
        </div>
    </div>

    <!-- APP SHELL - shown by admin-auth.js once an admin session is confirmed -->
    <div id="adminAppWrapper" class="app-wrapper" hidden>
        <nav class="app-header navbar navbar-expand bg-body">
            <div class="container-fluid">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button" aria-label="Toggle sidebar">
                            <i class="bi bi-list"></i>
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= $baseUrl ?>/">
                            <i class="bi bi-box-arrow-left me-1"></i>
                            <span class="d-none d-md-inline"><?= $t('admin.nav.back_to_ytan') ?></span>
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link" href="#" id="bd-theme" aria-label="Toggle color scheme" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-sun-fill" data-lte-theme-icon="light"></i>
                            <i class="bi bi-moon-fill d-none" data-lte-theme-icon="dark"></i>
                            <i class="bi bi-circle-half d-none" data-lte-theme-icon="auto"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="bd-theme">
                            <li><button type="button" class="dropdown-item d-flex align-items-center" data-bs-theme-value="light"><i class="bi bi-sun-fill me-2"></i><?= $t('admin.theme.light') ?></button></li>
                            <li><button type="button" class="dropdown-item d-flex align-items-center" data-bs-theme-value="dark"><i class="bi bi-moon-fill me-2"></i><?= $t('admin.theme.dark') ?></button></li>
                            <li><button type="button" class="dropdown-item d-flex align-items-center active" data-bs-theme-value="auto"><i class="bi bi-circle-half me-2"></i><?= $t('admin.theme.auto') ?></button></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown user-menu">
                        <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i>
                            <span class="d-none d-md-inline" id="adminUserDisplayName"></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button type="button" class="dropdown-item" id="adminLogoutBtn"><i class="bi bi-box-arrow-right me-2"></i><?= $t('admin.nav.logout') ?></button></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </nav>

        <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
            <div class="sidebar-brand">
                <a href="<?= $baseUrl ?>/admin" class="brand-link">
                    <span class="brand-text fw-bold"><?= htmlspecialchars($appName) ?> Admin</span>
                </a>
            </div>
            <div class="sidebar-wrapper">
                <nav class="mt-2" aria-label="Main navigation">
                    <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false">
                        <?php foreach ($adminNavItems as $item): ?>
                            <?php if (($item['visible'] ?? true) === false) { continue; } ?>
                            <li class="nav-item">
                                <a href="<?= htmlspecialchars($item['href']) ?>" class="nav-link<?= $item['key'] === ($adminActiveNav ?? '') ? ' active' : '' ?>">
                                    <i class="nav-icon bi <?= htmlspecialchars($item['icon']) ?>"></i>
                                    <p><?= htmlspecialchars($item['label']) ?></p>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            </div>
        </aside>

        <main class="app-main">
            <div class="app-content-header">
                <div class="container-fluid">
                    <h1 class="mb-0 fs-3"><?= htmlspecialchars($pageTitle) ?></h1>
                </div>
            </div>
            <div class="app-content">
                <div class="container-fluid">
