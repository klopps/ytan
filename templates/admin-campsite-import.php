<?php
$pageTitle = $t('admin.campsite_import.title');
$adminActiveNav = 'campsite_import';
$pageScripts = ['/js/admin-campsite-import.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';

$countryCodes = \Ytan\Service\ClubOsmImportService::EUROPE;
sort($countryCodes);
?>
<p class="text-secondary"><?= $t('admin.campsite_import.hint') ?></p>

<ul class="nav nav-tabs flex-nowrap overflow-x-auto overflow-y-hidden mb-3" id="ciTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link text-nowrap px-2 px-sm-3 active" id="ciTab-find" data-bs-toggle="tab" data-bs-target="#ciPane-find" type="button" role="tab" aria-controls="ciPane-find" aria-selected="true"><i class="bi bi-search d-none d-sm-inline"></i> <?= $t('admin.campsite_import.tab_find') ?></button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link text-nowrap px-2 px-sm-3" id="ciTab-import" data-bs-toggle="tab" data-bs-target="#ciPane-import" type="button" role="tab" aria-controls="ciPane-import" aria-selected="false"><i class="bi bi-box-arrow-in-down d-none d-sm-inline"></i> <?= $t('admin.campsite_import.tab_import') ?></button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link text-nowrap px-2 px-sm-3" id="ciTab-transfer" data-bs-toggle="tab" data-bs-target="#ciPane-transfer" type="button" role="tab" aria-controls="ciPane-transfer" aria-selected="false"><i class="bi bi-arrow-left-right d-none d-sm-inline"></i> <?= $t('admin.shelter_import.tab_transfer') ?></button>
    </li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="ciPane-find" role="tabpanel" aria-labelledby="ciTab-find" tabindex="0">
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6"><?= $t('admin.campsite_import.osm_title') ?></h2>
                <p class="small text-secondary mb-2"><?= $t('admin.campsite_import.osm_hint') ?></p>
                <form id="ciOsmForm" class="row g-2 align-items-end" data-on-submit="return false;">
                    <div class="col-6 col-md-3">
                        <label for="ciOsmCountry" class="form-label small mb-1"><?= $t('admin.shelter_import.osm_country') ?></label>
                        <select class="form-select form-select-sm" id="ciOsmCountry">
                            <?php foreach ($countryCodes as $code): ?>
                                <option value="<?= $code ?>"<?= $code === 'DK' ? ' selected' : '' ?>><?= $code ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <button type="submit" class="btn btn-outline-primary btn-sm" id="ciOsmLoad"><i class="bi bi-cloud-download"></i> <?= $t('admin.campsite_import.osm_load') ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6"><?= $t('admin.shelter_import.water_title') ?></h2>
                <p class="small text-secondary mb-0"><?= $t('admin.campsite_import.water_hint') ?></p>
                <p class="small mb-0" id="ciWaterInfo"></p>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="ciPane-import" role="tabpanel" aria-labelledby="ciTab-import" tabindex="0">
        <div class="card mb-3">
            <div class="card-body">
                <form id="ciFilterForm" class="row g-2" data-on-submit="return false;">
                    <div class="col-12 col-md-3">
                        <label for="ciFilterName" class="form-label small mb-1"><?= $t('admin.shelter_import.col_name') ?></label>
                        <input type="text" class="form-control form-control-sm" id="ciFilterName" data-filter="name" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="ciFilterPlace" class="form-label small mb-1"><?= $t('admin.campsite_import.col_place') ?></label>
                        <input type="text" class="form-control form-control-sm" id="ciFilterPlace" data-filter="place" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="ciFilterCountry" class="form-label small mb-1"><?= $t('admin.shelter_import.col_country') ?></label>
                        <select class="form-select form-select-sm" id="ciFilterCountry" data-filter="country">
                            <option value=""><?= $t('admin.shelter_import.filter_all') ?></option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="ciFilterCategory" class="form-label small mb-1"><?= $t('admin.campsite_import.col_category') ?></label>
                        <select class="form-select form-select-sm" id="ciFilterCategory" data-filter="category">
                            <option value=""><?= $t('admin.shelter_import.filter_all') ?></option>
                            <option value="campsite"><?= $t('admin.campsite_import.category_campsite') ?></option>
                            <option value="basic"><?= $t('admin.campsite_import.category_basic') ?></option>
                        </select>
                    </div>
                    <?php foreach (['tents', 'caravans', 'cabins'] as $flag): ?>
                        <div class="col-6 col-md-1">
                            <label for="ciFilter-<?= $flag ?>" class="form-label small mb-1"><?= $t('admin.campsite_import.flag_' . $flag) ?></label>
                            <select class="form-select form-select-sm" id="ciFilter-<?= $flag ?>" data-filter="<?= $flag ?>">
                                <option value=""><?= $t('admin.shelter_import.filter_all') ?></option>
                                <option value="yes"><?= $t('admin.campsite_import.yes') ?></option>
                                <option value="no"><?= $t('admin.campsite_import.no') ?></option>
                            </select>
                        </div>
                    <?php endforeach; ?>
                    <div class="col-6 col-md-2">
                        <label for="ciFilterWater" class="form-label small mb-1"><?= $t('admin.shelter_import.col_water') ?></label>
                        <select class="form-select form-select-sm" id="ciFilterWater" data-filter="water">
                            <option value=""><?= $t('admin.shelter_import.filter_all') ?></option>
                            <option value="checked"><?= $t('admin.shelter_import.water_checked') ?></option>
                            <option value="unchecked"><?= $t('admin.shelter_import.water_unchecked') ?></option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="ciFilterMaxSea" class="form-label small mb-1"><?= $t('admin.shelter_import.filter_max_sea') ?></label>
                        <input type="number" min="1" step="1" class="form-control form-control-sm" id="ciFilterMaxSea" data-filter="max_sea" placeholder="m" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="ciFilterMaxRiver" class="form-label small mb-1"><?= $t('admin.shelter_import.filter_max_river') ?></label>
                        <input type="number" min="1" step="1" class="form-control form-control-sm" id="ciFilterMaxRiver" data-filter="max_river" placeholder="m" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="ciFilterMaxLake" class="form-label small mb-1"><?= $t('admin.shelter_import.filter_max_lake') ?></label>
                        <input type="number" min="1" step="1" class="form-control form-control-sm" id="ciFilterMaxLake" data-filter="max_lake" placeholder="m" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="ciFilterMaxAny" class="form-label small mb-1"><?= $t('admin.shelter_import.filter_max_any') ?></label>
                        <input type="number" min="1" step="1" class="form-control form-control-sm" id="ciFilterMaxAny" data-filter="max_any" placeholder="m" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="ciFilterStatus" class="form-label small mb-1"><?= $t('admin.shelter_import.col_status') ?></label>
                        <select class="form-select form-select-sm" id="ciFilterStatus" data-filter="status">
                            <option value=""><?= $t('admin.lighthouse_import.status_all') ?></option>
                            <option value="open"><?= $t('admin.lighthouse_import.status_open') ?></option>
                            <option value="imported"><?= $t('admin.lighthouse_import.status_imported') ?></option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="ciFilterDuplicate" class="form-label small mb-1"><?= $t('admin.shelter_import.col_duplicate') ?></label>
                        <select class="form-select form-select-sm" id="ciFilterDuplicate" data-filter="duplicate">
                            <option value=""><?= $t('admin.shelter_import.filter_all') ?></option>
                            <option value="no"><?= $t('admin.campsite_import.duplicate_no') ?></option>
                            <option value="yes"><?= $t('admin.campsite_import.duplicate_yes') ?></option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
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
                    <label class="form-check-label small" for="ciPublic"><?= $t('admin.shelter_import.make_public') ?></label>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="ciImportButton"><i class="bi bi-box-arrow-in-down"></i> <?= $t('admin.shelter_import.import_selected') ?></button>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:2.5rem;"><input type="checkbox" class="form-check-input" id="ciTogglePage" title="<?= $t('admin.lighthouse_import.select_page') ?>"></th>
                            <th><?= $t('admin.shelter_import.col_name') ?></th>
                            <th><?= $t('admin.campsite_import.col_category') ?></th>
                            <th><?= $t('admin.campsite_import.col_place') ?></th>
                            <th><?= $t('admin.shelter_import.col_country') ?></th>
                            <th><?= $t('admin.shelter_import.col_water') ?></th>
                            <th><?= $t('admin.campsite_import.col_allowed') ?></th>
                            <th><?= $t('admin.campsite_import.col_link') ?></th>
                            <th><?= $t('admin.shelter_import.col_duplicate') ?></th>
                            <th><?= $t('admin.shelter_import.col_status') ?></th>
                        </tr>
                    </thead>
                    <tbody id="ciRows"></tbody>
                </table>
            </div>
            <div class="card-footer d-flex align-items-center justify-content-center gap-3" id="ciPagination"></div>
        </div>
    </div>
    <div class="tab-pane fade" id="ciPane-transfer" role="tabpanel" aria-labelledby="ciTab-transfer" tabindex="0">
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-2">
                <span class="fw-semibold me-2"><?= $t('admin.shelter_import.transfer_title') ?></span>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="ciExportButton"><i class="bi bi-box-arrow-up"></i> <?= $t('admin.shelter_import.export') ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="ciFileButton"><i class="bi bi-box-arrow-in-down"></i> <?= $t('admin.shelter_import.load_file') ?></button>
                <input type="file" id="ciFileInput" accept=".json,application/json" hidden>
                <span class="small text-secondary w-100"><?= $t('admin.shelter_import.transfer_hint') ?></span>
            </div>
        </div>
    </div>
</div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
