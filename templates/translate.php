<?php
$pageTitle = $t('admin.translate.title');
$adminActiveNav = 'translate';
$pageScripts = ['/js/translate.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card mb-0">
    <div class="card-header d-flex flex-wrap align-items-center gap-2" id="toolbar">
        <input type="text" class="form-control flex-grow-1" id="searchInput" placeholder="<?= $t('admin.translate.filter_placeholder') ?>" oninput="applyFilter();" style="min-width:200px;">
        <span id="dirtyCount" class="text-warning small text-nowrap"></span>
        <button type="button" class="btn btn-primary btn-sm" id="saveAllBtn" onclick="saveAll();" disabled><?= $t('admin.translate.save_all') ?></button>
    </div>
    <div class="card-body p-0">
        <div id="panes" class="d-flex flex-column flex-lg-row" style="height:70vh;">
            <div id="previewPane" class="border-end flex-shrink-0">
                <iframe id="livePreview" src="<?= $baseUrl ?>/" title="Live app preview" style="border:0; width:100%; height:100%;"></iframe>
            </div>
            <div id="editorPane" class="flex-grow-1 overflow-auto p-3">
                <div id="emptyState" class="text-center text-secondary p-5"><?= $t('admin.translate.loading') ?></div>
            </div>
        </div>
    </div>
</div>
<style>
    #previewPane { width: 100%; }
    @media (min-width: 992px) {
        #previewPane { width: 40%; max-width: 480px; }
    }
</style>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
