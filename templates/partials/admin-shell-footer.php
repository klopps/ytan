<?php
/**
 * Closing half of the shared AdminLTE shell - see admin-shell-header.php's
 * doc comment for the full pattern. Loads the shared script stack every
 * admin page needs, then each page's own $pageScripts (set before the
 * header was required), in that order - a page's own script always runs
 * after admin-auth.js/bootstrap/adminlte.js are available, and is expected
 * to end with its own initAdminAuth({contentId: 'adminAppWrapper', ...})
 * call (same bottom-of-file convention every admin page already used
 * before this shell existed - kept as-is rather than centralizing it here,
 * since each page's onReady callback differs).
 */
?>
                </div>
            </div>
        </main>

        <footer class="app-footer">
            <?= htmlspecialchars($appName) ?> Admin
        </footer>
    </div>

    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="adminToastContainer"></div>

    <!-- Confirmation dialog of every admin page (adminConfirm() in admin-common.js),
         used instead of the browser's window.confirm(). -->
    <div class="modal fade" id="adminConfirmModal" tabindex="-1" aria-labelledby="adminConfirmTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="adminConfirmTitle"></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $t('common.cancel') ?>"></button>
                </div>
                <div class="modal-body" id="adminConfirmMessage" style="white-space: pre-line;"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $t('common.cancel') ?></button>
                    <button type="button" class="btn btn-primary" id="adminConfirmOk"></button>
                </div>
            </div>
        </div>
    </div>

    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl ?>/js/config.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/config.js') ?>"></script>
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl ?>/js/i18n.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/i18n.js') ?>"></script>
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl ?>/js/helper.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/helper.js') ?>"></script>
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl ?>/js/api-client.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/api-client.js') ?>"></script>
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl ?>/js/settings.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/settings.js') ?>"></script>
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl ?>/js/admin-auth.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-auth.js') ?>"></script>
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl ?>/js/admin-common.js?v=<?= \Ytan\App::assetVersion($rootDir, '/js/admin-common.js') ?>"></script>
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl ?>/lib/bootstrap/bootstrap.bundle.min.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/bootstrap/bootstrap.bundle.min.js') ?>"></script>
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl ?>/lib/adminlte/adminlte.min.js?v=<?= \Ytan\App::assetVersion($rootDir, '/lib/adminlte/adminlte.min.js') ?>"></script>
    <?php foreach ($pageScripts ?? [] as $src): ?>
    <script<?= \Ytan\Http\ContentSecurityPolicy::nonceAttr() ?> src="<?= $baseUrl . $src ?>?v=<?= \Ytan\App::assetVersion($rootDir, $src) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
