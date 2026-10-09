/**
 * User guide page (templates/help.php): full-text search, keyword chips,
 * table-of-contents toggle on narrow screens, "back to top" button and the
 * current-chapter highlight in the table of contents. Plain script, no
 * dependency on the SPA's globals - the page is standalone (and also runs
 * inside the app's help panel iframe).
 */
(function () {
    'use strict';

    var searchInput = document.getElementById('helpSearch');
    var clearButton = document.getElementById('helpSearchClear');
    var noResults = document.getElementById('helpNoResults');
    var resultCount = document.getElementById('helpResultCount');
    var toc = document.getElementById('helpToc');
    var tocToggle = document.getElementById('helpTocToggle');
    var toTop = document.getElementById('helpToTop');
    var chapters = Array.prototype.slice.call(document.querySelectorAll('.help-chapter'));
    // The keyword index is navigation, not content: a search never lists it.
    var searchable = chapters.filter(function (chapter) { return chapter.dataset.chapter !== 'keyword-index'; });

    // A chapter is searched in blocks: the intro up to the first h2, then one
    // block per h2 section. A block is shown or hidden as a whole.
    var blocks = [];
    searchable.forEach(function (chapter) {
        var body = chapter.querySelector('.help-body');
        var current = { chapter: chapter, heading: null, elements: [], text: '' };
        blocks.push(current);
        Array.prototype.slice.call(body.children).forEach(function (el) {
            if (el.tagName === 'H2') {
                current = { chapter: chapter, heading: el, elements: [], text: el.textContent };
                blocks.push(current);
            }
            current.elements.push(el);
            current.text += ' ' + el.textContent;
        });
    });
    blocks.forEach(function (block) {
        // The chapter's title and keywords belong to its intro block only, so a
        // keyword chip finds the chapter without counting every one of its sections.
        var extra = '';
        if (block.heading === null) {
            var keywords = block.chapter.querySelector('.help-keywords');
            extra = ' ' + (keywords ? keywords.textContent : '') + ' ' + block.chapter.querySelector('.help-chapter-title').textContent;
        }
        block.haystack = normalize(block.text + extra);
    });

    function normalize(text) {
        return text.toLowerCase().replace(/\s+/g, ' ');
    }

    function setHidden(el, hidden) {
        el.classList.toggle('help-hidden', hidden);
    }

    function runSearch(raw) {
        var query = normalize(raw.trim());
        var words = query === '' ? [] : query.split(' ');
        var shownChapters = {};
        var shownBlocks = 0;
        var ranges = [];

        blocks.forEach(function (block) {
            var match = words.every(function (word) { return block.haystack.indexOf(word) !== -1; });
            var visible = words.length === 0 || match;
            block.elements.forEach(function (el) { setHidden(el, !visible); });
            if (visible) {
                shownChapters[block.chapter.dataset.chapter] = true;
                if (words.length > 0) {
                    shownBlocks++;
                    block.elements.forEach(function (el) { collectRanges(el, words, ranges); });
                }
            }
        });
        chapters.forEach(function (chapter) {
            var visible = words.length === 0 || shownChapters[chapter.dataset.chapter] === true;
            setHidden(chapter, !visible);
        });
        Array.prototype.forEach.call(document.querySelectorAll('.help-toc [data-chapter]'), function (li) {
            setHidden(li, words.length > 0 && shownChapters[li.dataset.chapter] !== true);
        });
        // The cover (print only) and the keyword index stay out of the search result.
        var searching = words.length > 0;
        document.documentElement.classList.toggle('help-searching', searching);
        noResults.hidden = !(searching && shownBlocks === 0);
        resultCount.hidden = !(searching && shownBlocks > 0);
        resultCount.textContent = window.HELP_I18N.results.replace('{count}', shownBlocks);
        clearButton.hidden = !searching;
        highlight(ranges);
    }

    // The CSS Custom Highlight API marks matches without touching the DOM
    // (browsers without it simply show no marks).
    function collectRanges(root, words, ranges) {
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        var node;
        while ((node = walker.nextNode())) {
            var text = node.nodeValue.toLowerCase();
            words.forEach(function (word) {
                var from = 0;
                var at;
                while ((at = text.indexOf(word, from)) !== -1) {
                    var range = document.createRange();
                    range.setStart(node, at);
                    range.setEnd(node, at + word.length);
                    ranges.push(range);
                    from = at + word.length;
                }
            });
        }
    }

    function highlight(ranges) {
        if (typeof CSS === 'undefined' || !CSS.highlights || typeof Highlight === 'undefined') {
            return;
        }
        CSS.highlights.delete('help-match');
        if (ranges.length > 0) {
            CSS.highlights.set('help-match', new Highlight(...ranges));
        }
    }

    var searchTimer = null;
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { runSearch(searchInput.value); }, 120);
    });
    clearButton.addEventListener('click', function () {
        searchInput.value = '';
        runSearch('');
        searchInput.focus();
    });

    // Keyword chips (chapter headers and the index) start a search for the keyword.
    document.addEventListener('click', function (event) {
        var chip = event.target.closest('[data-keyword]');
        if (!chip) {
            return;
        }
        searchInput.value = chip.dataset.keyword;
        runSearch(searchInput.value);
        window.scrollTo({ top: 0, behavior: 'smooth' });
        document.getElementById('helpContent').scrollTop = 0;
    });

    // Table of contents: a drop-down on narrow screens, a sidebar on wide ones (CSS).
    tocToggle.addEventListener('click', function () {
        var open = !toc.classList.contains('is-open');
        toc.classList.toggle('is-open', open);
        tocToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    toc.addEventListener('click', function (event) {
        if (event.target.closest('a')) {
            toc.classList.remove('is-open');
            tocToggle.setAttribute('aria-expanded', 'false');
            // A TOC link into a hidden (filtered-out) part would go nowhere.
            if (searchInput.value !== '') {
                searchInput.value = '';
                runSearch('');
            }
        }
    });

    // A link to a section that a search currently hides: drop the search.
    window.addEventListener('hashchange', function () {
        var target = location.hash ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
        if (target && target.offsetParent === null && searchInput.value !== '') {
            searchInput.value = '';
            runSearch('');
            target.scrollIntoView();
        }
    });

    // Lazy images below the fold are not loaded when the browser lays out the
    // page for printing: load them all first.
    window.addEventListener('beforeprint', function () {
        Array.prototype.forEach.call(document.querySelectorAll('img[loading="lazy"]'), function (img) {
            img.loading = 'eager';
        });
    });

    toTop.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    window.addEventListener('scroll', function () {
        toTop.hidden = window.scrollY < 600;
    }, { passive: true });

    // Current chapter in the table of contents.
    if ('IntersectionObserver' in window) {
        var tocItems = {};
        Array.prototype.forEach.call(document.querySelectorAll('.help-toc > ol > li[data-chapter]'), function (li) {
            tocItems[li.dataset.chapter] = li;
        });
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    Object.keys(tocItems).forEach(function (key) { tocItems[key].classList.remove('is-current'); });
                    var item = tocItems[entry.target.dataset.chapter];
                    if (item) {
                        item.classList.add('is-current');
                    }
                }
            });
        }, { rootMargin: '-10% 0px -80% 0px' });
        chapters.forEach(function (chapter) { observer.observe(chapter); });
    }

    // ?q=term opens the guide with a search already running (links from the app).
    var initial = new URLSearchParams(location.search).get('q');
    if (initial) {
        searchInput.value = initial;
        runSearch(initial);
    }
})();
