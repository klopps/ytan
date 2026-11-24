<?php
/*
 * /admin/water - the water index (storage/water/water-index.bin) as a map
 * overlay for checking it, and the admin's corrections of it
 * (WaterIndexAdminController, WaterCorrections): delete lines in an area,
 * reverse the water side in an area, add a line. public/js/admin-water.js.
 */
$pageTitle = $t('admin.water.title');
$adminActiveNav = 'water';
$pageScripts = ['/js/admin-water.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
// kind => label (keys literal for TranslationUsageScanner)
$kinds = [
    'sea' => $t('admin.water.kind.sea'),
    'lake' => $t('admin.water.kind.lake'),
    'river' => $t('admin.water.kind.river'),
    'canal' => $t('admin.water.kind.canal'),
];
?>
<p class="text-secondary"><?= $t('admin.water.hint') ?></p>

<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-3 align-items-center small">
            <?php foreach ($kinds as $kind => $label): ?>
            <div class="form-check form-check-inline m-0">
                <input class="form-check-input" type="checkbox" id="wxShow-<?= $kind ?>" checked data-on-change="wxRedraw()">
                <label class="form-check-label" for="wxShow-<?= $kind ?>"><span class="wx-swatch wx-<?= $kind ?>"></span> <?= $label ?></label>
            </div>
            <?php endforeach; ?>
            <span><span class="wx-swatch wx-own"></span> <?= $t('admin.water.own_line') ?></span>
            <div class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="wxRaw" data-on-change="wxReload()">
                <label class="form-check-label" for="wxRaw"><?= $t('admin.water.show_raw') ?></label>
            </div>
            <div class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="wxShowRemoved" checked data-on-change="wxRedraw()">
                <label class="form-check-label" for="wxShowRemoved"><?= $t('admin.water.show_removed') ?></label>
            </div>
        </div>
        <div class="small text-secondary mt-1" id="wxStatus"></div>
    </div>
</div>

<div class="alert alert-warning" id="wxNoKey" hidden><?= $t('admin.water.no_key') ?></div>
<div id="wxMap" class="mb-3 rounded border"></div>

<div class="card mb-3">
    <div class="card-header"><h2 class="card-title h6 m-0"><?= $t('admin.water.tools_title') ?></h2></div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-2" id="wxToolButtons">
            <button type="button" class="btn btn-outline-danger btn-sm" id="wxToolDelete" data-on-click="wxStartTool('delete')"><i class="bi bi-eraser"></i> <?= $t('admin.water.tool.delete') ?></button>
            <button type="button" class="btn btn-outline-primary btn-sm" id="wxToolFlip" data-on-click="wxStartTool('flip')"><i class="bi bi-arrow-left-right"></i> <?= $t('admin.water.tool.flip') ?></button>
            <button type="button" class="btn btn-outline-success btn-sm" id="wxToolAdd" data-on-click="wxStartTool('add')"><i class="bi bi-pencil"></i> <?= $t('admin.water.tool.add') ?></button>
        </div>
        <div id="wxDrawPanel" hidden>
            <p class="fw-semibold mb-1" id="wxEditing" hidden><i class="bi bi-pencil"></i> <?= $t('admin.water.editing') ?></p>
            <p class="small text-secondary mb-2" id="wxDrawHint"></p>
            <div class="d-flex flex-wrap gap-3 mb-2 small">
                <?php foreach ($kinds as $kind => $label): ?>
                <div class="form-check form-check-inline m-0">
                    <input class="form-check-input" type="checkbox" id="wxKind-<?= $kind ?>" data-on-change="wxToolKindChanged(this)">
                    <label class="form-check-label" for="wxKind-<?= $kind ?>"><?= $label ?></label>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="d-flex flex-wrap gap-3 mb-2 small" id="wxRingSide" hidden>
                <span class="text-secondary"><?= $t('admin.water.ring_label') ?></span>
                <div class="form-check form-check-inline m-0">
                    <input class="form-check-input" type="radio" name="wxRingSide" id="wxRingOutside" value="outside" checked data-on-change="wxRingSideChanged()">
                    <label class="form-check-label" for="wxRingOutside"><?= $t('admin.water.ring_outside') ?></label>
                </div>
                <div class="form-check form-check-inline m-0">
                    <input class="form-check-input" type="radio" name="wxRingSide" id="wxRingInside" value="inside" data-on-change="wxRingSideChanged()">
                    <label class="form-check-label" for="wxRingInside"><?= $t('admin.water.ring_inside') ?></label>
                </div>
            </div>
            <input type="text" class="form-control form-control-sm mb-2" id="wxNote" maxlength="500" placeholder="<?= $t('admin.water.note_placeholder') ?>">
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-secondary btn-sm" id="wxUndo" data-on-click="wxUndo()"><i class="bi bi-arrow-counterclockwise"></i> <?= $t('admin.water.undo') ?></button>
                <button type="button" class="btn btn-secondary btn-sm" id="wxReverse" data-on-click="wxReverse()"><i class="bi bi-arrow-repeat"></i> <?= $t('admin.water.reverse') ?></button>
                <button type="button" class="btn btn-secondary btn-sm" id="wxClose" data-on-click="wxCloseLine()"><i class="bi bi-circle"></i> <?= $t('admin.water.close_line') ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="wxSave" data-on-click="wxSave()"><i class="bi bi-check-lg"></i> <?= $t('admin.water.save') ?></button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-on-click="wxCancelTool()"><?= $t('common.cancel') ?></button>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <h2 class="card-title h6 m-0"><?= $t('admin.water.corrections_title') ?></h2>
        <div class="ms-auto d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-on-click="wxExport()"><i class="bi bi-download"></i> <?= $t('admin.water.export') ?></button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-on-click="clickElementById('wxImportFile')"><i class="bi bi-upload"></i> <?= $t('admin.water.import') ?></button>
            <input type="file" id="wxImportFile" accept=".json,application/json" hidden data-on-change="wxImport(this)">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>#</th>
                    <th><?= $t('admin.water.col_type') ?></th>
                    <th><?= $t('admin.water.col_kinds') ?></th>
                    <th><?= $t('admin.water.col_note') ?></th>
                    <th><?= $t('admin.water.col_by') ?></th>
                    <th></th>
                </tr></thead>
                <tbody id="wxCorrections"></tbody>
            </table>
        </div>
    </div>
</div>

<style>
    #wxMap { height: 60vh; min-height: 320px; }
    .wx-swatch { display: inline-block; width: 14px; height: 4px; vertical-align: middle; border-radius: 2px; }
    .wx-sea { background: #1565c0; }
    .wx-lake { background: #00acc1; }
    .wx-river { background: #2e7d32; }
    .wx-canal { background: #8e24aa; }
    .wx-own { background: #ff00ff; }
    /* InfoWindow colours come from admin.css (follow the admin theme) */
    /* No min-width: Google caps the bubble by the map's width (207px on a phone) -
       the buttons wrap instead (flex-wrap in admin-water.js). */
    .wx-info { font-size: 0.875rem; line-height: 1.4; }
    .wx-info-coords { color: var(--bs-secondary-color); }
    #wxCorrections tr.wx-selected { background: rgba(191, 64, 159, 0.15); }
</style>
<script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?>>window.YTAN_MAPS_API_KEY = <?= json_encode($mapsApiKey ?? '') ?>;</script>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
