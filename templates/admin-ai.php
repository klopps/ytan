<?php
/**
 * /admin/ai - the Routencheck (routencheck.md): switch, daily limit, text
 * cache, connection test (PUT /settings/ai, POST /settings/ai/test), token
 * usage per month and user (GET /admin/ai/usage) and the traffic-light rules
 * (PUT /settings/briefing-rules). Rendered and wired by admin-ai.js.
 */
$pageTitle = $t('admin.ai.title');
$adminActiveNav = 'ai';
$pageScripts = ['/js/admin-ai.js'];
require $rootDir . '/templates/partials/admin-shell-header.php';
$ruleRows = [
    ['dusk_minutes', 'min'], ['cold_water_c', '°C'], ['lee_fetch_m', 'm'], ['lee_factor', '×'],
    ['exposed_fetch_km', 'km'], ['exposed_factor', '×'], ['offshore_land_km', 'km'], ['offshore_open_km', 'km'],
    ['beam_min_deg', '°'], ['beam_max_deg', '°'], ['headwind_slowdown', 'km/h'], ['window_from', 'h'], ['window_to', 'h'],
];
?>
<div class="card mb-3" id="aiStatusCard">
    <div class="card-body">
        <h2 class="h6"><?= $t('admin.ai.status_title') ?></h2>
        <div class="form-check form-switch mb-1">
            <input class="form-check-input" type="checkbox" role="switch" id="aiEnabled" <?= $aiSettings['enabled'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="aiEnabled"><?= $t('admin.ai.enabled') ?></label>
        </div>
        <p class="small text-secondary"><?= $t('admin.ai.enabled_hint') ?></p>
        <div class="row g-3 mb-3 align-items-end">
            <div class="col-6 col-md-3">
                <label for="aiDailyLimit" class="form-label"><?= $t('admin.ai.daily_limit') ?></label>
                <input type="number" class="form-control" id="aiDailyLimit" min="0" max="<?= \Ytan\Http\Controllers\AiAdminController::MAX_DAILY_LIMIT ?>" value="<?= (int) $aiSettings['daily_limit'] ?>">
            </div>
            <div class="col-6 col-md-3">
                <label for="aiCacheHours" class="form-label"><?= $t('admin.ai.cache_hours') ?></label>
                <input type="number" class="form-control" id="aiCacheHours" min="0" max="<?= \Ytan\Http\Controllers\AiAdminController::MAX_CACHE_HOURS ?>" value="<?= (int) $aiSettings['cache_hours'] ?>">
            </div>
        </div>
        <dl class="row small mb-2">
            <dt class="col-sm-4"><?= $t('admin.ai.model') ?></dt>
            <dd class="col-sm-8"><code><?= htmlspecialchars($aiClient->model()) ?></code></dd>
            <dt class="col-sm-4"><?= $t('admin.ai.key') ?></dt>
            <dd class="col-sm-8"><?= $aiClient->configured()
                ? '<span class="text-success">' . $t('admin.ai.key_set') . '</span>'
                : '<span class="text-danger">' . $t('admin.ai.key_missing') . '</span>' ?></dd>
        </dl>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary" id="aiSaveBtn"><?= $t('common.save') ?></button>
            <button type="button" class="btn btn-outline-secondary" id="aiTestBtn" <?= $aiClient->configured() ? '' : 'disabled' ?>><i class="bi bi-plug"></i> <?= $t('admin.ai.test') ?></button>
        </div>
    </div>
</div>

<div class="card mb-3" id="aiUsageCard">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
            <h2 class="h6"><?= $t('admin.ai.usage_title') ?></h2>
            <div class="d-flex align-items-center gap-2">
                <label for="aiMonth" class="small text-secondary mb-0"><?= $t('admin.ai.month') ?></label>
                <select class="form-select form-select-sm" id="aiMonth" style="width:auto"></select>
            </div>
        </div>
        <div class="row g-2 mb-3" id="aiSummary"></div>
        <div class="table-responsive ai-usage-table">
            <table class="table table-sm align-middle mb-1">
                <thead>
                    <tr>
                        <th scope="col" class="ai-sticky"><button type="button" class="btn btn-link btn-sm p-0 ai-sort" data-sort="username"><?= $t('admin.ai.col_user') ?></button></th>
                        <th scope="col" class="text-end"><button type="button" class="btn btn-link btn-sm p-0 ai-sort" data-sort="requests"><?= $t('admin.ai.col_requests') ?></button></th>
                        <th scope="col" class="text-end"><?= $t('admin.ai.col_input') ?></th>
                        <th scope="col" class="text-end"><?= $t('admin.ai.col_output') ?></th>
                        <th scope="col" class="text-end"><button type="button" class="btn btn-link btn-sm p-0 ai-sort" data-sort="total_tokens"><?= $t('admin.ai.col_total') ?></button></th>
                        <th scope="col" class="text-end"><?= $t('admin.ai.col_avg') ?></th>
                        <th scope="col"><?= $t('admin.ai.col_last') ?></th>
                    </tr>
                </thead>
                <tbody id="aiUsageRows"></tbody>
            </table>
        </div>
        <p class="small text-secondary mb-0"><?= $t('admin.ai.usage_hint') ?></p>
    </div>
</div>

<div class="card mb-3" id="aiRulesCard">
    <div class="card-body">
        <h2 class="h6"><?= $t('admin.ai.rules_title') ?></h2>
        <p class="small text-secondary"><?= $t('admin.ai.rules_hint') ?></p>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th scope="col"></th><th scope="col"><?= $t('admin.ai.yellow') ?></th><th scope="col"><?= $t('admin.ai.red') ?></th></tr></thead>
                <tbody>
                <?php foreach (['wind' => 'm/s', 'gust' => 'm/s', 'wave' => 'm'] as $key => $unit): ?>
                    <tr>
                        <th scope="row" class="fw-normal"><?= $t('admin.ai.rule.' . $key) ?> <span class="text-secondary">(<?= $unit ?>)</span></th>
                        <td><input type="number" step="0.1" class="form-control form-control-sm ai-rule" data-rule="<?= $key ?>.yellow" aria-label="<?= htmlspecialchars($t('admin.ai.rule.' . $key) . ' ' . $t('admin.ai.yellow'), ENT_QUOTES) ?>"></td>
                        <td><input type="number" step="0.1" class="form-control form-control-sm ai-rule" data-rule="<?= $key ?>.red" aria-label="<?= htmlspecialchars($t('admin.ai.rule.' . $key) . ' ' . $t('admin.ai.red'), ENT_QUOTES) ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="row g-3 mb-3">
            <?php foreach ($ruleRows as [$key, $unit]): ?>
            <div class="col-12 col-sm-6 col-lg-4">
                <label for="aiRule_<?= $key ?>" class="form-label small"><?= $t('admin.ai.rule.' . $key) ?> <span class="text-secondary">(<?= htmlspecialchars($unit) ?>)</span></label>
                <input type="number" step="any" class="form-control form-control-sm ai-rule" id="aiRule_<?= $key ?>" data-rule="<?= $key ?>">
            </div>
            <?php endforeach; ?>
            <?php foreach (\Ytan\Service\Ai\BriefingRules::LEVELS as $level): ?>
            <div class="col-12 col-sm-6 col-lg-4">
                <label for="aiRule_level_<?= $level ?>" class="form-label small"><?= $t('admin.ai.rule.level_' . $level) ?> <span class="text-secondary">(×)</span></label>
                <input type="number" step="0.05" class="form-control form-control-sm ai-rule" id="aiRule_level_<?= $level ?>" data-rule="levels.<?= $level ?>">
            </div>
            <?php endforeach; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary" id="aiRulesSaveBtn"><?= $t('common.save') ?></button>
            <button type="button" class="btn btn-outline-secondary" id="aiRulesResetBtn"><?= $t('admin.ai.rules_reset') ?></button>
        </div>
    </div>
</div>
<script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?>>window.YTAN_AI_ADMIN = <?= json_encode([
    'rules' => $briefingRules,
    'defaults' => \Ytan\Service\Ai\BriefingRules::defaults(),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;</script>
<?php require $rootDir . '/templates/partials/admin-shell-footer.php'; ?>
