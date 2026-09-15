/**
 * Full-screen Tours panel: browse/search own + public tours, view a tour's
 * details, create/edit a tour's metadata (incl. tags/photos), manage which
 * routes belong to it ("Option B" - a searchable checklist), publish/
 * unpublish, copy, and delete - gated throughout by the Touren.md granular
 * rights (tour_create/tour_publish/tour_manage/tour_copy, plus is_admin
 * always overriding) carried on the `user` object (see map-core.js's
 * initUser(), user.js's loginUser(), and app.php's /auth/me bootstrap).
 *
 * Same .cookiemenu full-screen panel pattern as admin-user.js's user
 * management panel (a searchable list needs more room than the ~320px
 * drawer) - all sub-views (list / detail / form / route manager) swap in
 * place inside the single #touradminmenu-body container, the same way
 * admin-user.js swaps #useradminmenu-list/#useradminmenu-form.
 *
 * Tour metadata (name/description/tags/photos) and tour route-membership
 * are deliberately two separate flows (the form never edits routes, "Edit
 * Routes" never edits the name) - the same separation the app already
 * keeps between a route's geometry (measureTool) and its metadata fields.
 *
 * Because all sub-views share one container, a fetch started by one view
 * could otherwise still be in flight when the user has already navigated to
 * another (e.g. opening the panel then immediately tapping "New tour"
 * before the list's GET /tours resolves) and clobber it when it finally
 * resolves. tourAdminRenderToken guards against that: every show*()/
 * manageTourRoutes() entry point bumps it, and every async .then() that
 * renders checks it's still current before touching the DOM, discarding
 * stale responses instead.
 */

let tourAdminAllTours = []; // last-loaded list, kept for client-side search filtering
let tourAdminCurrentTourId = null; // tour shown in the route manager, so add/remove/reorder can refresh it
let tourRouteManagerOwnRoutes = []; // the current user's own routes, fetched once per manager visit
let tourRouteManagerInTour = []; // the tour's current routes, in order
let tourAdminRenderToken = 0; // bumped on every view switch; stale async renders check against it and no-op
let tourAdminLengthFilter = { min: '', max: '' }; // the list view's length-range filter, kept across re-renders
let tourAdminPageSize = ADMIN_LIST_DEFAULT_PAGE_SIZE; // config.js - shared with admin-user.js's user table
let tourAdminPage = { mine: 1, public: 1 }; // 1-indexed, one counter per list section (My Tours / Public Tours)
let tourFormTagList = []; // the create/edit form's current tag chips
let tourFormImages = []; // the edit form's current photo list (create form has none yet - a tour needs an id first)
let tourFormTourId = null; // the tour id the open form is editing, or null while creating
let tourFormPendingRouteId = null; // set by route.js's "+ New tour…" shortcut - the route to add once the new tour is saved
let tourFormPendingPhotoUploads = []; // File objects added but not yet uploaded - applied only when Save is clicked
let tourFormPendingPhotoRemovals = []; // ids of existing images marked for removal but not yet deleted - applied only when Save is clicked
let tourFormProcessingPhotoCount = 0; // oversized photos currently running through compressTourPhoto() - rendered as spinner tiles until each resolves
let tourRouteManagerOriginalIds = []; // route ids/order as loaded, to diff against on Save; the manager itself edits tourRouteManagerInTour locally only
let tourRouteManagerTourName = ''; // the tour being edited - the header alone just says "Edit Routes", so this is shown right below it
let tourRouteManagerCandidatePage = 1; // 1-indexed - pagination for the "Add more routes" candidate list below
let tourRouteManagerCandidatePageSize = ADMIN_LIST_DEFAULT_PAGE_SIZE; // config.js - shared with the other admin lists' page-size options

/**
 * Deliberately does NOT call closeMenu() - #touradminmenu's z-index sits
 * above #sidemenu's (style.css, ".cookiemenu opening over the drawer"), so
 * this opens directly on top of the drawer exactly as it currently is
 * (open on whichever screen, or closed) rather than needing to hide it
 * first. closeTourAdminMenu() then needs no matching "reopen the drawer"
 * step either - the drawer was never touched, so it's simply revealed
 * again in the same state once this panel closes. pushMenuLeft() (ui.js)
 * makes a still-open drawer slide fully out of view as this panel slides in
 * over it, the same "pushed left" look navMenuGoTo() gives a nav-screen
 * like Site Settings. slideInPanel()/slideOutPanel() (ui.js) drive the
 * panel's own slide-in-from-the-right transform.
 */
function openTourAdminMenu() {
    slideInPanel('touradminmenu');
    pushMenuLeft();
    panelOpened();
    tourAdminPageSize = ADMIN_LIST_DEFAULT_PAGE_SIZE;
    tourAdminPage = { mine: 1, public: 1 };
    tourFormPendingRouteId = null;
    showTourList();
}

function closeTourAdminMenu() {
    slideOutPanel('touradminmenu');
    unpushMenuLeft();
    panelClosed();
}

/**
 * Shared sticky title bar for every sub-view (list/detail/form/route
 * manager) - replaces the old pattern of a permanent "Tours" <h2> in
 * app.php PLUS each sub-view building its own back-button+heading below
 * it (two stacked headings on every screen but the list). Each render*()
 * function calls this once at the top instead.
 *
 * @param {string} title plain text - the title itself truncates with an
 *        ellipsis via CSS rather than wrapping, so no HTML/badges here.
 * @param {?function} backOnClick called on back-arrow click; the arrow is
 *        hidden entirely when this is null/undefined (root list view).
 * @param {string} [actionHtml] optional right-aligned action button HTML
 *        (list view's "New tour"), empty otherwise.
 */
function setTourAdminHeader(title, backOnClick, actionHtml) {
    document.getElementById('touradminmenu-title').textContent = title;
    var backBtn = document.getElementById('touradminmenu-back');
    backBtn.style.display = backOnClick ? '' : 'none';
    backBtn.onclick = backOnClick || null;
    document.getElementById('touradminmenu-action').innerHTML = actionHtml || '';
}

/* ------------------------------------------------------- Right helpers */

function canCreateTours() {
    return user.id !== null && (user.tour_create === true || user.is_admin === true);
}

function canManageTour(tour) {
    if (user.id === null) return false;
    if (user.is_admin === true || user.tour_manage === true) return true;
    return tour.user_id == user.id && user.tour_create === true;
}

function canPublishTour(tour) {
    if (user.id === null) return false;
    if (user.is_admin === true || user.tour_manage === true) return true;
    return tour.user_id == user.id && user.tour_publish === true;
}

function canCopyTours() {
    return user.id !== null && (user.tour_copy === true || user.is_admin === true);
}

/**
 * Fetches a (possibly private) tour photo through the authenticated Blob
 * endpoint and points the given <img> at it - a plain <img src="..."> can't
 * attach the Bearer token a private tour's photo requires, see
 * api-client.js's fetchBlob().
 */
