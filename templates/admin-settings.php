<?php
$pageTitle = $t('admin.settings.title');
$adminActiveNav = 'settings';
$pageScripts = ['/js/admin-settings.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card">
    <div class="card-body">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="settingGoogleSearchRequiresLogin" <?= $googleSearchRequiresLogin ? 'checked' : '' ?>>
            <label class="form-check-label" for="settingGoogleSearchRequiresLogin"><?= $t('admin.settings.google_search_requires_login') ?></label>
        </div>
    </div>
</div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
