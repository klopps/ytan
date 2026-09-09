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

function openTourAdminMenu() {
    closeMenu();
    document.getElementById('touradminmenu').style.width = '100%';
    document.getElementById('touradminmenu-close-btn').style.display = 'flex';
    panelOpened();
    tourAdminPageSize = ADMIN_LIST_DEFAULT_PAGE_SIZE;
    tourAdminPage = { mine: 1, public: 1 };
    tourFormPendingRouteId = null;
    showTourList();
}

function closeTourAdminMenu() {
    document.getElementById('touradminmenu').style.width = '0%';
    document.getElementById('touradminmenu-close-btn').style.display = 'none';
    panelClosed();
}

/* ------------------------------------------------------- Right helpers */

function canCreateTours() {
    return user.id !== null && (user.tour_create === true || user.is_admin === true);
}

function canManageTour(t) {
    if (user.id === null) return false;
    if (user.is_admin === true || user.tour_manage === true) return true;
    return t.user_id == user.id && user.tour_create === true;
}

function canPublishTour(t) {
    if (user.id === null) return false;
    if (user.is_admin === true || user.tour_manage === true) return true;
    return t.user_id == user.id && user.tour_publish === true;
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
        showToast('Loading tours failed: ' + err.message, 'error');
    });
}

function renderTourList() {
    var sizeOptions = '';
    for (let i = 0; i < ADMIN_LIST_PAGE_SIZES.length; i++) {
        var size = ADMIN_LIST_PAGE_SIZES[i];
        sizeOptions += '<option value="' + size + '"' + (size === tourAdminPageSize ? ' selected' : '') + '>' + size + '</option>';
    }

    var html = '<div class="admin-panel-header">' +
        (canCreateTours() ? '<div class="startbtn" onclick="showTourCreateForm();"><i class="material-icons-round">add</i>&nbsp;New tour</div>' : '') +
        '</div>' +
        '<div class="search-bar"><i class="material-icons-round">search</i>' +
            '<input type="text" id="tourSearchInput" placeholder="Search by name, description or creator" oninput="onTourSearchInput();"></div>' +
        '<div class="tour-length-filter">' +
            '<span class="nav-field-label" style="margin:0;">' + (settings.unit === 'nautical' ? 'Length (nm)' : 'Length (km)') + '</span>' +
            '<input type="number" min="0" id="tourLengthMin" placeholder="From" value="' + escapeHTML(tourAdminLengthFilter.min) + '" oninput="onTourLengthFilterChange();">' +
            '<span>&ndash;</span>' +
            '<input type="number" min="0" id="tourLengthMax" placeholder="To" value="' + escapeHTML(tourAdminLengthFilter.max) + '" oninput="onTourLengthFilterChange();">' +
        '</div>' +
        '<div class="admin-pagination-size tour-page-size-row">' +
            '<label for="tourPageSize">Rows per page:</label>' +
            '<select id="tourPageSize" class="select-css select-css-compact" onchange="onTourAdminPageSizeChange();">' + sizeOptions + '</select>' +
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

    function matches(t) {
        if (query !== ''
            && !foldSearchText(t.name).includes(query)
            && !foldSearchText(t.description || '').includes(query)
            && !foldSearchText(t.creator_username || '').includes(query)
        ) {
            return false;
        }

        var length = (t.total_length || 0) / lengthDivisor;
        if (!isNaN(minLength) && length < minLength) return false;
        if (!isNaN(maxLength) && length > maxLength) return false;

        return true;
    }

    var mine = tourAdminAllTours.filter(t => user.id !== null && t.user_id == user.id).filter(matches);
    var pub = tourAdminAllTours.filter(t => !(user.id !== null && t.user_id == user.id) && t.public == 1).filter(matches);

    var html = tourListSectionHtml('My Tours', mine, false, 'mine') + tourListSectionHtml('Public Tours', pub, true, 'public');
    document.getElementById('tourListResults').innerHTML = html || '<p class="hint">No tours found.</p>';
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
        '<div class="admin-user-table-wrap"><table class="admin-user-table"><tbody>';

    for (let i = 0; i < pageItems.length; i++) {
        var t = pageItems[i];
        var tags = (t.tags || []).slice(0, 3).map(tag => '<span class="tag-chip">' + escapeHTML(tag) + '</span>').join('');
        if ((t.tags || []).length > 3) {
            tags += '<span class="tag-chip">+' + (t.tags.length - 3) + '</span>';
        }

        html += '<tr class="tour-list-row" onclick="showTourDetail(' + t.id + ');">' +
            '<td data-label="Tour">' +
                '<strong>' + escapeHTML(t.name) + '</strong>' +
                (t.public == 1 ? ' <span class="admin-badge">Public</span>' : '') +
                '<br><span class="tour-list-meta">' + formatDistance(t.total_length || 0, settings.unit) +
                (showCreator ? ' &middot; by ' + escapeHTML(t.creator_username) : '') + '</span>' +
                (tags ? '<div class="chip-row">' + tags + '</div>' : '') +
            '</td>' +
            '<td class="actions"><i class="material-icons-round">chevron_right</i></td>' +
            '</tr>';
    }

    html += '</tbody></table></div>';

    if (totalPages > 1) {
        var page = tourAdminPage[sectionKey];
        html += '<div class="admin-pagination admin-pagination-section-only">' +
            '<div class="admin-pagination-nav">' +
                '<span class="admin-pagination-nav-btn' + (page <= 1 ? ' disabled' : '') + '" onclick="' + (page > 1 ? "goToTourAdminPage('" + sectionKey + "', -1);" : '') + '"><i class="material-icons-round">chevron_left</i></span>' +
                '<span class="admin-pagination-page">Page ' + page + ' of ' + totalPages + '</span>' +
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
        showToast('Loading tour failed: ' + err.message, 'error');
    });
}

