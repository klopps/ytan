/**
 * YTAN API client
 *
 * Thin fetch() wrapper around the /api/v1 REST API, replacing the legacy
 * service.php?key=...&cmd=... RPC dispatcher. Handles the JWT bearer token
 * (issued by POST /api/v1/auth/login) so callers don't have to.
 *
 * This is the one module a future native (React Native/Flutter/native)
 * client would NOT reuse directly, but its request/response shape is what
 * such a client should mirror.
 */
const Ytan = (() => {
    // Injected by templates/app.php from the server's own base path, so this
    // keeps working whether the app is served at the domain root or from a
    // subdirectory (e.g. http://localhost/ytan/public/).
    const BASE_URL = (typeof window.YTAN_API_BASE !== 'undefined') ? window.YTAN_API_BASE : '/api/v1';
    const TOKEN_KEY = 'ytan_token';

    function getToken() {
        return localStorage.getItem(TOKEN_KEY);
    }

    function setToken(token) {
        if (token) {
            localStorage.setItem(TOKEN_KEY, token);
        } else {
            localStorage.removeItem(TOKEN_KEY);
        }
    }

    async function request(method, path, body) {
        const headers = { 'Content-Type': 'application/json' };
        const token = getToken();
        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }

        const response = await fetch(BASE_URL + path, {
            method,
            headers,
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (e) {
            // no/invalid JSON body (e.g. 204 or a network-level HTML error page)
        }

        if (!response.ok) {
            const message = (payload && payload.error && payload.error.message) || ('HTTP ' + response.status);
            const error = new Error(message);
            error.status = response.status;
            throw error;
        }

        return payload;
    }

    return {
        get: (path) => request('GET', path),
        post: (path, body) => request('POST', path, body),
        put: (path, body) => request('PUT', path, body),
        del: (path) => request('DELETE', path),
        wsiImageUrl: (code) => BASE_URL + '/wsi/' + code,
        setToken,
        getToken,
        isLoggedIn: () => !!getToken(),
    };
})();
