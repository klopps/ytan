/**
 * Logic for the standalone /admin/users page (templates/admin-users.php) -
 * loads the existing user-admin panel (admin-user.js's openUserAdminMenu(),
 * unchanged from when it lived inside the main SPA's drawer) behind the
 * shared admin login-gate (admin-auth.js). admin-user.js no longer touches
 * a global `user` object (its one former reference, the "Google search
 * requires login" toggle, moved to the /admin menu page), so this is just
 * theme + login-gate + opening the panel.
 */

applyStoredTheme();

initAdminAuth({
    contentId: 'adminUsersReady',
    onReady: openUserAdminMenu,
});
