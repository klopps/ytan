/**
 * Event handlers without inline JavaScript (Content-Security-Policy, security
 * audit 2026-10-16). The CSP (src/Http/ContentSecurityPolicy.php) forbids
 * inline handler attributes (onclick & co.), so markup carries the same call
 * in a data attribute instead:
 *
 *     <button data-on-click="editPoi(3); closeMenu()">
 *     <input data-on-input="validatePoiEditForm()">
 *     <form data-on-submit="return false">
 *
 * One capture-phase listener per event type on the document finds the
 * element(s) with a matching data-on-<type> (the target and its ancestors,
 * innermost first - like inline handlers bubbling) and runs the calls. They
 * are PARSED, never evaluated (no eval, which the CSP forbids anyway), and
 * deliberately limited, since this attribute is now a way to run code:
 * - only global function declarations of our own scripts (window[name],
 *   native/built-in functions such as fetch or open are refused - no dotted
 *   names, no property access, no assignments);
 * - arguments only literals (numbers, 'strings', "strings", true/false/null,
 *   {key: literal} objects) plus this, this.value, this.checked,
 *   this.dataset.x and event;
 * - "return false" calls event.preventDefault().
 * DOMPurify drops data-* attributes from user Markdown (renderMarkdown()),
 * and escaped user text can't create attributes at all, so user content
 * can't add such calls.
 *
 * Loaded on every page with handlers, before the scripts it calls into -
 * it only needs the functions to exist by the time an event fires.
 */
