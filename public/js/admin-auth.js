/**
 * Shared login-gate for standalone admin pages (/admin, /admin/image-
 * cleanup, ...) - checks the stored JWT's is_admin via GET /auth/me, shows
 * a login form otherwise. Third occurrence of this exact pattern (after
 * /admin and /translate each having their own copy), so pulled out here
 * rather than copy-pasted a third time. translate.js keeps its own
 * self-contained copy rather than being migrated onto this - it's more
 * complex and already proven, not worth the regression risk for this
 * refactor alone.
 *
 * Every consuming page must provide:
 * - an element with id "loginBox" (the login form itself, hidden by default)
 * - inputs #loginUsername/#loginPassword and a #loginMessage element inside it
 * - its own content element (shown once authenticated), passed as contentId
 *
 * The login form's submit button must call `submitAdminLogin()` (attached
 * to window, since it's referenced from inline HTML onclick attributes).
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

    function showContent() {
        document.getElementById('loginBox').hidden = true;
        document.getElementById(contentId).hidden = false;
        if (onReady) {
            onReady();
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
            showContent();
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
            showContent();
        } catch (err) {
            showLogin();
        }
    })();
}