function renderTourImage(tourId, imageId, imgEl) {
    Ytan.fetchBlob('/tours/' + tourId + '/images/' + imageId).then(blob => {
        imgEl.src = URL.createObjectURL(blob);
    }).catch(err => log('renderTourImage() failed', LOG_ERROR, err));
}

function loadTourImagesInto(container) {
    var imgs = container.querySelectorAll('img[data-tour-id][data-image-id]');
    for (let i = 0; i < imgs.length; i++) {
        renderTourImage(imgs[i].dataset.tourId, imgs[i].dataset.imageId, imgs[i]);
    }
}

/* ---------------------------------------------------------------- List */

function showTourList() {
    var myToken = ++tourAdminRenderToken;

    // Anonymous visitors can still browse public tours (Touren.md: "Alle
    // Benutzer sehen ... öffentliche Touren") - scope=mine_public requires
    // auth and 401s for them, the same fallback route.js's
    // getRoutesByUserId()/getPublicRoutes() split already uses elsewhere.
    var scope = user.id !== null ? 'mine_public' : 'public';

    Ytan.get('/tours?scope=' + scope).then(answer => {
        if (myToken !== tourAdminRenderToken) {
            return; // the user already navigated away from the list before this resolved
        }
        log('showTourList()', LOG_INFO, answer);
        tourAdminAllTours = answer.data;
        renderTourList();
    }).catch(err => {
        log('showTourList() failed', LOG_ERROR, err);
        showToast(t('tour_admin.loading_tours_failed', { error: err.message }), 'error');
    });
}

function renderTourList() {
    var sizeOptions = '';
    for (let i = 0; i < ADMIN_LIST_PAGE_SIZES.length; i++) {
        var size = ADMIN_LIST_PAGE_SIZES[i];
        sizeOptions += '<option value="' + size + '"' + (size === tourAdminPageSize ? ' selected' : '') + '>' + size + '</option>';
    }

    setTourAdminHeader(t('tour_admin.title'), closeTourAdminMenu, canCreateTours() ? '<div class="startbtn" onclick="showTourCreateForm();"><i class="material-icons-round">add</i>&nbsp;' + t('tour_admin.new_tour') + '</div>' : '');

    var html = '<div class="search-bar"><i class="material-icons-round">search</i>' +
            '<input type="text" id="tourSearchInput" placeholder="' + t('tour_admin.search_placeholder') + '" oninput="onTourSearchInput();"></div>' +
        '<div class="tour-list-toolbar-row">' +
            '<div class="tour-length-filter">' +
                '<span class="nav-field-label" style="margin:0;">' + (settings.unit === 'nautical' ? t('common.length_nm') : t('common.length_km')) + '</span>' +
                '<input type="number" min="0" id="tourLengthMin" placeholder="' + t('common.from') + '" value="' + escapeHTML(tourAdminLengthFilter.min) + '" oninput="onTourLengthFilterChange();">' +
                '<span>&ndash;</span>' +
                '<input type="number" min="0" id="tourLengthMax" placeholder="' + t('common.to') + '" value="' + escapeHTML(tourAdminLengthFilter.max) + '" oninput="onTourLengthFilterChange();">' +
            '</div>' +
            '<div class="admin-pagination-size tour-page-size-row">' +
                '<label for="tourPageSize">' + t('common.rows_per_page') + '</label>' +
                '<select id="tourPageSize" class="select-css select-css-compact" onchange="onTourAdminPageSizeChange();">' + sizeOptions + '</select>' +
            '</div>' +
        '</div>' +
        '<div id="tourListResults"></div>';

    document.getElementById('touradminmenu-body').innerHTML = html;
    renderFilteredTourList();
}

function onTourSearchInput() {
    tourAdminPage = { mine: 1, public: 1 }; // the result set is about to change - stay on a page that still exists
    renderFilteredTourList();
}

function onTourLengthFilterChange() {
    tourAdminLengthFilter.min = document.getElementById('tourLengthMin').value;
    tourAdminLengthFilter.max = document.getElementById('tourLengthMax').value;
    tourAdminPage = { mine: 1, public: 1 };
    renderFilteredTourList();
}

function onTourAdminPageSizeChange() {
    tourAdminPageSize = parseInt(document.getElementById('tourPageSize').value, 10);
    tourAdminPage = { mine: 1, public: 1 };
    renderFilteredTourList();
}

function goToTourAdminPage(section, delta) {
    tourAdminPage[section] += delta;
    renderFilteredTourList();
}

function renderFilteredTourList() {
    var input = document.getElementById('tourSearchInput');
    var query = input ? foldSearchText(input.value) : '';
    var minLength = parseFloat(tourAdminLengthFilter.min);
    var maxLength = parseFloat(tourAdminLengthFilter.max);
    var lengthDivisor = settings.unit === 'nautical' ? 1852 : 1000; // matches the "Length (nm)"/"Length (km)" label above

    function matches(tour) {
        if (query !== ''
            && !foldSearchText(tour.name).includes(query)
            && !foldSearchText(tour.description || '').includes(query)
            && !foldSearchText(tour.creator_username || '').includes(query)
        ) {
            return false;
        }

        var length = (tour.total_length || 0) / lengthDivisor;
        if (!isNaN(minLength) && length < minLength) return false;
        if (!isNaN(maxLength) && length > maxLength) return false;

        return true;
    }

    var mine = tourAdminAllTours.filter(tour => user.id !== null && tour.user_id == user.id).filter(matches);
    var pub = tourAdminAllTours.filter(tour => !(user.id !== null && tour.user_id == user.id) && tour.public == 1).filter(matches);

    var html = tourListSectionHtml(t('tour_admin.my_tours'), mine, false, 'mine') + tourListSectionHtml(t('tour_admin.public_tours'), pub, true, 'public');
    document.getElementById('tourListResults').innerHTML = html || '<p class="hint">' + t('tour_admin.no_tours_found') + '</p>';
}

