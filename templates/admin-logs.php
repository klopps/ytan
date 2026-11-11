<?php
$pageTitle = $t('admin.logs.title');
$adminActiveNav = 'logs';
$pageScripts = ['/js/admin-logs.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<p class="text-secondary"><?= $t('admin.logs.hint') ?></p>

<div class="card mb-4">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <h2 class="h6 mb-0"><?= $t('admin.logs.files') ?></h2>
        <div class="d-flex flex-wrap gap-2 ms-auto">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-on-click="logsLoadList();"><i class="bi bi-arrow-clockwise"></i> <?= $t('admin.logs.refresh') ?></button>
            <button type="button" class="btn btn-primary btn-sm" id="logsDownloadSelected" data-on-click="logsDownloadSelected();" disabled><i class="bi bi-file-earmark-zip"></i> <?= $t('admin.logs.download_selected') ?></button>
        </div>
    </div>
    <div id="logsList"><p class="p-3 mb-0"><?= $t('admin.logs.loading') ?></p></div>
</div>

<div class="card mb-4" id="logsViewer" hidden>
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div>
            <h2 class="h6 mb-1" id="logsViewerName"></h2>
            <p class="text-secondary small mb-0" id="logsViewerInfo"></p>
        </div>
        <div class="d-flex flex-wrap gap-3 align-items-center ms-auto">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="logsFollow" data-on-change="logsSetFollow(this.checked);">
                <label class="form-check-label" for="logsFollow"><?= $t('admin.logs.follow') ?></label>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-on-click="logsDownloadCurrent();"><i class="bi bi-download"></i> <?= $t('admin.logs.download') ?></button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-on-click="logsCloseViewer();" aria-label="<?= $t('admin.logs.close') ?>"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>
    <pre id="logsContent" class="mb-0 p-2 small" style="max-height:70vh; overflow:auto; white-space:pre-wrap; word-break:break-all;"></pre>
</div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
