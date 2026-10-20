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
        <form id="liNgaForm" class="row g-2 align-items-end" data-on-submit="return false;">
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
        <form id="liOsmForm" class="row g-2 align-items-end" data-on-submit="return false;">
            <div class="col-12 col-md-4">
                <label for="liOsmPreset" class="form-label small mb-1"><?= $t('admin.lighthouse_import.osm_preset') ?></label>
                <select class="form-select form-select-sm" id="liOsmPreset">
                    <option value=""><?= $t('admin.lighthouse_import.osm_preset_choose') ?></option>
                    <option value="Deutschland - Ostfriesland und Ems|53.0|6.6|53.9|8.2">Deutschland - Ostfriesland und Ems</option>
                    <option value="Deutschland - Jade, Weser und Helgoland|53.2|7.7|54.4|9.0">Deutschland - Jade, Weser und Helgoland</option>
                    <option value="Deutschland - Elbe bis Hamburg|53.4|8.6|54.2|10.3">Deutschland - Elbe bis Hamburg</option>
                    <option value="Deutschland - Nordfriesland und Sylt|54.0|8.2|55.1|9.4">Deutschland - Nordfriesland und Sylt</option>
                    <option value="Deutschland - Flensburger Förde und Schlei|54.4|9.4|54.95|10.0">Deutschland - Flensburger Förde und Schlei</option>
                    <option value="Deutschland - Kieler Bucht|53.9|9.8|54.9|11.5">Deutschland - Kieler Bucht</option>
                    <option value="Deutschland - Lübecker Bucht und Mecklenburg|53.85|11.0|54.4|12.5">Deutschland - Lübecker Bucht und Mecklenburg</option>
                    <option value="Deutschland - Rügen und Vorpommern|53.8|12.3|54.8|14.3">Deutschland - Rügen und Vorpommern</option>
                    <option value="Dänemark - Jütland Westküste|54.9|8.0|57.2|8.9">Dänemark - Jütland Westküste</option>
                    <option value="Dänemark - Limfjord und Skagen|56.5|8.1|57.8|10.7">Dänemark - Limfjord und Skagen</option>
                    <option value="Dänemark - Ostjütland|55.4|9.5|57.6|10.9">Dänemark - Ostjütland</option>
                    <option value="Dänemark - Fünen und Seeland|54.5|9.5|56.2|12.8">Dänemark - Fünen und Seeland</option>
                    <option value="Dänemark - Lolland, Falster und Møn|54.5|10.8|55.2|12.7">Dänemark - Lolland, Falster und Møn</option>
                    <option value="Dänemark - Bornholm|54.95|14.6|55.35|15.2">Dänemark - Bornholm</option>
                    <option value="Schweden - Bohuslän|57.5|10.8|59.2|11.9">Schweden - Bohuslän</option>
                    <option value="Schweden - Göteborg und Halland|56.3|11.5|57.7|12.9">Schweden - Göteborg und Halland</option>
                    <option value="Schweden - Öresund|55.2|12.3|56.2|13.0">Schweden - Öresund</option>
                    <option value="Schweden - Südküste (Skåne, Blekinge)|55.2|13.0|56.3|15.8">Schweden - Südküste (Skåne, Blekinge)</option>
                    <option value="Schweden - Småland und Öland|56.0|15.5|57.6|17.2">Schweden - Småland und Öland</option>
                    <option value="Schweden - Gotland|56.8|18.0|58.5|19.4">Schweden - Gotland</option>
                    <option value="Schweden - Stockholmer Schären|58.5|16.2|60.0|19.5">Schweden - Stockholmer Schären</option>
                    <option value="Schweden - Uppland bis Gästrikland|59.8|17.0|61.5|19.2">Schweden - Uppland bis Gästrikland</option>
                    <option value="Schweden - Höga Kusten|61.2|16.5|63.5|19.5">Schweden - Höga Kusten</option>
                    <option value="Schweden - Västerbotten und Norrbotten|63.3|17.5|66.0|24.3">Schweden - Västerbotten und Norrbotten</option>
                    <option value="Finnland - Åland|59.8|19.3|60.6|21.2">Finnland - Åland</option>
                    <option value="Finnland - Schärenmeer Turku|59.7|21.0|60.7|23.0">Finnland - Schärenmeer Turku</option>
                    <option value="Finnland - Finnischer Meerbusen|59.9|23.0|60.7|28.3">Finnland - Finnischer Meerbusen</option>
                    <option value="Finnland - Bottenmeer Süd (Rauma bis Vaasa)|60.9|20.9|63.3|22.8">Finnland - Bottenmeer Süd (Rauma bis Vaasa)</option>
                    <option value="Finnland - Kvarken und Bottenmeer Nord|62.8|20.8|65.9|25.8">Finnland - Kvarken und Bottenmeer Nord</option>
                    <option value="Norwegen - Oslofjord und Østfold|58.8|10.0|59.6|11.3">Norwegen - Oslofjord und Østfold</option>
                    <option value="Norwegen - Vestfold und Telemark|58.6|9.0|59.5|10.6">Norwegen - Vestfold und Telemark</option>
                    <option value="Norwegen - Südküste (Kristiansand, Lindesnes)|57.9|6.8|58.9|9.3">Norwegen - Südküste (Kristiansand, Lindesnes)</option>
                    <option value="Norwegen - Rogaland|58.0|5.4|59.6|6.9">Norwegen - Rogaland</option>
                    <option value="Norwegen - Hordaland (Bergen)|59.3|4.8|61.0|6.4">Norwegen - Hordaland (Bergen)</option>
                    <option value="Norwegen - Sogn og Fjordane|60.9|4.8|62.2|7.6">Norwegen - Sogn og Fjordane</option>
                    <option value="Norwegen - Møre og Romsdal|62.0|5.0|63.3|9.2">Norwegen - Møre og Romsdal</option>
                    <option value="Norwegen - Trøndelag|63.0|8.0|65.0|12.2">Norwegen - Trøndelag</option>
                    <option value="Norwegen - Helgeland|64.8|10.5|66.8|14.2">Norwegen - Helgeland</option>
                    <option value="Norwegen - Nordland (Salten bis Narvik)|66.5|12.5|68.6|17.8">Norwegen - Nordland (Salten bis Narvik)</option>
                    <option value="Norwegen - Lofoten und Vesterålen|67.3|12.0|69.4|16.6">Norwegen - Lofoten und Vesterålen</option>
                    <option value="Norwegen - Troms|68.6|16.0|70.4|22.0">Norwegen - Troms</option>
                    <option value="Norwegen - Finnmark West|69.6|21.0|71.3|26.5">Norwegen - Finnmark West</option>
                    <option value="Norwegen - Finnmark Ost|69.5|25.0|71.2|31.3">Norwegen - Finnmark Ost</option>
                    <option value="Polen - Westpommern|53.8|14.1|54.5|16.6">Polen - Westpommern</option>
                    <option value="Polen - Pommern (Darłowo bis Łeba)|54.3|16.0|54.9|17.8">Polen - Pommern (Darłowo bis Łeba)</option>
                    <option value="Polen - Danziger Bucht und Hela|54.2|17.5|54.9|19.5">Polen - Danziger Bucht und Hela</option>
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
        <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?>>window.YTAN_MAPS_API_KEY = <?= json_encode($mapsApiKey ?? '') ?>;</script>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form id="liFilterForm" class="row g-2" data-on-submit="return false;">
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
