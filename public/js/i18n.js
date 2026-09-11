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
