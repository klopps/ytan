/**
 * Shared login-gate for standalone admin pages (/admin, /admin/image-
 * cleanup, /admin/users, ...) - checks the stored JWT's is_admin via GET
 * /auth/me, shows a login form otherwise. Third occurrence of this exact
 * pattern (after /admin and /translate each having their own copy), so
 * pulled out here rather than copy-pasted a third time. translate.js keeps
 * its own self-contained copy rather than being migrated onto this - it's
 * more complex and already proven, not worth the regression risk for this
 * refactor alone.
 *
 * Every consuming page must provide:
 * - an element with id "loginBox" (the login form itself, hidden by default)
 * - inputs #loginUsername/#loginPassword and a #loginMessage element inside it
 * - its own content element (shown once authenticated), passed as contentId
 *
 * The login form's submit button must call `submitAdminLogin()` (attached
 * to window, since it's referenced from inline HTML onclick attributes).
 *
 * onReady(userData), if given, is called with the authenticated user's data
 * once shown - normalized to the same shape both success paths can produce
 * (the GET /auth/me check and the POST /auth/login submit return
 * differently-shaped payloads - `sub` vs `id` for the user id, in
 * particular - exactly the two shapes templates/app.php's own inline
 * bootstrap and user.js's loginUser() already normalize separately for the
 * main app; this does the same normalization once, here, for any
 * standalone admin page that needs to populate its own `user` global
 * without a second network round-trip).
 */
function initAdminAuth(options) {
    var contentId = options.contentId;
    var onReady = options.onReady;

    function showLogin(message) {
        document.getElementById('loginBox').hidden = false;
        document.getElementById(contentId).hidden = true;
        if (message) {
            document.getElementById('loginMessage').textContent = message;
        }
    }

    function showContent(userData) {
        document.getElementById('loginBox').hidden = true;
        document.getElementById(contentId).hidden = false;
        if (onReady) {
            onReady(userData);
        }
    }

    window.submitAdminLogin = async function () {
        var username = document.getElementById('loginUsername').value;
        var password = document.getElementById('loginPassword').value;
        var message = document.getElementById('loginMessage');
        message.textContent = '';

        try {
            var answer = await Ytan.post('/auth/login', { username: username, password: password });
            if (!answer.user.is_admin) {
                message.textContent = 'Signed in, but this account is not an admin.';
                return;
            }
            Ytan.setToken(answer.token);
            showContent({
                id: answer.user.id,
                username: answer.user.username,
                email: answer.user.email,
                firstname: answer.user.firstname,
                lastname: answer.user.lastname,
                is_admin: !!answer.user.is_admin,
                tour_create: !!answer.user.tour_create,
                tour_publish: !!answer.user.tour_publish,
                tour_manage: !!answer.user.tour_manage,
                tour_copy: !!answer.user.tour_copy,
                route_view_recording: !!answer.user.route_view_recording,
                pending_email: null,
                pending_email_expires_at: null,
            });
        } catch (err) {
            message.textContent = err.message;
        }
    };

    (async function checkAuthAndInit() {
        var token = localStorage.getItem('ytan_token');
        if (!token) {
            showLogin();
            return;
        }

        try {
            var me = await Ytan.get('/auth/me');
            if (!me.data.is_admin) {
                showLogin('Signed in, but this account is not an admin.');
                return;
            }
            showContent({
                id: me.data.sub,
                username: me.data.username,
                email: me.data.email,
                firstname: me.data.firstname,
                lastname: me.data.lastname,
                is_admin: !!me.data.is_admin,
                tour_create: !!me.data.tour_create,
                tour_publish: !!me.data.tour_publish,
                tour_manage: !!me.data.tour_manage,
                tour_copy: !!me.data.tour_copy,
                route_view_recording: !!me.data.route_view_recording,
                pending_email: me.data.pending_email,
                pending_email_expires_at: me.data.pending_email_expires_at,
            });
        } catch (err) {
            showLogin();
        }
    })();
}
