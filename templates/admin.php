<?php
$pageTitle = $t('admin.nav.dashboard');
$adminActiveNav = 'dashboard';
$pageScripts = ['/js/admin-dashboard.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card mb-3" id="adminDashboardHealth">
    <div class="card-header d-flex align-items-center">
        <h3 class="card-title mb-0"><i class="bi bi-activity me-1"></i><?= $t('admin.health.title') ?></h3>
        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-on-click="loadDashboardHealth()" aria-label="<?= $t('admin.health.reload') ?>" title="<?= $t('admin.health.reload') ?>"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
    <ul class="list-group list-group-flush" id="adminDashboardHealthList">
        <li class="list-group-item text-secondary"><?= $t('admin.dashboard.loading') ?></li>
    </ul>
</div>
<div class="row" id="adminDashboardStats">
    <div class="col-12">
        <p class="text-secondary"><?= $t('admin.dashboard.loading') ?></p>
    </div>
</div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
