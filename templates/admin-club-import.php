<?php
$pageTitle = $t('admin.club_import.title');
$adminActiveNav = 'club_import';
$pageScripts = ['/js/admin-club-import.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';

$countryCodes = \Ytan\Service\ClubOsmImportService::EUROPE;
?>
<p class="text-secondary"><?= $t('admin.club_import.hint') ?></p>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-2"><?= $t('admin.club_import.transfer_title') ?></span>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="ciExportButton"><i class="bi bi-box-arrow-up"></i> <?= $t('admin.club_import.export') ?></button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="ciFileButton"><i class="bi bi-box-arrow-in-down"></i> <?= $t('admin.club_import.load_file') ?></button>
        <input type="file" id="ciFileInput" accept=".json,application/json" hidden>
        <span class="small text-secondary w-100"><?= $t('admin.club_import.transfer_hint') ?></span>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6"><?= $t('admin.club_import.osm_title') ?></h2>
        <p class="small text-secondary mb-2"><?= $t('admin.club_import.osm_hint') ?></p>
        <form id="ciOsmForm" class="row g-2 align-items-end" data-on-submit="return false;">
            <div class="col-6 col-md-3">
                <label for="ciOsmCountry" class="form-label small mb-1"><?= $t('admin.club_import.osm_country') ?></label>
                <select class="form-select form-select-sm" id="ciOsmCountry">
                    <?php foreach ($countryCodes as $code): ?>
                        <option value="<?= $code ?>"><?= $code ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-4">
                <button type="submit" class="btn btn-outline-primary btn-sm" id="ciOsmLoad"><i class="bi bi-cloud-download"></i> <?= $t('admin.club_import.osm_load') ?></button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form id="ciFilterForm" class="row g-2" data-on-submit="return false;">
            <div class="col-12 col-md-3">
                <label for="ciFilterName" class="form-label small mb-1"><?= $t('admin.club_import.col_name') ?></label>
                <input type="text" class="form-control form-control-sm" id="ciFilterName" data-filter="name" autocomplete="off">
            </div>
            <div class="col-6 col-md-2">
                <label for="ciFilterCountry" class="form-label small mb-1"><?= $t('admin.club_import.col_country') ?></label>
                <select class="form-select form-select-sm" id="ciFilterCountry" data-filter="country">
                    <option value=""><?= $t('admin.club_import.filter_all') ?></option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="ciFilterCity" class="form-label small mb-1"><?= $t('admin.club_import.col_city') ?></label>
                <input type="text" class="form-control form-control-sm" id="ciFilterCity" data-filter="city" autocomplete="off">
            </div>
            <div class="col-6 col-md-2">
                <label for="ciFilterKind" class="form-label small mb-1"><?= $t('admin.club_import.col_kind') ?></label>
                <select class="form-select form-select-sm" id="ciFilterKind" data-filter="kind">
                    <option value=""><?= $t('admin.club_import.filter_all') ?></option>
                    <option value="canoe"><?= $t('admin.club_import.kind_canoe') ?></option>
                    <option value="rowing"><?= $t('admin.club_import.kind_rowing') ?></option>
                    <option value="both"><?= $t('admin.club_import.kind_both') ?></option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="ciFilterStatus" class="form-label small mb-1"><?= $t('admin.club_import.col_status') ?></label>
                <select class="form-select form-select-sm" id="ciFilterStatus" data-filter="status">
                    <option value=""><?= $t('admin.lighthouse_import.status_all') ?></option>
                    <option value="open"><?= $t('admin.lighthouse_import.status_open') ?></option>
                    <option value="imported"><?= $t('admin.lighthouse_import.status_imported') ?></option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label for="ciPageSize" class="form-label small mb-1"><?= $t('common.rows_per_page') ?></label>
                <select class="form-select form-select-sm" id="ciPageSize">
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
        <button type="button" class="btn btn-outline-secondary btn-sm" id="ciSelectAllMatches"></button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="ciSelectNone"><?= $t('admin.lighthouse_import.select_none') ?></button>
        <span class="small text-secondary" id="ciSelectedCount"></span>
        <div class="form-check ms-md-auto mb-0">
            <input class="form-check-input" type="checkbox" id="ciPublic" checked>
            <label class="form-check-label small" for="ciPublic"><?= $t('admin.club_import.make_public') ?></label>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="ciImportButton"><i class="bi bi-box-arrow-in-down"></i> <?= $t('admin.club_import.import_selected') ?></button>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th style="width:2.5rem;"><input type="checkbox" class="form-check-input" id="ciTogglePage" title="<?= $t('admin.lighthouse_import.select_page') ?>"></th>
                    <th><?= $t('admin.club_import.col_name') ?></th>
                    <th><?= $t('admin.club_import.col_kind') ?></th>
                    <th><?= $t('admin.club_import.col_country') ?></th>
                    <th><?= $t('admin.club_import.col_city') ?></th>
                    <th><?= $t('admin.club_import.col_website') ?></th>
                    <th><?= $t('admin.club_import.col_status') ?></th>
                </tr>
            </thead>
            <tbody id="ciRows"></tbody>
        </table>
    </div>
    <div class="card-footer d-flex align-items-center justify-content-center gap-3" id="ciPagination"></div>
</div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
