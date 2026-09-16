<?php
$pageTitle = $t('admin.image_cleanup.title');
$adminActiveNav = 'image_cleanup';
$pageScripts = ['/js/admin-image-cleanup.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<p class="text-secondary"><?= $t('admin.image_cleanup.hint') ?></p>
<div id="cleanupContent"><p><?= $t('admin.image_cleanup.scanning') ?></p></div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