function renderTourDetail(t, routesInTour) {
    var html = '<button type="button" class="nav-back tour-detail-back" onclick="showTourList();"><i class="material-icons-round">arrow_back</i></button>' +
        '<h3 class="tour-detail-name">' + escapeHTML(t.name) +
            (t.public == 1 ? ' <span class="admin-badge">Public</span>' : '') +
        '</h3>' +
        '<div class="tour-detail-meta">' + formatDistance(t.total_length || 0, settings.unit) + ' &middot; ' +
            routesInTour.length + (routesInTour.length == 1 ? ' route' : ' routes') +
            ' &middot; by ' + escapeHTML(t.creator_username) +
        '</div>';

    if (t.public == 1 && t.published_by) {
        html += '<div class="tour-detail-meta">Published' + (t.published_at ? ' ' + escapeHTML(t.published_at) : '') + '</div>';
    }

    if ((t.tags || []).length > 0) {
        html += '<div class="chip-row">' + t.tags.map(tag => '<span class="tag-chip">' + escapeHTML(tag) + '</span>').join('') + '</div>';
    }

    if (t.description) {
        html += '<div class="tour-detail-desc">' + marked.parse(t.description) + '</div>';
    }

    if ((t.images || []).length > 0) {
        html += '<div class="tour-photo-gallery">';
        for (let i = 0; i < t.images.length; i++) {
            html += '<img class="tour-photo-gallery-img" data-tour-id="' + t.id + '" data-image-id="' + t.images[i].id + '">';
        }
        html += '</div>';
    }

    html += '<p class="nav-field-label">Routes in this tour</p>';
    if (routesInTour.length === 0) {
        html += '<p class="hint">This tour has no routes yet.</p>';
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
    var activateTourModeCall = 'activateTourMode(' + t.id + ', ' + JSON.stringify(t.name) + '); closeTourAdminMenu();';
    html += '<div class="tour-detail-actions">' +
        '<div class="nav-btn-primary" onclick="' + escapeHTML(activateTourModeCall) + '">' +
            '<i class="material-icons-round">explore</i>&nbsp;Activate Tour Mode' +
        '</div>' +
        '<div class="tour-action-row">';

    if (canManageTour(t)) {
        html +=
            '<div class="button" onclick="showTourEditForm(' + t.id + ');"><i class="material-icons-round">edit</i>&nbsp;Edit</div>' +
            '<div class="button" onclick="manageTourRoutes(' + t.id + ');"><i class="material-icons-round">reorder</i>&nbsp;Edit Routes</div>';
    }
    if (canPublishTour(t)) {
        html += '<div class="button" onclick="toggleTourPublic(' + t.id + ', ' + (t.public == 1 ? 'false' : 'true') + ');"><i class="material-icons-round">public</i>&nbsp;' + (t.public == 1 ? 'Unpublish' : 'Publish') + '</div>';
    }
    if (canCopyTours()) {
        html += '<div class="button" onclick="copyTourRow(' + t.id + ');"><i class="material-icons-round">content_copy</i>&nbsp;Copy</div>';
    }
    if (canManageTour(t)) {
        html += '<div class="button" onclick="deleteTourRow(' + t.id + ');"><i class="material-icons-round">delete</i>&nbsp;Delete</div>';
    }

    html += '</div></div>';

    document.getElementById('touradminmenu-body').innerHTML = html;
    loadTourImagesInto(document.getElementById('touradminmenu-body'));
}

function toggleTourPublic(id, makePublic) {
    Ytan.put('/tours/' + id + '/publish', { public: makePublic }).then(() => {
        showTourDetail(id);
    }).catch(err => showToast('Save failed: ' + err.message, 'error'));
}

function copyTourRow(id) {
    Ytan.post('/tours/' + id + '/copy').then(answer => {
        getToursByUserId(user.id);
        showToast('Tour copied.', 'success');
        showTourDetail(answer.data.id);
    }).catch(err => showToast('Copy failed: ' + err.message, 'error'));
}

async function deleteTourRow(id) {
    if (!(await showConfirmDialog('Do you really want to delete this tour? Its routes will not be deleted.', { type: 'danger', confirmLabel: 'Delete' }))) {
        return;
    }

    Ytan.del('/tours/' + id).then(() => {
        getToursByUserId(user.id);
        showTourList();
    }).catch(err => showToast('Delete failed: ' + err.message, 'error'));
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
        document.getElementById('touradminmenu-body').innerHTML = tourFormHtml(answer.data);
        loadTourImagesInto(document.getElementById('touradminmenu-body'));
    }).catch(err => showToast('Loading tour failed: ' + err.message, 'error'));
}

