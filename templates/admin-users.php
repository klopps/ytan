<?php
$pageTitle = $t('admin_user.title');
$adminActiveNav = 'users';
$pageScripts = ['/js/admin-user.js', '/js/admin-users.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center" id="useradminmenu-header">
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="useradminmenu-back" style="display:none;"><i class="bi bi-arrow-left"></i></button>
            <h2 class="h5 mb-0" id="useradminmenu-title"></h2>
        </div>
        <div id="useradminmenu-action"></div>
    </div>
    <div class="card-body">
        <div id="useradminmenu-form"></div>
        <div id="useradminmenu-list"></div>
    </div>
</div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
