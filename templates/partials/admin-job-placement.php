<?php
/**
 * Where a scheduled job runs (JobPlacement): on this server or on the YTAN
 * worker (Home Assistant add-on). Included by admin-radar.php / admin-wind.php
 * with $placementJob set; rendered and saved by admin-common.js
 * (initAdminJobPlacement(), PUT /settings/{job}/placement).
 */
?>
<div class="border rounded p-2 mb-3 small" id="jobPlacement" data-job="<?= htmlspecialchars($placementJob, ENT_QUOTES) ?>">
    <div class="form-check form-switch mb-1">
        <input class="form-check-input" type="checkbox" role="switch" id="jobPlacementExternal">
        <label class="form-check-label" for="jobPlacementExternal"><?= $t('admin.job_placement.external') ?></label>
    </div>
    <div class="text-secondary" id="jobPlacementWorker"></div>
</div>
