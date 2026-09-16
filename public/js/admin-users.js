/**
 * Logic for the standalone /admin/users page (templates/admin-users.php) -
 * loads the existing user-admin panel (admin-user.js's openUserAdminMenu())
 * behind the shared admin login-gate (admin-auth.js).
 */

initAdminAuth({ contentId: 'adminAppWrapper', onReady: openUserAdminMenu });