function tourListSectionHtml(title, list, showCreator, sectionKey) {
    if (list.length === 0) {
        return '';
    }

    var totalPages = Math.max(1, Math.ceil(list.length / tourAdminPageSize));
    tourAdminPage[sectionKey] = Math.min(Math.max(1, tourAdminPage[sectionKey]), totalPages);
    var pageStart = (tourAdminPage[sectionKey] - 1) * tourAdminPageSize;
    var pageItems = list.slice(pageStart, pageStart + tourAdminPageSize);

    var html = '<p class="nav-field-label">' + escapeHTML(title) + ' (' + list.length + ')</p>' +
        '<div class="admin-user-table-wrap">';

    for (let i = 0; i < pageItems.length; i++) {
        var tour = pageItems[i];
        var tags = (tour.tags || []).slice(0, 3).map(tag => '<span class="tag-chip">' + escapeHTML(tag) + '</span>').join('');
        if ((tour.tags || []).length > 3) {
            tags += '<span class="tag-chip">+' + (tour.tags.length - 3) + '</span>';
        }

        html += '<div class="tour-list-row" onclick="showTourDetail(' + tour.id + ');">' +
                '<strong>' + escapeHTML(tour.name) + '</strong>' +
                (tour.public == 1 ? ' <span class="admin-badge">' + t('common.public_badge') + '</span>' : '') +
                '<br><span class="tour-list-meta">' + formatDistance(tour.total_length || 0, settings.unit) +
                '  &middot;  ' + (tour.route_count || 0) + ' ' + (tour.route_count == 1 ? t('tour_admin.route_singular') : t('tour_admin.route_plural')) +
                (showCreator ? ' &middot; ' + t('tour_admin.by', { creator: escapeHTML(tour.creator_username) }) : '') + '</span>' +
                (tags ? '<div class="chip-row">' + tags + '</div>' : '') +
            '</div>';
    }

    html += '</div>';

    if (totalPages > 1) {
        var page = tourAdminPage[sectionKey];
        html += '<div class="admin-pagination admin-pagination-section-only">' +
            '<div class="admin-pagination-nav">' +
                '<span class="admin-pagination-nav-btn' + (page <= 1 ? ' disabled' : '') + '" onclick="' + (page > 1 ? "goToTourAdminPage('" + sectionKey + "', -1);" : '') + '"><i class="material-icons-round">chevron_left</i></span>' +
                '<span class="admin-pagination-page">' + t('common.page_of', { page: page, total: totalPages }) + '</span>' +
                '<span class="admin-pagination-nav-btn' + (page >= totalPages ? ' disabled' : '') + '" onclick="' + (page < totalPages ? "goToTourAdminPage('" + sectionKey + "', 1);" : '') + '"><i class="material-icons-round">chevron_right</i></span>' +
            '</div>' +
        '</div>';
    }

    return html;
}

/* -------------------------------------------------------------- Detail */

function showTourDetail(id) {
    var myToken = ++tourAdminRenderToken;

    Promise.all([
        Ytan.get('/tours/' + id),
        Ytan.get('/routes?tour_id=' + id)
    ]).then(([tourAnswer, routesAnswer]) => {
        if (myToken !== tourAdminRenderToken) {
            return;
        }
        renderTourDetail(tourAnswer.data, routesAnswer.data);
    }).catch(err => {
        log('showTourDetail() failed', LOG_ERROR, err);
        showToast(t('tour_admin.loading_tour_failed', { error: err.message }), 'error');
    });
}

function renderTourDetail(tour, routesInTour) {
    setTourAdminHeader(tour.name, showTourList, '');

    var html = '<div class="tour-detail-meta">' +
            (tour.public == 1 ? '<span class="admin-badge">' + t('common.public_badge') + '</span> &middot; ' : '') +
            formatDistance(tour.total_length || 0, settings.unit) + ' &middot; ' +
            routesInTour.length + ' ' + (routesInTour.length == 1 ? t('tour_admin.route_singular') : t('tour_admin.route_plural')) +
            ' &middot; ' + t('tour_admin.by', { creator: escapeHTML(tour.creator_username) }) +
        '</div>';

    if (tour.public == 1 && tour.published_by) {
        html += '<div class="tour-detail-meta">' + t('tour_admin.published') + (tour.published_at ? ' ' + escapeHTML(tour.published_at) : '') + '</div>';
    }

    if ((tour.tags || []).length > 0) {
        html += '<div class="chip-row">' + tour.tags.map(tag => '<span class="tag-chip">' + escapeHTML(tag) + '</span>').join('') + '</div>';
    }

    if (tour.description) {
        html += '<div class="tour-detail-desc">' + marked.parse(tour.description) + '</div>';
    }

    if ((tour.images || []).length > 0) {
        html += '<div class="tour-photo-gallery">';
        for (let i = 0; i < tour.images.length; i++) {
            html += '<img class="tour-photo-gallery-img" data-tour-id="' + tour.id + '" data-image-id="' + tour.images[i].id + '">';
        }
        html += '</div>';
    }

    html += '<p class="nav-field-label">' + t('tour_admin.routes_in_tour') + '</p>';
    if (routesInTour.length === 0) {
        html += '<p class="hint">' + t('tour_admin.no_routes_yet') + '</p>';
    } else {
        html += '<ol class="tour-route-list">';
        for (let i = 0; i < routesInTour.length; i++) {
            html += '<li>' + escapeHTML(routesInTour[i].name) +
                ' <span class="tour-list-meta">' + formatDistance(routesInTour[i].length || 0, settings.unit) + '</span></li>';
        }
        html += '</ol>';
    }

    // onclick is JS source containing a JSON.stringify()'d string argument
    // (embedded double quotes), placed inside a double-quoted HTML
    // attribute - it must be HTML-escaped as a whole (see iconButton() in
    // admin-user.js) or the browser ends the attribute early at the first
    // embedded quote, silently mangling the handler into invalid JS.
    // closeMenu() too, not just closeTourAdminMenu() - the Tours panel now
    // opens directly over a still-open drawer (openTourAdminMenu() no
    // longer calls closeMenu() itself, see its own comment), so closing
    // only the panel would leave the drawer sitting there behind it instead
    // of landing back on the clean map Tour Mode is meant to show.
    var activateTourModeCall = 'activateTourMode(' + tour.id + ', ' + JSON.stringify(tour.name) + '); closeTourAdminMenu(); closeMenu();';
    html += '<div class="tour-detail-actions">' +
        '<div class="nav-btn-primary" onclick="' + escapeHTML(activateTourModeCall) + '">' +
            '<i class="material-icons-round">explore</i>&nbsp;' + t('tour_admin.activate_tour_mode') +
        '</div>' +
        '<div class="tour-action-row">';

    if (canManageTour(tour)) {
        html +=
            '<div class="button" onclick="showTourEditForm(' + tour.id + ');"><i class="material-icons-round">edit</i>&nbsp;' + t('common.edit') + '</div>' +
            '<div class="button" onclick="manageTourRoutes(' + tour.id + ');"><i class="material-icons-round">reorder</i>&nbsp;' + t('tour_admin.edit_routes') + '</div>';
    }
    if (canPublishTour(tour)) {
        html += '<div class="button" onclick="toggleTourPublic(' + tour.id + ', ' + (tour.public == 1 ? 'false' : 'true') + ');"><i class="material-icons-round">public</i>&nbsp;' + (tour.public == 1 ? t('tour_admin.unpublish') : t('tour_admin.publish')) + '</div>';
    }
    if (canCopyTours()) {
        html += '<div class="button" onclick="copyTourRow(' + tour.id + ');"><i class="material-icons-round">content_copy</i>&nbsp;' + t('tour_admin.copy') + '</div>';
    }
    if (canManageTour(tour)) {
        html += '<div class="button" onclick="deleteTourRow(' + tour.id + ');"><i class="material-icons-round">delete</i>&nbsp;' + t('common.delete') + '</div>';
    }

    html += '</div></div>';

    document.getElementById('touradminmenu-body').innerHTML = html;
    loadTourImagesInto(document.getElementById('touradminmenu-body'));
}

