<?php
/**
 * /admin/workers - the YTAN workers (tools/ytan_worker.py): the registry with unique names and, per job,
 * the priority list of who computes it (JobPlacement, WorkerSettingsController, admin-workers.js).
 */
$pageTitle = $t('admin.workers.title');
$adminActiveNav = 'workers';
$pageScripts = ['/js/admin-workers.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
?>
<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6"><?= $t('admin.workers.title') ?></h2>
        <p class="text-muted small mb-2"><?= $t('admin.workers.hint') ?></p>
        <details class="small mb-1">
            <summary><?= $t('admin.workers.setup_title') ?></summary>
            <p class="mt-2 mb-0"><?= $t('admin.workers.setup_text') ?></p>
        </details>
        <details class="small">
            <summary><?= $t('admin.workers.security_title') ?></summary>
            <p class="mt-2 mb-0"><?= $t('admin.workers.security_text') ?></p>
        </details>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h6"><?= $t('admin.workers.registered_title') ?></h2>
        <p class="text-muted small"><?= $t('admin.workers.registered_hint') ?></p>
        <ul class="list-group mb-3" id="workersRegistered"></ul>
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <input type="text" class="form-control form-control-sm" id="workersNewId" maxlength="32" autocomplete="off" autocapitalize="none" spellcheck="false"
                       placeholder="<?= htmlspecialchars($t('admin.workers.new_name'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($t('admin.workers.col_name'), ENT_QUOTES) ?>">
            </div>
            <div class="col-12 col-md-4">
                <input type="text" class="form-control form-control-sm" id="workersNewLabel" maxlength="60" autocomplete="off"
                       placeholder="<?= htmlspecialchars($t('admin.workers.new_label'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($t('admin.workers.col_label'), ENT_QUOTES) ?>">
            </div>
            <div class="col-12 col-md-3">
                <input type="text" class="form-control form-control-sm" id="workersNewNote" maxlength="200" autocomplete="off"
                       placeholder="<?= htmlspecialchars($t('admin.workers.new_note'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($t('admin.workers.col_note'), ENT_QUOTES) ?>">
            </div>
            <div class="col-12 col-md-2">
                <button type="button" class="btn btn-outline-primary btn-sm w-100" id="workersAdd"><i class="bi bi-plus-lg"></i> <?= $t('admin.workers.add') ?></button>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3" id="workersUnknownCard" hidden>
    <div class="card-body">
        <h2 class="h6"><?= $t('admin.workers.unknown_title') ?></h2>
        <p class="text-muted small"><?= $t('admin.workers.unknown_hint') ?></p>
        <ul class="list-group" id="workersUnknown"></ul>
    </div>
</div>

<h2 class="h6 mt-4"><?= $t('admin.workers.jobs_title') ?></h2>
<div id="workersJobs"></div>
<p class="small text-secondary mb-4"><?= $t('admin.workers.autosave_hint') ?></p>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
