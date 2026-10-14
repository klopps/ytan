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
        <h2 class="h6"><?= $t('admin.lighthouse_import.nga_title') ?></h2>
        <p class="small text-secondary mb-2"><?= $t('admin.lighthouse_import.nga_hint') ?></p>
        <form id="liNgaForm" class="row g-2 align-items-end" onsubmit="return false;">
            <div class="col-12 col-md-8">
                <label for="liNgaVolume" class="form-label small mb-1"><?= $t('admin.lighthouse_import.nga_volume') ?></label>
                <select class="form-select form-select-sm" id="liNgaVolume">
                    <option value="110">Pub. 110 - <?= $t('admin.lighthouse_import.nga_pub110') ?></option>
                    <option value="111">Pub. 111 - <?= $t('admin.lighthouse_import.nga_pub111') ?></option>
                    <option value="112">Pub. 112 - <?= $t('admin.lighthouse_import.nga_pub112') ?></option>
                    <option value="113">Pub. 113 - <?= $t('admin.lighthouse_import.nga_pub113') ?></option>
                    <option value="114">Pub. 114 - <?= $t('admin.lighthouse_import.nga_pub114') ?></option>
                    <option value="115">Pub. 115 - <?= $t('admin.lighthouse_import.nga_pub115') ?></option>
                    <option value="116" selected>Pub. 116 - <?= $t('admin.lighthouse_import.nga_pub116') ?></option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-outline-primary btn-sm" id="liNgaLoad"><i class="bi bi-cloud-download"></i> <?= $t('admin.lighthouse_import.nga_load') ?></button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6"><?= $t('admin.lighthouse_import.osm_title') ?></h2>
        <p class="small text-secondary mb-2"><?= $t('admin.lighthouse_import.osm_hint') ?></p>
        <form id="liOsmForm" class="row g-2 align-items-end" onsubmit="return false;">
            <div class="col-12 col-md-4">
                <label for="liOsmPreset" class="form-label small mb-1"><?= $t('admin.lighthouse_import.osm_preset') ?></label>
                <select class="form-select form-select-sm" id="liOsmPreset">
                    <option value=""><?= $t('admin.lighthouse_import.osm_preset_choose') ?></option>
                    <option value="Bornholm|54.95|14.6|55.35|15.2">Bornholm</option>
                    <option value="Kieler Bucht|53.9|9.8|54.9|11.5">Kieler Bucht</option>
                    <option value="Fünen und Seeland|54.5|9.5|56.2|12.8">Fünen und Seeland</option>
                    <option value="Öresund|55.2|12.3|56.2|13.0">Öresund</option>
                    <option value="Kattegat|55.5|10.0|58.0|12.8">Kattegat</option>
                    <option value="Bohuslän|57.5|10.8|59.2|11.9">Bohuslän</option>
                    <option value="Gotland|56.8|18.0|58.0|19.4">Gotland</option>
                </select>
            </div>
            <div class="col-12 col-md-8">
                <label for="liOsmLabel" class="form-label small mb-1"><?= $t('admin.lighthouse_import.osm_label') ?></label>
                <input type="text" class="form-control form-control-sm" id="liOsmLabel" maxlength="100" autocomplete="off">
            </div>
            <div class="col-6 col-md-2">
                <label for="liOsmSouth" class="form-label small mb-1"><?= $t('admin.lighthouse_import.osm_south') ?></label>
                <input type="number" class="form-control form-control-sm" id="liOsmSouth" min="-90" max="90" step="any">
            </div>
            <div class="col-6 col-md-2">
                <label for="liOsmWest" class="form-label small mb-1"><?= $t('admin.lighthouse_import.osm_west') ?></label>
                <input type="number" class="form-control form-control-sm" id="liOsmWest" min="-180" max="180" step="any">
            </div>
            <div class="col-6 col-md-2">
                <label for="liOsmNorth" class="form-label small mb-1"><?= $t('admin.lighthouse_import.osm_north') ?></label>
                <input type="number" class="form-control form-control-sm" id="liOsmNorth" min="-90" max="90" step="any">
            </div>
            <div class="col-6 col-md-2">
                <label for="liOsmEast" class="form-label small mb-1"><?= $t('admin.lighthouse_import.osm_east') ?></label>
                <input type="number" class="form-control form-control-sm" id="liOsmEast" min="-180" max="180" step="any">
            </div>
            <div class="col-12 col-md-4">
                <button type="submit" class="btn btn-outline-primary btn-sm" id="liOsmLoad"><i class="bi bi-cloud-download"></i> <?= $t('admin.lighthouse_import.osm_load') ?></button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3" id="liAreaCard">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="liAreaToggle" data-bs-toggle="collapse" data-bs-target="#liAreaPanel" aria-expanded="false" aria-controls="liAreaPanel"><i class="bi bi-bounding-box"></i> <?= $t('admin.lighthouse_import.area_title') ?></button>
            <span class="small text-secondary" id="liAreaStatus"></span>
        </div>
        <div class="collapse mt-3" id="liAreaPanel">
            <p class="small text-secondary mb-2"><?= $t('admin.lighthouse_import.area_hint') ?></p>
            <p class="small text-danger mb-2" id="liAreaNoKey" hidden><?= $t('admin.lighthouse_import.area_no_key') ?></p>
            <div class="d-flex flex-wrap gap-2 mb-2" id="liAreaButtons">
                <button type="button" class="btn btn-outline-primary btn-sm" id="liAreaDraw"><i class="bi bi-pencil"></i> <?= $t('admin.lighthouse_import.area_draw') ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="liAreaUndo"><i class="bi bi-arrow-counterclockwise"></i> <?= $t('admin.lighthouse_import.area_undo') ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="liAreaFinish"><i class="bi bi-check2"></i> <?= $t('admin.lighthouse_import.area_finish') ?></button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="liAreaClear"><i class="bi bi-trash"></i> <?= $t('admin.lighthouse_import.area_clear') ?></button>
            </div>
            <div id="liAreaMap" class="border rounded" style="height:55vh;min-height:280px;"></div>
        </div>
        <script>window.YTAN_MAPS_API_KEY = <?= json_encode($mapsApiKey ?? '') ?>;</script>
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
                <label for="liFilterSubregion" class="form-label small mb-1"><?= $t('admin.lighthouse_import.col_subregion') ?></label>
                <input type="text" class="form-control form-control-sm" id="liFilterSubregion" data-filter="subregion" list="liSubregionList" autocomplete="off">
                <datalist id="liSubregionList"></datalist>
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
                    <option value="ngaapi"><?= $t('admin.lighthouse_import.source_ngaapi') ?></option>
                    <option value="osm"><?= $t('admin.lighthouse_import.source_osm') ?></option>
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
                <input type="number" class="form-control form-control-sm" style="width:5rem;" id="liRangeW" value="3" min="0.1" max="50" step="any"></label>
            <label class="small d-flex align-items-center gap-1" for="liRangeR"><?= $t('admin.lighthouse_import.colour_red') ?>
                <input type="number" class="form-control form-control-sm" style="width:5rem;" id="liRangeR" value="2" min="0.1" max="50" step="any"></label>
            <label class="small d-flex align-items-center gap-1" for="liRangeG"><?= $t('admin.lighthouse_import.colour_green') ?>
                <input type="number" class="form-control form-control-sm" style="width:5rem;" id="liRangeG" value="2" min="0.1" max="50" step="any"></label>
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