function toggleTourPublic(id, makePublic) {
    Ytan.put('/tours/' + id + '/publish', { public: makePublic }).then(() => {
        showTourDetail(id);
    }).catch(err => showToast(t('tour_admin.save_failed', { error: err.message }), 'error'));
}

function copyTourRow(id) {
    Ytan.post('/tours/' + id + '/copy').then(answer => {
        getToursByUserId(user.id);
        showToast(t('tour_admin.tour_copied'), 'success');
        showTourDetail(answer.data.id);
    }).catch(err => showToast(t('tour_admin.copy_failed', { error: err.message }), 'error'));
}

async function deleteTourRow(id) {
    if (!(await showConfirmDialog(t('tour_admin.confirm_delete_tour'), { type: 'danger', confirmLabel: t('common.delete') }))) {
        return;
    }

    Ytan.del('/tours/' + id).then(() => {
        getToursByUserId(user.id);
        showTourList();
    }).catch(err => showToast(t('tour_admin.delete_failed', { error: err.message }), 'error'));
}

/* --------------------------------------------------------- Create/Edit */

/**
 * @param {number|null} [pendingRouteId] when set (route.js's "+ New tour…"
 *        shortcut), the route to add to the tour once it's saved - see
 *        saveNewTour().
 */
function showTourCreateForm(pendingRouteId = null) {
    ++tourAdminRenderToken; // invalidate any still-in-flight list/detail/manager fetch
    tourFormTagList = [];
    tourFormImages = [];
    tourFormTourId = null;
    tourFormPendingRouteId = pendingRouteId;
    tourFormPendingPhotoUploads = [];
    tourFormPendingPhotoRemovals = [];
    tourFormProcessingPhotoCount = 0;
    document.getElementById('touradminmenu-body').innerHTML = tourFormHtml(null);
}

function showTourEditForm(id) {
    var myToken = ++tourAdminRenderToken;

    Ytan.get('/tours/' + id).then(answer => {
        if (myToken !== tourAdminRenderToken) {
            return;
        }
        tourFormTagList = (answer.data.tags || []).slice();
        tourFormImages = answer.data.images || [];
        tourFormTourId = id;
        tourFormPendingPhotoUploads = [];
        tourFormPendingPhotoRemovals = [];
        tourFormProcessingPhotoCount = 0;
        document.getElementById('touradminmenu-body').innerHTML = tourFormHtml(answer.data);
        loadTourImagesInto(document.getElementById('touradminmenu-body'));
    }).catch(err => showToast(t('tour_admin.loading_tour_failed', { error: err.message }), 'error'));
}

function tourFormHtml(tour) {
    tour = tour || { id: null, name: '', description: '' };

    var saveCall = tour.id === null ? 'saveNewTour()' : 'saveEditedTour(' + tour.id + ')';
    var cancelCall = tour.id === null ? 'showTourList()' : 'showTourDetail(' + tour.id + ')';
    var cancelFn = tour.id === null ? showTourList : function () { showTourDetail(tour.id); };
    setTourAdminHeader(tour.id === null ? t('tour_admin.new_tour') : t('tour_admin.edit_tour'), cancelFn, '');

    var html = '<div class="admin-user-form">' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="tourFormName">' + t('tour_admin.name_label') + '</label></div>' +
            '<div class="adminFormField"><input id="tourFormName" type="text" value="' + escapeHTML(tour.name) + '" placeholder="' + t('tour_admin.name_min_chars') + '"></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="tourFormDescription">' + t('tour_admin.description_label') + '</label></div>' +
            '<div class="adminFormField"><textarea id="tourFormDescription" rows="4">' + escapeHTML(tour.description || '') + '</textarea></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="tourFormTagInput">' + t('tour_admin.tags_label') + '</label></div>' +
            '<div class="adminFormField" id="tourFormTagsWrap">' + tourFormTagsInnerHtml() + '</div>' +
        '</div>';

    if (tour.id !== null) {
        // adminFormRowPhotos: the shared .adminFormRow centers its label
        // vertically against the field (align-items:center) - fine for the
        // other rows here (single-line inputs), but not for this one, whose
        // field is a multi-row photo grid + hint text; overridden to
        // top-align "Photos:" with the grid instead, same as Name/
        // Description/Tags already read as aligned with the top of their
        // own fields.
        html += '<div class="adminFormRow adminFormRowPhotos">' +
            '<div class="adminFormLabel">' + t('tour_admin.photos_label') + '</div>' +
            '<div class="adminFormField" id="tourFormPhotosWrap">' + tourFormPhotosInnerHtml() + '</div>' +
        '</div>';
    }

    html += '<p class="hint">' + t('tour_admin.form_hint') + '</p>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel">&nbsp;</div>' +
            '<div class="adminFormField">' +
                '<button id="tourFormSaveBtn" class="button" type="button" onclick="' + saveCall + '">' + t('common.save') + '</button>&nbsp;' +
                '<button class="button" type="button" onclick="' + cancelCall + ';">' + t('common.cancel') + '</button>' +
            '</div>' +
        '</div>' +
        '</div>';

    return html;
}

/* --- Tags (create/edit form) --- */

function tourFormTagsInnerHtml() {
    var html = '<div class="chip-input">';
    for (let i = 0; i < tourFormTagList.length; i++) {
        html += '<span class="tag-chip tag-chip-removable">' + escapeHTML(tourFormTagList[i]) +
            ' <i class="material-icons-round" onclick="removeTourFormTag(' + i + ');">close</i></span>';
    }
    html += '<input type="text" id="tourFormTagInput" placeholder="' + t('tour_admin.add_tag_placeholder') + '" onkeydown="handleTourFormTagKeydown(event);">';
    html += '</div>';

    return html;
}

function refreshTourFormTags() {
    document.getElementById('tourFormTagsWrap').innerHTML = tourFormTagsInnerHtml();
    document.getElementById('tourFormTagInput').focus();
}

function handleTourFormTagKeydown(event) {
    if (event.key !== 'Enter' && event.key !== ',') {
        return;
    }
    event.preventDefault();

    var input = document.getElementById('tourFormTagInput');
    var value = input.value.trim().replace(/,$/, '');
    if (value !== '' && value.length <= 50 && !tourFormTagList.some(tag => tag.toLowerCase() === value.toLowerCase())) {
        tourFormTagList.push(value);
    }
    refreshTourFormTags();
}

function removeTourFormTag(index) {
    tourFormTagList.splice(index, 1);
    refreshTourFormTags();
}

/* --- Photos (edit form only - a tour needs an id first) --- */
// TOUR_PHOTO_MAX_COUNT lives in config.js, alongside the other tour-photo
// tuning constants (TOUR_PHOTO_MAX_BYTES etc.) - purely a UX nicety so the
// "add" tile disappears and stageTourFormPhoto() can stop early instead of
// staging photos the server would reject anyway (mirrors the backend's own
// independent cap, TourController::MAX_IMAGES_PER_TOUR).