function tourFormHtml(t) {
    t = t || { id: null, name: '', description: '' };

    var saveCall = t.id === null ? 'saveNewTour()' : 'saveEditedTour(' + t.id + ')';
    var cancelCall = t.id === null ? 'showTourList()' : 'showTourDetail(' + t.id + ')';

    var html = '<button type="button" class="nav-back tour-detail-back" onclick="' + cancelCall + ';"><i class="material-icons-round">arrow_back</i></button>' +
        '<div class="admin-user-form">' +
        '<h3>' + (t.id === null ? 'New tour' : 'Edit tour') + '</h3>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="tourFormName">Name: </label></div>' +
            '<div class="adminFormField"><input id="tourFormName" type="text" value="' + escapeHTML(t.name) + '" placeholder="At least 3 characters"></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="tourFormDescription">Description: </label></div>' +
            '<div class="adminFormField"><textarea id="tourFormDescription" rows="4">' + escapeHTML(t.description || '') + '</textarea></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="tourFormTagInput">Tags: </label></div>' +
            '<div class="adminFormField" id="tourFormTagsWrap">' + tourFormTagsInnerHtml() + '</div>' +
        '</div>';

    if (t.id !== null) {
        html += '<div class="adminFormRow">' +
            '<div class="adminFormLabel">Photos:</div>' +
            '<div class="adminFormField" id="tourFormPhotosWrap">' + tourFormPhotosInnerHtml() + '</div>' +
        '</div>';
    }

    html += '<p class="hint">Publishing this tour and managing its routes happen from the tour’s detail page, not here.</p>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel">&nbsp;</div>' +
            '<div class="adminFormField">' +
                '<button id="tourFormSaveBtn" class="button" type="button" onclick="' + saveCall + '">Save</button>&nbsp;' +
                '<button class="button" type="button" onclick="' + cancelCall + ';">Cancel</button>' +
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
    html += '<input type="text" id="tourFormTagInput" placeholder="+ Add tag" onkeydown="handleTourFormTagKeydown(event);">';
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
    if (value !== '' && value.length <= 50 && !tourFormTagList.some(t => t.toLowerCase() === value.toLowerCase())) {
        tourFormTagList.push(value);
    }
    refreshTourFormTags();
}

function removeTourFormTag(index) {
    tourFormTagList.splice(index, 1);
    refreshTourFormTags();
}

