<?php
$pageTitle = $t('admin.lighthouse_duplicates.title');
$adminActiveNav = 'lighthouse_duplicates';
$pageScripts = ['/js/admin-lighthouse-duplicates.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<p class="text-secondary"><?= $t('admin.lighthouse_duplicates.hint') ?></p>

<div class="card mb-3">
    <div class="card-body">
        <form id="ldForm" class="row g-2 align-items-end" onsubmit="return false;">
            <div class="col-6 col-md-3">
                <label for="ldDistance" class="form-label small mb-1"><?= $t('admin.lighthouse_duplicates.distance_label') ?></label>
                <input type="number" class="form-control form-control-sm" id="ldDistance" value="50" min="1" max="5000" step="any">
            </div>
            <div class="col-6 col-md-3">
                <label for="ldPageSize" class="form-label small mb-1"><?= $t('admin.lighthouse_duplicates.groups_per_page') ?></label>
                <select class="form-select form-select-sm" id="ldPageSize">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="ldHideDifferentIds">
                    <label class="form-check-label small" for="ldHideDifferentIds"><?= $t('admin.lighthouse_duplicates.hide_different_ids') ?></label>
                </div>
                <div class="form-text mt-0 mb-2"><?= $t('admin.lighthouse_duplicates.hide_different_ids_hint') ?></div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="ldOnlyWithManual">
                    <label class="form-check-label small" for="ldOnlyWithManual"><?= $t('admin.lighthouse_duplicates.only_with_manual') ?></label>
                </div>
                <div class="form-text mt-0"><?= $t('admin.lighthouse_duplicates.only_with_manual_hint') ?></div>
            </div>
            <div class="col-12 col-md-3">
                <button type="submit" class="btn btn-primary btn-sm" id="ldSearch"><i class="bi bi-search"></i> <?= $t('admin.lighthouse_duplicates.search') ?></button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="small text-secondary" id="ldSummary"></span>
        <span class="small ms-md-auto" id="ldSelected"></span>
        <button type="button" class="btn btn-danger btn-sm" id="ldMergeButton"><i class="bi bi-intersect"></i> <?= $t('admin.lighthouse_duplicates.merge_button') ?></button>
    </div>
</div>

<p class="small text-secondary"><?= $t('admin.lighthouse_duplicates.choose_hint') ?></p>

<div id="ldGroups"></div>

<div class="d-flex align-items-center justify-content-center gap-3 mb-4" id="ldPagination"></div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
