<?php
$pageTitle = $t('admin.shelter_import.title');
$adminActiveNav = 'shelter_import';
$pageScripts = ['/js/admin-shelter-import.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';

$countryCodes = \Ytan\Service\ShelterOsmImportService::SCANDINAVIA;
?>
<p class="text-secondary"><?= $t('admin.shelter_import.hint') ?></p>

<ul class="nav nav-tabs flex-nowrap overflow-x-auto overflow-y-hidden mb-3" id="siTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link text-nowrap px-2 px-sm-3 active" id="siTab-find" data-bs-toggle="tab" data-bs-target="#siPane-find" type="button" role="tab" aria-controls="siPane-find" aria-selected="true"><i class="bi bi-search d-none d-sm-inline"></i> <?= $t('admin.shelter_import.tab_find') ?></button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link text-nowrap px-2 px-sm-3" id="siTab-import" data-bs-toggle="tab" data-bs-target="#siPane-import" type="button" role="tab" aria-controls="siPane-import" aria-selected="false"><i class="bi bi-box-arrow-in-down d-none d-sm-inline"></i> <?= $t('admin.shelter_import.tab_import') ?></button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link text-nowrap px-2 px-sm-3" id="siTab-transfer" data-bs-toggle="tab" data-bs-target="#siPane-transfer" type="button" role="tab" aria-controls="siPane-transfer" aria-selected="false"><i class="bi bi-arrow-left-right d-none d-sm-inline"></i> <?= $t('admin.shelter_import.tab_transfer') ?></button>
    </li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="siPane-find" role="tabpanel" aria-labelledby="siTab-find" tabindex="0">
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6"><?= $t('admin.shelter_import.sources_title') ?></h2>
                <p class="small text-secondary mb-2"><?= $t('admin.shelter_import.sources_hint') ?></p>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <?php foreach (['geofa', 'nvv', 'kv', 'lipas'] as $sourceCode): ?>
                        <button type="button" class="btn btn-outline-primary btn-sm siSourceLoad" data-source="<?= $sourceCode ?>"><i class="bi bi-cloud-download"></i> <?= $t('admin.shelter_import.source_' . $sourceCode) ?></button>
                    <?php endforeach; ?>
                </div>
                <h2 class="h6"><?= $t('admin.shelter_import.water_title') ?></h2>
                <p class="small text-secondary mb-0"><?= $t('admin.shelter_import.water_hint') ?></p>
                <p class="small mb-0" id="siWaterInfo"></p>
            </div>
        </div>


        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6"><?= $t('admin.shelter_import.osm_title') ?></h2>
                <p class="small text-secondary mb-2"><?= $t('admin.shelter_import.osm_hint') ?></p>
                <form id="siOsmForm" class="row g-2 align-items-end" data-on-submit="return false;">
                    <div class="col-6 col-md-3">
                        <label for="siOsmCountry" class="form-label small mb-1"><?= $t('admin.shelter_import.osm_country') ?></label>
                        <select class="form-select form-select-sm" id="siOsmCountry">
                            <?php foreach ($countryCodes as $code): ?>
                                <option value="<?= $code ?>"><?= $code ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <button type="submit" class="btn btn-outline-primary btn-sm" id="siOsmLoad"><i class="bi bi-cloud-download"></i> <?= $t('admin.shelter_import.osm_load') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="siPane-import" role="tabpanel" aria-labelledby="siTab-import" tabindex="0">
        <div class="card mb-3">
            <div class="card-body">
                <form id="siFilterForm" class="row g-2" data-on-submit="return false;">
                    <div class="col-12 col-md-3">
                        <label for="siFilterName" class="form-label small mb-1"><?= $t('admin.shelter_import.col_name') ?></label>
                        <input type="text" class="form-control form-control-sm" id="siFilterName" data-filter="name" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="siFilterCountry" class="form-label small mb-1"><?= $t('admin.shelter_import.col_country') ?></label>
                        <select class="form-select form-select-sm" id="siFilterCountry" data-filter="country">
                            <option value=""><?= $t('admin.shelter_import.filter_all') ?></option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="siFilterSource" class="form-label small mb-1"><?= $t('admin.shelter_import.col_source') ?></label>
                        <select class="form-select form-select-sm" id="siFilterSource" data-filter="source">
                            <option value=""><?= $t('admin.shelter_import.filter_all') ?></option>
                            <?php foreach (['osm' => $t('admin.shelter_import.region_scandinavia'), 'geofa' => 'DK', 'nvv' => 'SE', 'kv' => 'NO', 'lipas' => 'FI'] as $sourceCode => $sourceRegion): ?>
                                <option value="<?= $sourceCode ?>"><?= $t('admin.shelter_import.source_short_' . $sourceCode) ?> (<?= $sourceRegion ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="siFilterKind" class="form-label small mb-1"><?= $t('admin.shelter_import.col_kind') ?></label>
                        <select class="form-select form-select-sm" id="siFilterKind" data-filter="kind">
                            <option value=""><?= $t('admin.shelter_import.filter_all') ?></option>
                            <option value="shelter"><?= $t('admin.shelter_import.kind_shelter') ?></option>
                            <option value="hut"><?= $t('admin.shelter_import.kind_hut') ?></option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="siFilterWater" class="form-label small mb-1"><?= $t('admin.shelter_import.col_water') ?></label>
                        <select class="form-select form-select-sm" id="siFilterWater" data-filter="water">
                            <option value=""><?= $t('admin.shelter_import.filter_all') ?></option>
                            <option value="checked"><?= $t('admin.shelter_import.water_checked') ?></option>
                            <option value="unchecked"><?= $t('admin.shelter_import.water_unchecked') ?></option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="siFilterMaxSea" class="form-label small mb-1"><?= $t('admin.shelter_import.filter_max_sea') ?></label>
                        <input type="number" min="1" step="1" class="form-control form-control-sm" id="siFilterMaxSea" data-filter="max_sea" placeholder="m" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="siFilterMaxRiver" class="form-label small mb-1"><?= $t('admin.shelter_import.filter_max_river') ?></label>
                        <input type="number" min="1" step="1" class="form-control form-control-sm" id="siFilterMaxRiver" data-filter="max_river" placeholder="m" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="siFilterMaxLake" class="form-label small mb-1"><?= $t('admin.shelter_import.filter_max_lake') ?></label>
                        <input type="number" min="1" step="1" class="form-control form-control-sm" id="siFilterMaxLake" data-filter="max_lake" placeholder="m" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="siFilterMaxAny" class="form-label small mb-1"><?= $t('admin.shelter_import.filter_max_any') ?></label>
                        <input type="number" min="1" step="1" class="form-control form-control-sm" id="siFilterMaxAny" data-filter="max_any" placeholder="m" autocomplete="off">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="siFilterStatus" class="form-label small mb-1"><?= $t('admin.shelter_import.col_status') ?></label>
                        <select class="form-select form-select-sm" id="siFilterStatus" data-filter="status">
                            <option value=""><?= $t('admin.lighthouse_import.status_all') ?></option>
                            <option value="open"><?= $t('admin.lighthouse_import.status_open') ?></option>
                            <option value="imported"><?= $t('admin.lighthouse_import.status_imported') ?></option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="siFilterDuplicate" class="form-label small mb-1"><?= $t('admin.shelter_import.col_duplicate') ?></label>
                        <select class="form-select form-select-sm" id="siFilterDuplicate" data-filter="duplicate">
                            <option value=""><?= $t('admin.shelter_import.filter_all') ?></option>
                            <option value="no"><?= $t('admin.shelter_import.duplicate_no') ?></option>
                            <option value="yes"><?= $t('admin.shelter_import.duplicate_yes') ?></option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="siPageSize" class="form-label small mb-1"><?= $t('common.rows_per_page') ?></label>
                        <select class="form-select form-select-sm" id="siPageSize">
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
                <button type="button" class="btn btn-outline-secondary btn-sm" id="siSelectAllMatches"></button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="siSelectNone"><?= $t('admin.lighthouse_import.select_none') ?></button>
                <span class="small text-secondary" id="siSelectedCount"></span>
                <div class="form-check ms-md-auto mb-0">
                    <input class="form-check-input" type="checkbox" id="siPublic" checked>
                    <label class="form-check-label small" for="siPublic"><?= $t('admin.shelter_import.make_public') ?></label>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="siImportButton"><i class="bi bi-box-arrow-in-down"></i> <?= $t('admin.shelter_import.import_selected') ?></button>
            </div>
        </div>


        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:2.5rem;"><input type="checkbox" class="form-check-input" id="siTogglePage" title="<?= $t('admin.lighthouse_import.select_page') ?>"></th>
                            <th><?= $t('admin.shelter_import.col_name') ?></th>
                            <th><?= $t('admin.shelter_import.col_kind') ?></th>
                            <th><?= $t('admin.shelter_import.col_country') ?></th>
                            <th><?= $t('admin.shelter_import.col_water') ?></th>
                            <th><?= $t('admin.shelter_import.col_source') ?></th>
                            <th><?= $t('admin.shelter_import.col_link') ?></th>
                            <th><?= $t('admin.shelter_import.col_duplicate') ?></th>
                            <th><?= $t('admin.shelter_import.col_status') ?></th>
                        </tr>
                    </thead>
                    <tbody id="siRows"></tbody>
                </table>
            </div>
            <div class="card-footer d-flex align-items-center justify-content-center gap-3" id="siPagination"></div>
        </div>
    </div>
    <div class="tab-pane fade" id="siPane-transfer" role="tabpanel" aria-labelledby="siTab-transfer" tabindex="0">
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-2">
                <span class="fw-semibold me-2"><?= $t('admin.shelter_import.transfer_title') ?></span>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="siExportButton"><i class="bi bi-box-arrow-up"></i> <?= $t('admin.shelter_import.export') ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="siFileButton"><i class="bi bi-box-arrow-in-down"></i> <?= $t('admin.shelter_import.load_file') ?></button>
                <input type="file" id="siFileInput" accept=".json,application/json" hidden>
                <span class="small text-secondary w-100"><?= $t('admin.shelter_import.transfer_hint') ?></span>
            </div>
        </div>
    </div>
</div>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