/* --- Photos (edit form only - a tour needs an id first) --- */

function tourFormPhotosInnerHtml() {
    var html = '<div class="tour-photo-grid" id="tourFormPhotoGrid">';
    for (let i = 0; i < tourFormImages.length; i++) {
        var img = tourFormImages[i];
        html += '<div class="tour-photo-tile">' +
            '<img data-tour-id="' + tourFormTourId + '" data-image-id="' + img.id + '">' +
            '<div class="tour-photo-remove" onclick="removeTourFormImage(' + img.id + ');"><i class="material-icons-round">close</i></div>' +
            '</div>';
    }
    if (tourFormImages.length < 8) {
        html += '<div class="tour-photo-tile tour-photo-add" onclick="document.getElementById(\'tourFormPhotoInput\').click();">' +
            '<i class="material-icons-round">add</i></div>';
    }
    html += '</div>' +
        '<input type="file" id="tourFormPhotoInput" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="uploadTourFormPhoto(event);">' +
        '<p class="hint">Up to 8 photos, 5 MB each.</p>';

    return html;
}

function refreshTourFormPhotos() {
    document.getElementById('tourFormPhotosWrap').innerHTML = tourFormPhotosInnerHtml();
    loadTourImagesInto(document.getElementById('tourFormPhotosWrap'));
}

function uploadTourFormPhoto(event) {
    var file = event.target.files[0];
    if (!file) {
        return;
    }

    var formData = new FormData();
    formData.append('image', file);

    Ytan.postFile('/tours/' + tourFormTourId + '/images', formData).then(answer => {
        tourFormImages = answer.data;
        refreshTourFormPhotos();
    }).catch(err => showToast('Uploading photo failed: ' + err.message, 'error'));

    event.target.value = '';
}

function removeTourFormImage(imageId) {
    Ytan.del('/tours/' + tourFormTourId + '/images/' + imageId).then(answer => {
        tourFormImages = answer.data;
        refreshTourFormPhotos();
    }).catch(err => showToast('Removing photo failed: ' + err.message, 'error'));
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
        showToast('Name must be at least 3 characters.', 'error');
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
                .catch(err => showToast('Tour created, but adding the route failed: ' + err.message, 'error'));

        return addPendingRoute.then(() => showTourDetail(tourId));
    }).catch(err => {
        document.getElementById('tourFormSaveBtn').disabled = false;
        showToast('Save failed: ' + err.message, 'error');
    });
}

function saveEditedTour(id) {
    if (document.getElementById('tourFormName').value.trim().length < 3) {
        showToast('Name must be at least 3 characters.', 'error');
        return;
    }
    document.getElementById('tourFormSaveBtn').disabled = true;

    Ytan.put('/tours/' + id, readTourForm()).then(() => {
        getToursByUserId(user.id);
        showTourDetail(id);
    }).catch(err => {
        document.getElementById('tourFormSaveBtn').disabled = false;
        showToast('Save failed: ' + err.message, 'error');
    });
}

/* ----------------------------------------- Route manager ("Option B") */

function manageTourRoutes(tourId) {
    tourAdminCurrentTourId = tourId;
    var myToken = ++tourAdminRenderToken;

    Promise.all([
        Ytan.get('/routes?scope=mine'),
        Ytan.get('/routes?tour_id=' + tourId)
    ]).then(([ownAnswer, tourRoutesAnswer]) => {
        if (myToken !== tourAdminRenderToken) {
            return;
        }
        tourRouteManagerOwnRoutes = ownAnswer.data;
        tourRouteManagerInTour = tourRoutesAnswer.data;
        renderTourRouteManager('');
    }).catch(err => {
        log('manageTourRoutes() failed', LOG_ERROR, err);
        showToast('Loading routes failed: ' + err.message, 'error');
    });
}