function tourFormPhotoTotalCount() {
    var visibleImages = tourFormImages.filter(img => !tourFormPendingPhotoRemovals.includes(img.id));
    return visibleImages.length + tourFormPendingPhotoUploads.length + tourFormProcessingPhotoCount;
}

function tourFormPhotosInnerHtml() {
    var visibleImages = tourFormImages.filter(img => !tourFormPendingPhotoRemovals.includes(img.id));
    var totalCount = tourFormPhotoTotalCount();

    var html = '<div class="tour-photo-grid" id="tourFormPhotoGrid">';
    for (let i = 0; i < visibleImages.length; i++) {
        var img = visibleImages[i];
        html += '<div class="tour-photo-tile">' +
            '<img data-tour-id="' + tourFormTourId + '" data-image-id="' + img.id + '">' +
            '<div class="tour-photo-remove" onclick="removeTourFormImage(' + img.id + ');"><i class="material-icons-round">close</i></div>' +
            '</div>';
    }
    for (let i = 0; i < tourFormPendingPhotoUploads.length; i++) {
        // Not yet uploaded - preview straight from the local File via a blob:
        // URL rather than loadTourImagesInto()'s fetchBlob() path, which is
        // only for photos that already exist on the server.
        html += '<div class="tour-photo-tile tour-photo-tile-pending" title="' + t('tour_admin.pending_photo_title') + '">' +
            '<img src="' + URL.createObjectURL(tourFormPendingPhotoUploads[i]) + '">' +
            '<div class="tour-photo-remove" onclick="removePendingTourFormPhoto(' + i + ');"><i class="material-icons-round">close</i></div>' +
            '</div>';
    }
    // Placeholder tiles for photos currently running through
    // compressTourPhoto() (see stageTourFormPhoto()) - a spinner stands in
    // where the preview will appear once compression resolves, rather than
    // a toast the user could miss if a large photo takes a while.
    for (let i = 0; i < tourFormProcessingPhotoCount; i++) {
        html += '<div class="tour-photo-tile tour-photo-processing" title="' + t('tour_admin.photo_compressing') + '">' +
            '<i class="material-icons-round tour-photo-spinner">autorenew</i>' +
            '<span class="tour-photo-processing-label">' + t('tour_admin.photo_compressing') + '</span>' +
            '</div>';
    }
    if (totalCount < TOUR_PHOTO_MAX_COUNT) {
        html += '<div class="tour-photo-tile tour-photo-add" onclick="document.getElementById(\'tourFormPhotoInput\').click();">' +
            '<i class="material-icons-round">add</i></div>';
    }
    html += '</div>' +
        '<input type="file" id="tourFormPhotoInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none;" onchange="stageTourFormPhoto(event);">' +
        '<p class="hint tour-photo-hint">' + t('tour_admin.photos_hint') + '</p>';

    return html;
}

function refreshTourFormPhotos() {
    document.getElementById('tourFormPhotosWrap').innerHTML = tourFormPhotosInnerHtml();
    loadTourImagesInto(document.getElementById('tourFormPhotosWrap'));
}

/**
 * Stages one or more photos locally (preview only, via blob: URLs) instead
 * of uploading them immediately - the actual POST happens in
 * applyPendingTourPhotoChanges(), only once the form's Save button is
 * clicked, matching the explicit-save behaviour of the rest of this form.
 * The file input has `multiple` set, so a single pick can hand this several
 * files at once (event.target.files is a FileList, not just one File).
 *
 * A file already at/under TOUR_PHOTO_MAX_BYTES is staged and rendered right
 * away - only an oversized one goes through compressTourPhoto() first, so a
 * perfectly fine small PNG never gets needlessly re-encoded to JPEG, and
 * never has to wait behind a slower, larger photo in the same batch.
 * tourFormProcessingPhotoCount tracks how many are still compressing so
 * tourFormPhotosInnerHtml() can render a spinner tile per photo in
 * progress; several oversized files compress concurrently, each with its
 * own spinner tile, rather than one after another. Compression can outlive
 * the form it was started from (user picks a huge photo, then navigates
 * away before it finishes) - myToken mirrors the guard every other async
 * render in this file already uses (see tourAdminRenderToken's own doc
 * comment) so a late result is silently discarded instead of touching a
 * DOM/state that now belongs to a different view.
 */
function stageTourFormPhoto(event) {
    var files = Array.prototype.slice.call(event.target.files);
    event.target.value = ''; // clear either way, so picking the same file(s) again still fires onchange
    if (files.length === 0) {
        return;
    }

    var remainingSlots = TOUR_PHOTO_MAX_COUNT - tourFormPhotoTotalCount();
    if (files.length > remainingSlots) {
        showToast(t('tour_admin.photo_limit_reached', { max: TOUR_PHOTO_MAX_COUNT }), 'warning');
        files = files.slice(0, Math.max(0, remainingSlots));
    }
    if (files.length === 0) {
        return;
    }

    var immediate = files.filter(file => file.size <= TOUR_PHOTO_MAX_BYTES);
    var oversized = files.filter(file => file.size > TOUR_PHOTO_MAX_BYTES);

    immediate.forEach(file => tourFormPendingPhotoUploads.push(file));

    if (oversized.length === 0) {
        refreshTourFormPhotos();
        return;
    }

    var myToken = tourAdminRenderToken;
    tourFormProcessingPhotoCount += oversized.length;
    refreshTourFormPhotos();

    Promise.all(oversized.map(function (file) {
        return compressTourPhoto(file).catch(function (err) {
            log('compressTourPhoto() failed', LOG_ERROR, err);
            return null;
        });
    })).then(function (results) {
        if (myToken !== tourAdminRenderToken) {
            return;
        }

        var failureCount = 0;
        results.forEach(function (result) {
            if (result === null || result.size > TOUR_PHOTO_MAX_BYTES) {
                failureCount++;
                return;
            }
            tourFormPendingPhotoUploads.push(result);
        });
        tourFormProcessingPhotoCount -= oversized.length;
        refreshTourFormPhotos();
        if (failureCount > 0) {
            showToast(t('tour_admin.photo_too_large'), 'error');
        }
    });
}

/**
 * Re-encodes an oversized photo as JPEG, scaling down and/or lowering
 * quality until it fits under TOUR_PHOTO_MAX_BYTES (or the lowest quality/
 * smallest size this is willing to try is reached - callers still check
 * the returned Blob's own .size, this never throws just for "still too
 * big"). Always outputs JPEG regardless of the source format (PNG has no
 * lossy "quality" to reduce via canvas.toBlob() the way JPEG does, so
 * shrinking a large PNG at all requires converting it) - fine for tour
 * photos, which are real photographs rather than graphics needing
 * transparency.
 *
 * @param {File} file
 * @returns {Promise<File>}
 */
function compressTourPhoto(file) {
    return createImageBitmap(file).then(function (bitmap) {
        return compressBitmapRound(bitmap, TOUR_PHOTO_MAX_DIMENSION_PX, 0);
    }).then(function (blob) {
        var baseName = file.name ? file.name.replace(/\.[^.]+$/, '') : 'photo';
        return new File([blob], baseName + '.jpg', { type: 'image/jpeg' });
    });
}

