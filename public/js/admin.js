/**
 * Logic for the standalone /admin menu page (templates/admin.php) - the
 * entry point for admin-only tools. Login-gate handled entirely by
 * admin-auth.js (initAdminAuth()) - this file just wires it to this page's
 * content element, since the menu itself needs no further setup once shown.
 */

initAdminAuth({ contentId: 'adminMenu' });