function renderTourRouteManager(searchQuery) {
    var tourId = tourAdminCurrentTourId;
    var inTourIds = tourRouteManagerInTour.map(r => r.id);
    var totalLength = tourRouteManagerInTour.reduce((sum, r) => sum + (r.length || 0), 0);

    var html = '<button type="button" class="nav-back tour-detail-back" onclick="showTourDetail(' + tourId + ');"><i class="material-icons-round">arrow_back</i></button>' +
        '<h3>Edit Routes</h3>';

    html += '<p class="nav-field-label">In this tour (' + tourRouteManagerInTour.length + ') &middot; ' + formatDistance(totalLength, settings.unit) + '</p>';

    if (tourRouteManagerInTour.length === 0) {
        html += '<p class="hint">No routes yet - add some below.</p>';
    } else {
        html += '<div class="tour-route-manager-list">';
        for (let i = 0; i < tourRouteManagerInTour.length; i++) {
            var r = tourRouteManagerInTour[i];
            html += '<div class="tour-route-manager-row tour-route-manager-row-selected">' +
                '<span class="tour-route-manager-order">' +
                    '<i class="material-icons-round' + (i === 0 ? ' tour-route-manager-order-disabled' : '') + '" onclick="reorderTourRoute(' + i + ', -1);">arrow_upward</i>' +
                    '<i class="material-icons-round' + (i === tourRouteManagerInTour.length - 1 ? ' tour-route-manager-order-disabled' : '') + '" onclick="reorderTourRoute(' + i + ', 1);">arrow_downward</i>' +
                '</span>' +
                '<span class="tour-route-manager-name">' + escapeHTML(r.name) + '</span>' +
                '<span class="tour-list-meta">' + formatDistance(r.length || 0, settings.unit) + '</span>' +
                '<i class="material-icons-round tour-route-manager-remove" title="Remove from tour" onclick="removeRouteFromTour(' + r.id + ');">close</i>' +
                '</div>';
        }
        html += '</div>';
    }

    html += '<p class="nav-field-label">Add more routes</p>' +
        '<div class="search-bar"><i class="material-icons-round">search</i>' +
            '<input type="text" id="tourRouteSearchInput" placeholder="Search your routes…" value="' + escapeHTML(searchQuery) + '" oninput="filterTourRouteManager();"></div>';

    var query = foldSearchText(searchQuery);
    var candidates = tourRouteManagerOwnRoutes.filter(r => !inTourIds.includes(r.id) && (query === '' || foldSearchText(r.name).includes(query)));

    html += '<div class="tour-route-manager-list">';
    if (candidates.length === 0) {
        html += '<p class="hint">No matching routes.</p>';
    } else {
        for (let i = 0; i < candidates.length; i++) {
            var c = candidates[i];
            html += '<div class="tour-route-manager-row">' +
                '<span class="tour-route-manager-name">' + escapeHTML(c.name) + '</span>' +
                '<span class="tour-list-meta">' + formatDistance(c.length || 0, settings.unit) + '</span>' +
                '<i class="material-icons-round tour-route-manager-add" title="Add to tour" onclick="addRouteToTour(' + c.id + ');">add_circle</i>' +
                '</div>';
        }
    }
    html += '</div>';

    html += '<div class="nav-btn-primary" onclick="showTourDetail(' + tourId + ');">Done</div>';

    document.getElementById('touradminmenu-body').innerHTML = html;
}

function filterTourRouteManager() {
    renderTourRouteManager(document.getElementById('tourRouteSearchInput').value);
}

function addRouteToTour(routeId) {
    Ytan.post('/tours/' + tourAdminCurrentTourId + '/routes', { route_id: routeId }).then(() => {
        manageTourRoutes(tourAdminCurrentTourId);
    }).catch(err => showToast('Adding route failed: ' + err.message, 'error'));
}

function removeRouteFromTour(routeId) {
    Ytan.del('/tours/' + tourAdminCurrentTourId + '/routes/' + routeId).then(() => {
        manageTourRoutes(tourAdminCurrentTourId);
    }).catch(err => showToast('Removing route failed: ' + err.message, 'error'));
}

function reorderTourRoute(index, direction) {
    var newIndex = index + direction;
    if (newIndex < 0 || newIndex >= tourRouteManagerInTour.length) {
        return;
    }

    var ids = tourRouteManagerInTour.map(r => r.id);
    var tmp = ids[index];
    ids[index] = ids[newIndex];
    ids[newIndex] = tmp;

    Ytan.put('/tours/' + tourAdminCurrentTourId + '/routes/order', { route_ids: ids }).then(() => {
        manageTourRoutes(tourAdminCurrentTourId);
    }).catch(err => showToast('Reordering failed: ' + err.message, 'error'));
}