(function () {
    const EVENTS = ['click', 'input', 'change', 'submit', 'keypress', 'keydown', 'pointerdown'];
    const NATIVE = /\{\s*\[native code\]\s*\}\s*$/;
    const FORBIDDEN_SEGMENTS = new Set(['__proto__', 'prototype', 'constructor']);

    /** Splits "a(1); b('x;y'); return false" at top-level semicolons. */
    function splitStatements(code) {
        const out = [];
        let depth = 0, quote = null, start = 0;
        for (let i = 0; i < code.length; i++) {
            const c = code[i];
            if (quote) {
                if (c === '\\') { i++; continue; }
                if (c === quote) { quote = null; }
                continue;
            }
            if (c === '"' || c === "'") { quote = c; continue; }
            if (c === '(' || c === '{' || c === '[') { depth++; continue; }
            if (c === ')' || c === '}' || c === ']') { depth--; continue; }
            if (c === ';' && depth === 0) {
                out.push(code.slice(start, i));
                start = i + 1;
            }
        }
        out.push(code.slice(start));
        return out.map(s => s.trim()).filter(Boolean);
    }

    /** Minimal tokenizer/parser for one argument list. */
    function Parser(text, element, event) {
        this.s = text;
        this.i = 0;
        this.element = element;
        this.event = event;
    }
    Parser.prototype.ws = function () {
        while (this.i < this.s.length && /\s/.test(this.s[this.i])) this.i++;
    };
    Parser.prototype.fail = function (what) {
        throw new Error('csp-events: ' + what + ' at ' + this.i + ' in "' + this.s + '"');
    };
    Parser.prototype.value = function () {
        this.ws();
        const c = this.s[this.i];
        if (c === '"' || c === "'") return this.string(c);
        if (c === '{') return this.object();
        if (c === '-' || /[0-9.]/.test(c)) return this.number();
        const ident = this.identifier();
        if (ident === 'true') return true;
        if (ident === 'false') return false;
        if (ident === 'null') return null;
        if (ident === 'undefined') return undefined;
        if (ident === 'event') return this.event;
        if (ident === 'this') {
            let value = this.element;
            // this.value / this.checked / this.dataset.name - property reads only.
            while (this.s[this.i] === '.') {
                this.i++;
                const key = this.identifier();
                if (FORBIDDEN_SEGMENTS.has(key)) this.fail('forbidden property');
                value = value == null ? undefined : value[key];
            }
            return value;
        }
        this.fail('unexpected "' + ident + '"');
    };
    Parser.prototype.identifier = function () {
        this.ws();
        const m = /^[A-Za-z_$][\w$]*/.exec(this.s.slice(this.i));
        if (!m) this.fail('identifier expected');
        this.i += m[0].length;
        return m[0];
    };
    Parser.prototype.number = function () {
        const m = /^-?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/.exec(this.s.slice(this.i));
        if (!m) this.fail('number expected');
        this.i += m[0].length;
        return Number(m[0]);
    };
    Parser.prototype.string = function (quote) {
        let out = '';
        this.i++;
        while (this.i < this.s.length) {
            const c = this.s[this.i++];
            if (c === quote) return out;
            if (c !== '\\') { out += c; continue; }
            const e = this.s[this.i++];
            if (e === 'n') out += '\n';
            else if (e === 't') out += '\t';
            else if (e === 'r') out += '\r';
            else if (e === 'u') { out += String.fromCharCode(parseInt(this.s.substr(this.i, 4), 16)); this.i += 4; }
            else out += e;
        }
        this.fail('unterminated string');
    };
    Parser.prototype.object = function () {
        const obj = {};
        this.i++;
        this.ws();
        if (this.s[this.i] === '}') { this.i++; return obj; }
        for (;;) {
            this.ws();
            const c = this.s[this.i];
            const key = (c === '"' || c === "'") ? this.string(c) : this.identifier();
            if (FORBIDDEN_SEGMENTS.has(key)) this.fail('forbidden key');
            this.ws();
            if (this.s[this.i++] !== ':') this.fail('":" expected');
            obj[key] = this.value();
            this.ws();
            const sep = this.s[this.i++];
            if (sep === '}') return obj;
            if (sep !== ',') this.fail('"," or "}" expected');
        }
    };

    /** Runs one statement; returns false for "return false". */
    function runStatement(statement, element, event) {
        if (/^return\s+false$/.test(statement)) {
            event.preventDefault();
            return false;
        }
        const p = new Parser(statement, element, event);
        const name = p.identifier();
        p.ws();
        if (p.s[p.i++] !== '(') p.fail('"(" expected after ' + name);
        const args = [];
        p.ws();
        if (p.s[p.i] !== ')') {
            for (;;) {
                args.push(p.value());
                p.ws();
                const sep = p.s[p.i++];
                if (sep === ')') break;
                if (sep !== ',') p.fail('"," or ")" expected');
            }
        } else {
            p.i++;
        }
        p.ws();
        if (p.i !== p.s.length) p.fail('trailing characters');

        const fn = Object.prototype.hasOwnProperty.call(window, name) ? window[name] : undefined;
        if (typeof fn !== 'function' || NATIVE.test(Function.prototype.toString.call(fn))) {
            throw new Error('csp-events: "' + name + '" is not an allowed handler function');
        }
        fn.apply(element, args);
        return true;
    }

    function dispatch(event) {
        const attribute = 'data-on-' + event.type;
        let element = event.target instanceof Element ? event.target : event.target && event.target.parentElement;
        while (element) {
            const code = element.getAttribute(attribute);
            if (code !== null) {
                for (const statement of splitStatements(code)) {
                    try {
                        if (runStatement(statement, element, event) === false) {
                            break;
                        }
                    } catch (err) {
                        console.error(err);
                        break;
                    }
                }
                // Like inline handlers: a handler that stopped propagation
                // keeps the ancestors' handlers from running.
                if (event.cancelBubble) {
                    return;
                }
            }
            element = element.parentElement;
        }
    }

    // Capture phase: Google Maps InfoWindows and other widgets may stop
    // propagation inside their content before a bubbling listener on the
    // document would see the event.
    EVENTS.forEach(type => document.addEventListener(type, dispatch, true));

    // Helpers for the few handlers that used to be more than a function call.

    /** Opens a hidden file input etc. (was document.getElementById(id).click()). */
    window.clickElementById = function clickElementById(id) {
        const el = document.getElementById(id);
        if (el) el.click();
    };

    /** Was window.location.href = url. Own origin only - no open redirect. */
    window.navigateTo = function navigateTo(url) {
        const target = new URL(String(url), window.location.href);
        if (target.origin !== window.location.origin) {
            throw new Error('csp-events: navigateTo() only within this site');
        }
        window.location.href = target.href;
    };

    /**
     * Admin area: new tab in a desktop browser, same tab on phones/tablets
     * (touch) and in the native Android app. Own origin only.
     */
    window.openAdmin = function openAdmin(url) {
        const target = new URL(String(url), window.location.href);
        if (target.origin !== window.location.origin) {
            throw new Error('csp-events: openAdmin() only within this site');
        }
        const native = typeof CapacitorBridge !== 'undefined' && CapacitorBridge.isAvailable();
        const desktop = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        if (!native && desktop) {
            // 'noopener' would make window.open() return null even on success
            // (the fallback below would then navigate this tab too).
            // Fixed window name: an already open admin tab is reused and
            // brought to the front by the browser (focus() alone doesn't
            // switch tabs), otherwise a new tab opens.
            // An empty URL finds an existing tab without reloading it; a fresh
            // (blank) one is then pointed at the admin area.
            const tab = window.open('', 'ytan_admin');
            if (tab) {
                let fresh = true;
                try {
                    fresh = tab.location.href === 'about:blank';
                } catch (e) {
                    // other origin in that tab: take it over
                }
                if (fresh) {
                    tab.opener = null;
                    tab.location.href = target.href;
                }
                tab.focus();
                return;
            }
        }
        window.location.href = target.href;
    };

    /** Was window.print(). */
    window.printPage = function printPage() {
        window.print();
    };
})();
