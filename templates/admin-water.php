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

<!-- Tools and drawing panel: moved onto the map as a map control by wxInit(), so they stay
     next to the drawing and visible in Google's full-screen mode. -->
<div id="wxTools" class="wx-tools" hidden>
    <!-- the bar: 64 px wide, symbol + short text; the full name is the tooltip -->
    <div class="wx-bar">
        <div id="wxToolButtons">
            <button type="button" class="wx-btn wx-tool-delete" id="wxToolDelete" title="<?= $t('admin.water.tool.delete') ?>" data-on-click="wxStartTool('delete')"><i class="bi bi-eraser"></i><span><?= $t('admin.water.tb.delete') ?></span></button>
            <button type="button" class="wx-btn wx-tool-flip" id="wxToolFlip" title="<?= $t('admin.water.tool.flip') ?>" data-on-click="wxStartTool('flip')"><i class="bi bi-arrow-left-right"></i><span><?= $t('admin.water.tb.flip') ?></span></button>
            <button type="button" class="wx-btn wx-tool-add" id="wxToolAdd" title="<?= $t('admin.water.tool.add') ?>" data-on-click="wxStartTool('add')"><i class="bi bi-pencil"></i><span><?= $t('admin.water.tb.add') ?></span></button>
        </div>
        <div id="wxDrawPanel" hidden>
            <!-- the active tool; a click cancels it -->
            <button type="button" class="wx-btn wx-active" id="wxToolTitle" data-on-click="wxCancelTool()"><i id="wxToolTitleIcon" class="bi bi-eraser"></i><span id="wxToolTitleText"></span></button>
            <div class="wx-kinds">
                <?php foreach ($kinds as $kind => $label): ?>
                <label class="wx-kind" style="--wx-c: var(--wx-<?= $kind ?>)" title="<?= $label ?>">
                    <input type="checkbox" id="wxKind-<?= $kind ?>" data-on-change="wxToolKindChanged(this)"><?= $label ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="wx-row" id="wxEditRow">
                <button type="button" class="wx-btn" id="wxUndo" title="<?= $t('admin.water.undo') ?>" data-on-click="wxUndo()"><i class="bi bi-arrow-counterclockwise"></i><span><?= $t('admin.water.tb.undo') ?></span></button>
                <button type="button" class="wx-btn" id="wxReverse" title="<?= $t('admin.water.reverse') ?>" data-on-click="wxReverse()"><i class="bi bi-arrow-repeat"></i><span><?= $t('admin.water.tb.reverse') ?></span></button>
                <button type="button" class="wx-btn" id="wxClose" title="<?= $t('admin.water.close_line') ?>" data-on-click="wxCloseLine()"><i class="bi bi-circle"></i><span><?= $t('admin.water.tb.close') ?></span></button>
            </div>
            <div class="wx-ring" id="wxRingSide" title="<?= $t('admin.water.ring_label') ?>" hidden>
                <label title="<?= $t('admin.water.ring_outside') ?>"><input type="radio" name="wxRingSide" id="wxRingOutside" value="outside" checked data-on-change="wxRingSideChanged()"><?= $t('admin.water.tb.island') ?></label>
                <label title="<?= $t('admin.water.ring_inside') ?>"><input type="radio" name="wxRingSide" id="wxRingInside" value="inside" data-on-change="wxRingSideChanged()"><?= $t('admin.water.tb.lake') ?></label>
            </div>
            <button type="button" class="wx-btn" id="wxNoteButton" title="<?= $t('admin.water.note_placeholder') ?>" data-on-click="wxToggleNote()"><i class="bi bi-chat-left-text"></i><span><?= $t('admin.water.tb.note') ?></span></button>
            <button type="button" class="wx-btn" id="wxHelpButton" title="<?= $t('admin.water.tb.help_title') ?>" data-on-click="wxToggleHelp()"><i class="bi bi-question-circle"></i><span><?= $t('admin.water.tb.help') ?></span></button>
            <button type="button" class="wx-btn wx-save" id="wxSave" title="<?= $t('admin.water.save') ?>" data-on-click="wxSave()"><span class="wx-badge" id="wxSaveBadge" hidden></span><i class="bi bi-check-lg"></i><span><?= $t('admin.water.save') ?></span></button>
            <button type="button" class="wx-btn" title="<?= $t('common.cancel') ?>" data-on-click="wxCancelTool()"><i class="bi bi-x-lg"></i><span><?= $t('common.cancel') ?></span></button>
        </div>
    </div>
    <!-- beside the bar: the hint (Help) and the note field (Note) -->
    <div class="wx-flyouts">
        <div class="wx-flyout" id="wxDrawHint" hidden></div>
        <div class="wx-flyout" id="wxNoteBox" hidden>
            <input type="text" class="form-control form-control-sm" id="wxNote" maxlength="500" placeholder="<?= $t('admin.water.note_placeholder') ?>">
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
    /* Tool bar = map control (wxInit): a 64 px column of buttons (symbol + short text), the hint
       and the note field beside it. Theme colours from Bootstrap, line colours as in the legend. */
    .wx-tools {
        --wx-sea: #1565c0; --wx-lake: #00acc1; --wx-river: #2e7d32; --wx-canal: #8e24aa;
        display: flex; align-items: flex-start; gap: 8px;
        margin: 10px; max-height: calc(100% - 20px);
        font-size: 11px; line-height: 1.15;
    }
    .wx-tools [hidden] { display: none !important; }
    .wx-bar, .wx-flyout {
        background: var(--bs-body-bg); color: var(--bs-body-color);
        border-radius: 4px; box-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);
    }
    .wx-bar { width: 64px; flex: none; overflow: hidden; }
    .wx-btn {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
        width: 100%; height: 40px; padding: 0 2px; position: relative;
        border: 0; border-top: 1px solid var(--bs-border-color); background: none; color: inherit;
        font: inherit; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .wx-bar > div > .wx-btn:first-child, .wx-bar > div > :first-child { border-top: 0; }
    .wx-btn i { font-size: 18px; line-height: 1; }
    .wx-btn span { max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
    .wx-btn:hover:not(:disabled) { background: var(--bs-tertiary-bg); }
    .wx-btn:disabled { opacity: 0.4; }
    .wx-btn.wx-on { color: var(--bs-primary); background: var(--bs-primary-bg-subtle); }
    .wx-tool-delete i { color: #e53935; }
    .wx-tool-flip i { color: #1e88e5; }
    .wx-tool-add i { color: #43a047; }
    .wx-active { font-weight: 600; background: color-mix(in srgb, var(--wx-tool) 15%, transparent); color: var(--wx-tool); }
    .wx-save { background: var(--bs-primary); color: #fff; }
    .wx-save:hover:not(:disabled) { background: var(--bs-primary); filter: brightness(1.1); }
    .wx-badge {
        position: absolute; top: 3px; right: 4px; padding: 0 4px; border-radius: 8px;
        background: #fff; color: var(--bs-primary); font-size: 10px; font-weight: 700;
    }
    .wx-kinds, .wx-ring { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid var(--bs-border-color); }
    .wx-kind, .wx-ring label {
        display: flex; align-items: center; justify-content: center; gap: 2px; margin: 0;
        padding: 5px 0; font-size: 10px; cursor: pointer; opacity: 0.55;
    }
    .wx-kind::before { content: ""; width: 6px; height: 6px; flex: none; border-radius: 50%; background: var(--wx-c); }
    .wx-kind:has(input:checked) { opacity: 1; font-weight: 600; background: color-mix(in srgb, var(--wx-c) 18%, transparent); }
    .wx-kind:has(input:disabled) { cursor: default; }
    .wx-ring label:has(input:checked) { opacity: 1; font-weight: 600; color: var(--bs-primary); background: var(--bs-primary-bg-subtle); }
    .wx-kind input, .wx-ring input { position: absolute; opacity: 0; pointer-events: none; }
    .wx-kind:has(input:focus-visible), .wx-ring label:has(input:focus-visible) { outline: 2px solid var(--bs-primary); outline-offset: -2px; }
    /* undo / reverse / close: in a row of three symbols for the line tools (the text in the tooltip) */
    .wx-row { display: flex; border-top: 1px solid var(--bs-border-color); }
    .wx-row .wx-btn { border-top: 0; }
    .wx-row.wx-compact .wx-btn { height: 34px; }
    .wx-row.wx-compact .wx-btn + .wx-btn { border-left: 1px solid var(--bs-border-color); }
    .wx-row.wx-compact .wx-btn span { display: none; }
    .wx-flyouts { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
    .wx-flyout { padding: 8px 10px; width: 220px; max-width: calc(100vw - 120px); font-size: 12px; line-height: 1.35; }</style>
<script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?>>window.YTAN_MAPS_API_KEY = <?= json_encode($mapsApiKey ?? '') ?>;</script>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
