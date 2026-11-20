<?php
/**
 * Where a scheduled job runs (JobPlacement): who works on it NOW (this server's cron or one of the
 * registered YTAN workers) - read-only here, the priority list is edited on /admin/workers.
 * Included by admin-radar.php / admin-wind.php with $placementJob set; rendered by admin-common.js
 * (initAdminJobPlacement() / renderAdminJobPlacement()) from the page's settings payload
 * (JobPlacement::payload()).
 */
?>
<div class="border rounded p-2 mb-3 small" id="jobPlacement" data-job="<?= htmlspecialchars($placementJob, ENT_QUOTES) ?>">
    <div id="jobPlacementWorker" class="text-secondary"></div>
    <a href="<?= htmlspecialchars($baseUrl . '/admin/workers', ENT_QUOTES) ?>"><?= $t('admin.job_placement.manage') ?></a>
</div>
