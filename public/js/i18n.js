/**
 * Client-side lookup for the same translations templates/app.php resolved
 * server-side for the current locale (window.YTAN_TRANSLATIONS, injected
 * once per page load - see src/Service/Translator.php for the PHP-side
 * counterpart reading the same resources/i18n/{locale}.json files).
 *
 * Only the ACTIVE locale's strings are ever sent to the client (not every
 * supported language), so there is no separate fallback lookup here the
 * way Translator.php has one - a missing key just returns itself, same as
 * Translator::t()'s last-resort behavior.
 */
function t(key, vars) {
    var strings = window.YTAN_TRANSLATIONS || {};
    var template = Object.prototype.hasOwnProperty.call(strings, key) ? strings[key] : key;

    if (!vars) {
        return template;
    }

    return Object.keys(vars).reduce(function (result, name) {
        return result.split('{' + name + '}').join(String(vars[name]));
    }, template);
}

/**
 * Resolves a JSON API error body's {message, code} (the "error" object out
 * of {"error":{"message":...,"code":...}} - see src/App.php's error
 * handler and ApiException::getErrorCode()) into a user-facing string:
 * prefers the machine-readable code translated via the "error.<code>" key
 * when the backend attached one and a translation exists, falling back to
 * the raw (English-only) message otherwise - so a throw site not yet
 * migrated to a code still shows something sensible instead of breaking.
 *
 * api-client.js exposes this shape as a thrown Error's `.data` property;
 * the standalone auth pages (forgot/set-password, confirm-email) read it
 * directly off their parsed fetch() response body's `.error` field.
 */
function translateApiError(error) {
    if (!error) {
        return '';
    }
    if (!error.code) {
        return error.message;
    }
    var key = 'error.' + error.code;
    var translated = t(key);
    return translated === key ? error.message : translated;
}