function compressBitmapRound(bitmap, maxDimension, round) {
    var scale = Math.min(1, maxDimension / Math.max(bitmap.width, bitmap.height));
    var canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);

    return tryQualitySteps(canvas, 0).then(function (blob) {
        if (blob.size <= TOUR_PHOTO_MAX_BYTES || round >= TOUR_PHOTO_MAX_DOWNSCALE_ROUNDS) {
            bitmap.close();
            return blob;
        }
        // Still too big even at the lowest quality step - halve the target
        // dimension and try the whole quality ladder again from the top.
        return compressBitmapRound(bitmap, Math.round(maxDimension / 2), round + 1);
    });
}

function tryQualitySteps(canvas, stepIndex) {
    var quality = TOUR_PHOTO_QUALITY_STEPS[Math.min(stepIndex, TOUR_PHOTO_QUALITY_STEPS.length - 1)];
    return new Promise(function (resolve) {
        canvas.toBlob(resolve, 'image/jpeg', quality);
    }).then(function (blob) {
        if (blob.size <= TOUR_PHOTO_MAX_BYTES || stepIndex >= TOUR_PHOTO_QUALITY_STEPS.length - 1) {
            return blob;
        }
        return tryQualitySteps(canvas, stepIndex + 1);
    });
}

function removePendingTourFormPhoto(index) {
    tourFormPendingPhotoUploads.splice(index, 1);
    refreshTourFormPhotos();
}

/**
 * Marks an already-saved photo for removal - the actual DELETE happens in
 * applyPendingTourPhotoChanges() on Save, so a Cancel leaves it untouched.
 */
function removeTourFormImage(imageId) {
    tourFormPendingPhotoRemovals.push(imageId);
    refreshTourFormPhotos();
}

/* --- Save --- */

function readTourForm() {
    return {
        name: document.getElementById('tourFormName').value,
        description: document.getElementById('tourFormDescription').value,
        tags: tourFormTagList,
    };
}

function saveNewTour() {
    if (document.getElementById('tourFormName').value.trim().length < 3) {
        showToast(t('tour_admin.name_too_short'), 'error');
        return;
    }
    document.getElementById('tourFormSaveBtn').disabled = true;

    Ytan.post('/tours', readTourForm()).then(answer => {
        var tourId = answer.data.id;
        getToursByUserId(user.id); // refresh the global tours[] used by the quick-picker and the route popup's tour list

        // Cleared only now that the tour actually exists - if the create
        // request itself fails below, it stays set so a retry still adds it.
        var pendingRouteId = tourFormPendingRouteId;
        tourFormPendingRouteId = null;

        var addPendingRoute = pendingRouteId === null
            ? Promise.resolve()
            : Ytan.post('/tours/' + tourId + '/routes', { route_id: pendingRouteId })
                .catch(err => showToast(t('tour_admin.created_but_add_route_failed', { error: err.message }), 'error'));

        return addPendingRoute.then(() => showTourDetail(tourId));
    }).catch(err => {
        document.getElementById('tourFormSaveBtn').disabled = false;
        showToast(t('tour_admin.save_failed', { error: err.message }), 'error');
    });
}

function saveEditedTour(id) {
    if (document.getElementById('tourFormName').value.trim().length < 3) {
        showToast(t('tour_admin.name_too_short'), 'error');
        return;
    }
    document.getElementById('tourFormSaveBtn').disabled = true;

    Ytan.put('/tours/' + id, readTourForm())
        .then(() => applyPendingTourPhotoChanges(id))
        .then(() => {
            getToursByUserId(user.id);
            showTourDetail(id);
        }).catch(err => {
            document.getElementById('tourFormSaveBtn').disabled = false;
            showToast(t('tour_admin.save_failed', { error: err.message }), 'error');
        });
}

/**
 * Applies the photo add/remove operations staged by stageTourFormPhoto()/
 * removeTourFormImage() while the edit form was open. A failure here is
 * reported but doesn't block the rest of Save - the tour's own fields were
 * already saved by the time this runs.
 */
function applyPendingTourPhotoChanges(tourId) {
    var removals = tourFormPendingPhotoRemovals.map(imageId => Ytan.del('/tours/' + tourId + '/images/' + imageId));
    var uploads = tourFormPendingPhotoUploads.map(file => {
        var formData = new FormData();
        formData.append('image', file);
        return Ytan.postFile('/tours/' + tourId + '/images', formData);
    });

    if (removals.length === 0 && uploads.length === 0) {
        return Promise.resolve();
    }

    return Promise.all(removals.concat(uploads)).catch(err => {
        showToast(t('tour_admin.photo_changes_failed', { error: err.message }), 'error');
    });
}

/* ----------------------------------------- Route manager ("Option B") */

function manageTourRoutes(tourId) {
    tourAdminCurrentTourId = tourId;
    tourRouteManagerCandidatePage = 1;
    tourRouteManagerCandidatePageSize = ADMIN_LIST_DEFAULT_PAGE_SIZE;
    var myToken = ++tourAdminRenderToken;

    Promise.all([
        Ytan.get('/routes?scope=mine'),
        Ytan.get('/routes?tour_id=' + tourId),
        Ytan.get('/tours/' + tourId)
    ]).then(([ownAnswer, tourRoutesAnswer, tourAnswer]) => {
        if (myToken !== tourAdminRenderToken) {
            return;
        }
        tourRouteManagerOwnRoutes = ownAnswer.data;
        tourRouteManagerInTour = tourRoutesAnswer.data;
        tourRouteManagerOriginalIds = tourRouteManagerInTour.map(r => r.id);
        tourRouteManagerTourName = tourAnswer.data.name;
        renderTourRouteManager('');
    }).catch(err => {
        log('manageTourRoutes() failed', LOG_ERROR, err);
        showToast(t('tour_admin.loading_routes_failed', { error: err.message }), 'error');
    });
}

