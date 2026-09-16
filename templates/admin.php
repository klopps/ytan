<?php
$pageTitle = $t('admin.nav.dashboard');
$adminActiveNav = 'dashboard';
$pageScripts = ['/js/admin-dashboard.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="row" id="adminDashboardStats">
    <div class="col-12">
        <p class="text-secondary"><?= $t('admin.dashboard.loading') ?></p>
    </div>
</div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
