<?php
$pageTitle = $t('admin.lighthouse_import.title');
$adminActiveNav = 'lighthouse_import';
$pageScripts = ['/js/admin-lighthouse-import.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<p class="text-secondary"><?= $t('admin.lighthouse_import.hint') ?></p>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-2"><?= $t('admin.lighthouse_import.transfer_title') ?></span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="liExportButton"><i class="bi bi-box-arrow-up"></i> <?= $t('admin.lighthouse_import.export') ?></button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="liFileButton"><i class="bi bi-box-arrow-in-down"></i> <?= $t('admin.lighthouse_import.load_file') ?></button>
        <input type="file" id="liFileInput" accept=".json,application/json" hidden>
        <span class="small text-secondary w-100"><?= $t('admin.lighthouse_import.transfer_hint') ?></span>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form id="liFilterForm" class="row g-2" onsubmit="return false;">
            <div class="col-6 col-md-2">
                <label for="liFilterDkNo" class="form-label small mb-1"><?= $t('admin.lighthouse_import.col_dk_no') ?></label>
                <input type="text" class="form-control form-control-sm" id="liFilterDkNo" data-filter="dk_no" autocomplete="off">
            </div>
            <div class="col-6 col-md-2">
                <label for="liFilterIntNo" class="form-label small mb-1"><?= $t('admin.lighthouse_import.col_international_id') ?></label>
                <input type="text" class="form-control form-control-sm" id="liFilterIntNo" data-filter="international_id" autocomplete="off">
            </div>
            <div class="col-12 col-md-3">
                <label for="liFilterRegion" class="form-label small mb-1"><?= $t('admin.lighthouse_import.col_region') ?></label>
                <input type="text" class="form-control form-control-sm" id="liFilterRegion" data-filter="region" list="liRegionList" autocomplete="off">
                <datalist id="liRegionList"></datalist>
            </div>
            <div class="col-12 col-md-3">
                <label for="liFilterName" class="form-label small mb-1"><?= $t('admin.lighthouse_import.col_name') ?></label>
                <input type="text" class="form-control form-control-sm" id="liFilterName" data-filter="name" autocomplete="off">
            </div>
            <div class="col-12 col-md-2">
                <label for="liFilterLocation" class="form-label small mb-1"><?= $t('admin.lighthouse_import.col_location') ?></label>
                <input type="text" class="form-control form-control-sm" id="liFilterLocation" data-filter="location" autocomplete="off">
            </div>
            <div class="col-6 col-md-3">
                <label for="liFilterSource" class="form-label small mb-1"><?= $t('admin.lighthouse_import.col_source') ?></label>
                <select class="form-select form-select-sm" id="liFilterSource" data-filter="source">
                    <option value=""><?= $t('admin.lighthouse_import.source_all') ?></option>
                    <option value="dk"><?= $t('admin.lighthouse_import.source_dk') ?></option>
                    <option value="nga"><?= $t('admin.lighthouse_import.source_nga') ?></option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="liFilterStatus" class="form-label small mb-1"><?= $t('admin.lighthouse_import.col_status') ?></label>
                <select class="form-select form-select-sm" id="liFilterStatus" data-filter="status">
                    <option value=""><?= $t('admin.lighthouse_import.status_all') ?></option>
                    <option value="open"><?= $t('admin.lighthouse_import.status_open') ?></option>
                    <option value="imported"><?= $t('admin.lighthouse_import.status_imported') ?></option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="liPageSize" class="form-label small mb-1"><?= $t('common.rows_per_page') ?></label>
                <select class="form-select form-select-sm" id="liPageSize">
                    <option value="25">25</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                </select>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="liSelectAllMatches"></button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="liSelectNone"><?= $t('admin.lighthouse_import.select_none') ?></button>
        <span class="small text-secondary" id="liSelectedCount"></span>
        <div class="w-100 d-flex flex-wrap align-items-center gap-2" id="liDefaultRanges">
            <span class="small"><?= $t('admin.lighthouse_import.default_ranges') ?></span>
            <label class="small d-flex align-items-center gap-1" for="liRangeW"><?= $t('admin.lighthouse_import.colour_white') ?>
                <input type="number" class="form-control form-control-sm" style="width:5rem;" id="liRangeW" value="3" min="0.1" max="50" step="0.1"></label>
            <label class="small d-flex align-items-center gap-1" for="liRangeR"><?= $t('admin.lighthouse_import.colour_red') ?>
                <input type="number" class="form-control form-control-sm" style="width:5rem;" id="liRangeR" value="2" min="0.1" max="50" step="0.1"></label>
            <label class="small d-flex align-items-center gap-1" for="liRangeG"><?= $t('admin.lighthouse_import.colour_green') ?>
                <input type="number" class="form-control form-control-sm" style="width:5rem;" id="liRangeG" value="2" min="0.1" max="50" step="0.1"></label>
            <span class="small text-secondary"><?= $t('admin.lighthouse_import.default_ranges_hint') ?></span>
        </div>
        <div class="form-check ms-md-auto mb-0">
            <input class="form-check-input" type="checkbox" id="liPublic" checked>
            <label class="form-check-label small" for="liPublic"><?= $t('admin.lighthouse_import.make_public') ?></label>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="liImportButton"><i class="bi bi-box-arrow-in-down"></i> <?= $t('admin.lighthouse_import.import_selected') ?></button>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:2.5rem;"><input type="checkbox" class="form-check-input" id="liTogglePage" title="<?= $t('admin.lighthouse_import.select_page') ?>"></th>
                    <th><?= $t('admin.lighthouse_import.col_source') ?></th>
                    <th><?= $t('admin.lighthouse_import.col_dk_no') ?></th>
                    <th><?= $t('admin.lighthouse_import.col_international_id') ?></th>
                    <th><?= $t('admin.lighthouse_import.col_name') ?></th>
                    <th><?= $t('admin.lighthouse_import.col_location') ?></th>
                    <th><?= $t('admin.lighthouse_import.col_region') ?></th>
                    <th><?= $t('admin.lighthouse_import.col_characteristic') ?></th>
                    <th><?= $t('admin.lighthouse_import.col_sectors') ?></th>
                    <th><?= $t('admin.lighthouse_import.col_status') ?></th>
                </tr>
            </thead>
            <tbody id="liRows"></tbody>
        </table>
    </div>
    <div class="card-footer d-flex align-items-center justify-content-center gap-3" id="liPagination"></div>
</div>

<div class="modal fade" id="liConflictModal" tabindex="-1" aria-labelledby="liConflictTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="liConflictTitle"><?= $t('admin.lighthouse_import.conflict_title') ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $t('common.cancel') ?>"></button>
            </div>
            <div class="modal-body">
                <p id="liConflictIntro"></p>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <label for="liConflictAll" class="small mb-0"><?= $t('admin.lighthouse_import.conflict_for_all') ?></label>
                    <select id="liConflictAll" class="form-select form-select-sm w-auto">
                        <option value=""><?= $t('admin.lighthouse_import.conflict_choose') ?></option>
                        <option value="add"><?= $t('admin.lighthouse_import.conflict_add') ?></option>
                        <option value="overwrite"><?= $t('admin.lighthouse_import.conflict_overwrite') ?></option>
                        <option value="skip"><?= $t('admin.lighthouse_import.conflict_skip') ?></option>
                    </select>
                </div>
                <div id="liConflictList" class="list-group list-group-flush"></div>
                <p class="mt-3 mb-0 small text-secondary"><?= $t('admin.lighthouse_import.conflict_hint') ?></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $t('common.cancel') ?></button>
                <button type="button" class="btn btn-primary" id="liConflictConfirm"><?= $t('admin.lighthouse_import.conflict_confirm') ?></button>
            </div>
        </div>
    </div>
</div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