function renderTourRouteManager(searchQuery) {
    var tourId = tourAdminCurrentTourId;
    var totalLength = tourRouteManagerInTour.reduce((sum, r) => sum + (r.length || 0), 0);
    // The sticky header shows the tour's own name rather than a generic
    // "Edit Routes" label - matches renderTourDetail()'s header, and its
    // .cm-panel-title already truncates with an ellipsis instead of
    // wrapping when the name doesn't fit.
    setTourAdminHeader(tourRouteManagerTourName, function () { showTourDetail(tourId); }, '');

    var html = '<p class="tour-route-manager-subtitle">' + t('tour_admin.edit_routes') + '</p>';

    // Save/Cancel sit right under the heading rather than at the very
    // bottom of the view - on a phone, with a long route list, the bottom
    // of this panel can be a long scroll away, which made the buttons hard
    // to reach in practice.
    html += '<div class="tour-action-row tour-route-manager-actions">' +
        '<button id="tourRouteManagerSaveBtn" class="button" type="button" onclick="saveTourRouteManager();">' + t('common.save') + '</button>' +
        '<button class="button" type="button" onclick="cancelTourRouteManager();">' + t('common.cancel') + '</button>' +
    '</div>';

    html += '<p class="nav-field-label">' + t('tour_admin.in_this_tour', { count: tourRouteManagerInTour.length }) + ' &middot; ' + formatDistance(totalLength, settings.unit) + '</p>';
    html += '<div id="tourRouteManagerInTourList"></div>';

    html += '<p class="nav-field-label">' + t('tour_admin.add_more_routes') + '</p>' +
        '<div class="search-bar"><i class="material-icons-round">search</i>' +
            '<input type="text" id="tourRouteSearchInput" placeholder="' + t('tour_admin.search_your_routes') + '" value="' + escapeHTML(searchQuery) + '" oninput="filterTourRouteManager();"></div>' +
        '<div id="tourRouteManagerCandidatesResults"></div>';

    document.getElementById('touradminmenu-body').innerHTML = html;
    renderTourRouteManagerInTourList();
    renderFilteredTourRouteManagerCandidates();
}

/**
 * Renders just the "In this tour" rows (into #tourRouteManagerInTourList) -
 * kept separate from renderTourRouteManager() so a drag-reorder can re-render
 * only this list on every pointermove instead of rebuilding the whole panel
 * (Save/Cancel row, search box, candidates list) on every frame of the drag.
 */
function renderTourRouteManagerInTourList() {
    var container = document.getElementById('tourRouteManagerInTourList');
    if (!container) {
        return;
    }

    if (tourRouteManagerInTour.length === 0) {
        container.innerHTML = '<p class="hint">' + t('tour_admin.no_routes_added_yet') + '</p>';
        return;
    }

    var html = '<div class="tour-route-manager-list">';
    for (let i = 0; i < tourRouteManagerInTour.length; i++) {
        var r = tourRouteManagerInTour[i];
        var dragging = tourRouteDragState && tourRouteDragState.routeId === r.id;
        html += '<div class="tour-route-manager-row tour-route-manager-row-selected' + (dragging ? ' tour-route-manager-row-dragging' : '') + '">' +
            '<i class="material-icons-round tour-route-manager-handle" onpointerdown="startRouteDrag(event, ' + r.id + ');">drag_indicator</i>' +
            '<span class="tour-route-manager-name">' + escapeHTML(r.name) + '</span>' +
            '<span class="tour-list-meta">' + formatDistance(r.length || 0, settings.unit) + '</span>' +
            '<i class="material-icons-round tour-route-manager-remove" title="' + t('tour_admin.remove_from_tour_title') + '" onclick="removeRouteFromTour(' + r.id + ');">close</i>' +
            '</div>';
    }
    html += '</div>';

    container.innerHTML = html;
}

/**
 * Renders just the "Add more routes" candidate rows + their pagination bar
 * (into #tourRouteManagerCandidatesResults) from the already-loaded
 * tourRouteManagerOwnRoutes, filtered client-side by the live search input
 * and paged by tourRouteManagerCandidatePage/PageSize - kept separate from
 * renderTourRouteManager() so typing in the search box never rebuilds the
 * search input itself (which would steal focus/cursor position after every
 * keystroke), the same split renderFilteredTourList()/renderFilteredUserRows()
 * already use for their own search boxes.
 */
function renderFilteredTourRouteManagerCandidates() {
    var searchInput = document.getElementById('tourRouteSearchInput');
    var query = foldSearchText(searchInput ? searchInput.value : '');
    var inTourIds = tourRouteManagerInTour.map(r => r.id);
    var allCandidates = tourRouteManagerOwnRoutes.filter(r => !inTourIds.includes(r.id) && (query === '' || foldSearchText(r.name).includes(query)));

    var totalPages = Math.max(1, Math.ceil(allCandidates.length / tourRouteManagerCandidatePageSize));
    tourRouteManagerCandidatePage = Math.min(Math.max(1, tourRouteManagerCandidatePage), totalPages);
    var pageStart = (tourRouteManagerCandidatePage - 1) * tourRouteManagerCandidatePageSize;
    var candidates = allCandidates.slice(pageStart, pageStart + tourRouteManagerCandidatePageSize);

    var html = '<div class="tour-route-manager-list">';
    if (candidates.length === 0) {
        html += '<p class="hint">' + t('tour_admin.no_matching_routes') + '</p>';
    } else {
        for (let i = 0; i < candidates.length; i++) {
            var c = candidates[i];
            html += '<div class="tour-route-manager-row">' +
                '<span class="tour-route-manager-name">' + escapeHTML(c.name) + '</span>' +
                '<span class="tour-list-meta">' + formatDistance(c.length || 0, settings.unit) + '</span>' +
                '<i class="material-icons-round tour-route-manager-add" title="' + t('common.add_to_tour') + '" onclick="addRouteToTour(' + c.id + ');">add_circle</i>' +
                '</div>';
        }
    }
    html += '</div>';

    html += tourRouteManagerCandidatePaginationHtml(allCandidates.length, totalPages);

    document.getElementById('tourRouteManagerCandidatesResults').innerHTML = html;
}

/**
 * Mirrors adminUserPaginationHtml() (admin-user.js) / the Tours list's own
 * pagination block - same page-size dropdown (ADMIN_LIST_PAGE_SIZES) and
 * prev/next nav, just scoped to this one "Add more routes" candidate list
 * instead of a two-section split.
 */
function tourRouteManagerCandidatePaginationHtml(totalMatches, totalPages) {
    var sizeOptions = '';
    for (let i = 0; i < ADMIN_LIST_PAGE_SIZES.length; i++) {
        var size = ADMIN_LIST_PAGE_SIZES[i];
        sizeOptions += '<option value="' + size + '"' + (size === tourRouteManagerCandidatePageSize ? ' selected' : '') + '>' + size + '</option>';
    }

    return '<div class="admin-pagination">' +
        '<div class="admin-pagination-size">' +
            '<label for="tourRouteCandidatePageSize">' + t('common.rows_per_page') + '</label>' +
            '<select id="tourRouteCandidatePageSize" class="select-css select-css-compact" onchange="onTourRouteManagerCandidatePageSizeChange();">' + sizeOptions + '</select>' +
        '</div>' +
        '<div class="admin-pagination-nav">' +
            '<span class="admin-pagination-nav-btn' + (tourRouteManagerCandidatePage <= 1 ? ' disabled' : '') + '" onclick="' + (tourRouteManagerCandidatePage > 1 ? 'goToTourRouteManagerCandidatePage(-1);' : '') + '"><i class="material-icons-round">chevron_left</i></span>' +
            '<span class="admin-pagination-page">' + (totalMatches === 0 ? t('tour_admin.zero_routes') : t('common.page_of', { page: tourRouteManagerCandidatePage, total: totalPages })) + '</span>' +
            '<span class="admin-pagination-nav-btn' + (tourRouteManagerCandidatePage >= totalPages ? ' disabled' : '') + '" onclick="' + (tourRouteManagerCandidatePage < totalPages ? 'goToTourRouteManagerCandidatePage(1);' : '') + '"><i class="material-icons-round">chevron_right</i></span>' +
        '</div>' +
        '</div>';
}

