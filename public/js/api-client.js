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

    /**
     * Decodes the stored JWT's payload (the `sub` claim, i.e. the user id)
     * WITHOUT verifying its signature - purely advisory, never used to
     * authorize anything (the server always re-verifies via a real request).
     * Exists because `user.id` (templates/app.php's inline bootstrap) stays
     * null until an async GET /auth/me round trip resolves, which can't
     * happen offline - offline-cache.js uses this instead to know which
     * user's cached data to read back on a cold, offline boot. Returns null
     * if there's no token, or it can't be parsed as a JWT.
     */
    function getStoredUserId() {
        const token = getToken();
        if (!token) {
            return null;
        }
        const parts = token.split('.');
        if (parts.length !== 3) {
            return null;
        }
        try {
            const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')));
            return (typeof payload.sub === 'number') ? payload.sub : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * fetch() itself rejects (as opposed to resolving with a non-ok status)
     * when the request never reaches a server at all - offline, DNS/
     * connection failure, CORS, etc. Its rejection message is a raw browser
     * string ("Failed to fetch" in Chrome, "NetworkError when attempting to
     * fetch resource" in Firefox, ...) that isn't meaningful to an end user
     * once it lands in a showToast(err.message) call - wrapped here into one
     * consistent, friendly message instead. The original error is kept as
     * `cause` so it still shows up in the browser console for debugging.
     */
    function networkError(cause) {
        return new Error('Network error - please check your connection and try again.', { cause });
    }

    async function request(method, path, body) {
        const headers = { 'Content-Type': 'application/json' };
        const token = getToken();
        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }

        let response;
        try {
            response = await fetch(BASE_URL + path, {
                method,
                headers,
                body: body !== undefined ? JSON.stringify(body) : undefined,
            });
        } catch (e) {
            throw networkError(e);
        }

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
            // Some error bodies (CaptchaRequiredException) carry extra
            // structured fields (tour_count, captcha) alongside message -
            // exposed here so callers like route.js's deleteRoute captcha
            // flow don't need to re-fetch or re-parse the response.
            if (payload && payload.error) {
                error.data = payload.error;
            }
            throw error;
        }

        return payload;
    }

    /**
     * Fetches a binary resource (e.g. a tour photo) as a Blob, with the
     * same Bearer auth as request() above. A plain <img src="..."> can't
     * attach an Authorization header, so callers that need to show a
     * possibly-private image (tour photos) fetch it through here and set
     * img.src to URL.createObjectURL(blob) instead of pointing at the URL
     * directly - see tour-admin.js's renderTourImage().
     */
    async function fetchBlob(path) {
        const headers = {};
        const token = getToken();
        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }

        let response;
        try {
            response = await fetch(BASE_URL + path, { headers });
        } catch (e) {
            throw networkError(e);
        }
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        return response.blob();
    }

    /**
     * POSTs a FormData body (e.g. a tour photo upload) - unlike request()
     * above, this must NOT set a JSON Content-Type; the browser sets its
     * own multipart boundary header when given a FormData body.
     */
    async function postFile(path, formData) {
        const headers = {};
        const token = getToken();
        if (token) {
            headers['Authorization'] = 'Bearer ' + token;
        }

        let response;
        try {
            response = await fetch(BASE_URL + path, {
                method: 'POST',
                headers,
                body: formData,
            });
        } catch (e) {
            throw networkError(e);
        }

        let payload = null;
        try {
            payload = await response.json();
        } catch (e) {
            // no/invalid JSON body
        }

        if (!response.ok) {
            const message = (payload && payload.error && payload.error.message) || ('HTTP ' + response.status);
            const error = new Error(message);
            error.status = response.status;
            if (payload && payload.error) {
                error.data = payload.error;
            }
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
        fetchBlob,
        postFile,
        setToken,
        getToken,
        getStoredUserId,
        isLoggedIn: () => !!getToken(),
    };
})();