function filterTourRouteManager() {
    tourRouteManagerCandidatePage = 1; // the result set is about to change - stay on a page that still exists
    renderFilteredTourRouteManagerCandidates();
}

function onTourRouteManagerCandidatePageSizeChange() {
    tourRouteManagerCandidatePageSize = parseInt(document.getElementById('tourRouteCandidatePageSize').value, 10);
    tourRouteManagerCandidatePage = 1;
    renderFilteredTourRouteManagerCandidates();
}

function goToTourRouteManagerCandidatePage(delta) {
    tourRouteManagerCandidatePage += delta;
    renderFilteredTourRouteManagerCandidates();
}

/**
 * addRouteToTour()/removeRouteFromTour()/the drag-reorder handlers only edit
 * the local tourRouteManagerInTour buffer and re-render - nothing is sent to the
 * server until saveTourRouteManager() runs (Save button), so a Cancel (or
 * the back arrow) can discard every change made in this view for free.
 */
function addRouteToTour(routeId) {
    var route = tourRouteManagerOwnRoutes.find(r => r.id === routeId);
    if (!route || tourRouteManagerInTour.some(r => r.id === routeId)) {
        return;
    }
    tourRouteManagerInTour.push(route);
    renderTourRouteManager(document.getElementById('tourRouteSearchInput') ? document.getElementById('tourRouteSearchInput').value : '');
}

function removeRouteFromTour(routeId) {
    tourRouteManagerInTour = tourRouteManagerInTour.filter(r => r.id !== routeId);
    renderTourRouteManager(document.getElementById('tourRouteSearchInput') ? document.getElementById('tourRouteSearchInput').value : '');
}

/**
 * Drag-to-reorder for the "In this tour" list, via Pointer Events (unifies
 * mouse/touch/pen - a native HTML5 draggable wouldn't fire on touch at all,
 * which matters since this app is mobile-first). tourRouteDragState tracks
 * the one active drag; a floating "ghost" clone (appended straight to
 * <body>, not into the re-rendered list) follows the pointer and holds
 * pointer capture for the whole gesture, so it keeps receiving move/up
 * events even though renderTourRouteManagerInTourList() rebuilds the actual
 * rows underneath it on every reorder.
 */
let tourRouteDragState = null; // { pointerId, routeId, ghost, offsetY }

function startRouteDrag(event, routeId) {
    if (event.pointerType === 'mouse' && event.button !== 0) {
        return;
    }

    var row = event.currentTarget.closest('.tour-route-manager-row');
    if (!row) {
        return;
    }
    var rect = row.getBoundingClientRect();

    var ghost = row.cloneNode(true);
    ghost.classList.add('tour-route-manager-ghost');
    ghost.style.width = rect.width + 'px';
    ghost.style.left = rect.left + 'px';
    ghost.style.top = rect.top + 'px';
    document.body.appendChild(ghost);

    tourRouteDragState = {
        pointerId: event.pointerId,
        routeId: routeId,
        ghost: ghost,
        offsetY: event.clientY - rect.top
    };

    ghost.setPointerCapture(event.pointerId);
    ghost.addEventListener('pointermove', onRouteDragMove);
    ghost.addEventListener('pointerup', endRouteDrag);
    ghost.addEventListener('pointercancel', endRouteDrag);

    renderTourRouteManagerInTourList();
    event.preventDefault();
}

function onRouteDragMove(event) {
    if (!tourRouteDragState || event.pointerId !== tourRouteDragState.pointerId) {
        return;
    }
    var state = tourRouteDragState;
    state.ghost.style.top = (event.clientY - state.offsetY) + 'px';

    var container = document.getElementById('tourRouteManagerInTourList');
    if (!container) {
        return;
    }
    var rows = Array.from(container.querySelectorAll('.tour-route-manager-row-selected'));
    var fromIndex = tourRouteManagerInTour.findIndex(r => r.id === state.routeId);
    if (fromIndex === -1) {
        return;
    }

    var targetIndex = rows.length - 1;
    for (let i = 0; i < rows.length; i++) {
        var r = rows[i].getBoundingClientRect();
        if (event.clientY < r.top + r.height / 2) {
            targetIndex = i;
            break;
        }
    }

    if (targetIndex !== fromIndex) {
        var item = tourRouteManagerInTour.splice(fromIndex, 1)[0];
        tourRouteManagerInTour.splice(targetIndex, 0, item);
        renderTourRouteManagerInTourList();
    }
}

function endRouteDrag(event) {
    if (!tourRouteDragState || event.pointerId !== tourRouteDragState.pointerId) {
        return;
    }
    var state = tourRouteDragState;
    state.ghost.removeEventListener('pointermove', onRouteDragMove);
    state.ghost.removeEventListener('pointerup', endRouteDrag);
    state.ghost.removeEventListener('pointercancel', endRouteDrag);
    state.ghost.remove();
    tourRouteDragState = null;
    renderTourRouteManagerInTourList();
}

/**
 * Diffs tourRouteManagerInTour against the snapshot taken when the manager
 * was opened (tourRouteManagerOriginalIds) and applies exactly the API
 * calls needed: POST for newly added routes, DELETE for removed ones, then
 * PUT .../routes/order with the full final id list so the server-side order
 * always matches the buffer regardless of where adds/removes landed it.
 */
function saveTourRouteManager() {
    var tourId = tourAdminCurrentTourId;
    var finalIds = tourRouteManagerInTour.map(r => r.id);
    var addedIds = finalIds.filter(id => !tourRouteManagerOriginalIds.includes(id));
    var removedIds = tourRouteManagerOriginalIds.filter(id => !finalIds.includes(id));
    var orderChanged = finalIds.length !== tourRouteManagerOriginalIds.length
        || finalIds.some((id, i) => id !== tourRouteManagerOriginalIds[i]);

    document.getElementById('tourRouteManagerSaveBtn').disabled = true;

    var addCalls = addedIds.map(id => Ytan.post('/tours/' + tourId + '/routes', { route_id: id }));
    var removeCalls = removedIds.map(id => Ytan.del('/tours/' + tourId + '/routes/' + id));

    Promise.all(addCalls.concat(removeCalls)).then(() => {
        return orderChanged && finalIds.length > 0
            ? Ytan.put('/tours/' + tourId + '/routes/order', { route_ids: finalIds })
            : Promise.resolve();
    }).then(() => {
        showToast(t('tour_admin.saved'), 'success');
        showTourDetail(tourId);
    }).catch(err => {
        document.getElementById('tourRouteManagerSaveBtn').disabled = false;
        showToast(t('tour_admin.save_failed', { error: err.message }), 'error');
    });
}

function cancelTourRouteManager() {
    showTourDetail(tourAdminCurrentTourId);
}
