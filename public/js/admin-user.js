/**
 * Admin-only user management panel (list / create / edit / delete users,
 * plus per-user password actions). Lives on the standalone /admin/users
 * page (templates/admin-users.php) inside the shared AdminLTE shell - not
 * part of the main SPA. New users get no password field in the create
 * form - they always set their own password through the emailed invite
 * link (see UserController::create() on the backend, which sends that
 * email on POST /api/v1/users). Admins can still set a password directly
 * for an existing user (setUserPasswordRow) or trigger the same reset
 * email a "Forgot password?" request would send (sendResetEmailRow).
 */

const TOUR_RIGHT_FIELDS = ['tour_create', 'tour_publish', 'tour_manage', 'tour_copy'];
// Rights that aren't about tours (currently just route_view_recording, the
// right to see who recorded a GPS-tracked route and when - see
// RouteController::redactRecordingInfo()) get their own bucket so the "Tour: "
// label prefix stays accurate and the two groups can be shown under separate
// headings in the form (tour_rights_label vs. other_rights_label).
const OTHER_RIGHT_FIELDS = ['route_view_recording'];
// "Admin" is an app-wide right, so it's left bare; the four tour_* fields are
// specifically about tours and are labelled "Tour: ..." everywhere they're
// shown (this table's badges, the filter panel below) so that's never
// ambiguous - "Create"/"Publish"/"Manage"/"Copy" alone read as generic
// permissions otherwise.
const USER_RIGHT_LABELS = { is_admin: t('admin_user.right_admin'), tour_create: t('admin_user.right_tour_create'), tour_publish: t('admin_user.right_tour_publish'), tour_manage: t('admin_user.right_tour_manage'), tour_copy: t('admin_user.right_tour_copy'), route_view_recording: t('admin_user.right_route_view_recording') };
const USER_ADMIN_NEW_BUTTON_HTML = '<button type="button" class="btn btn-primary btn-sm" onclick="showUserCreateForm();"><i class="bi bi-person-plus me-1"></i>' + t('admin_user.new_user') + '</button>';
let adminUserFilters = {}; // { is_admin: 1, tour_manage: 1, ... } - AND'ed together, see loadUserList()
let adminUserFilterPanelOpen = false; // whether the collapsible "Filter by right" panel is expanded
let adminUserLastList = []; // last list rendered, so toggling the filter panel/typing a search can re-render without a re-fetch
let adminUserSearchQuery = ''; // client-side search over username/email/firstname/lastname, see renderFilteredUserRows()
let adminUserPageSize = ADMIN_LIST_DEFAULT_PAGE_SIZE; // config.js
let adminUserPage = 1; // 1-indexed, over the filtered result set (not the unfiltered list)

/**
 * Called once as initAdminAuth()'s onReady (see admin-users.js) - this
 * page has no panel to slide in/drawer to push aside anymore (that was
 * the old main-SPA .cookiemenu overlay's job), just resets list state and
 * loads the first page.
 */
function openUserAdminMenu() {
    adminUserFilters = {};
    adminUserFilterPanelOpen = false;
    adminUserSearchQuery = '';
    adminUserPageSize = ADMIN_LIST_DEFAULT_PAGE_SIZE;
    adminUserPage = 1;
    loadUserList();
}

/**
 * Shared sticky title bar for the list/form sub-views - #useradminmenu-*
 * elements live in templates/admin-users.php's card header/body.
 */
function setUserAdminHeader(title, backOnClick, actionHtml) {
    document.getElementById('useradminmenu-title').textContent = title;
    var backBtn = document.getElementById('useradminmenu-back');
    backBtn.style.display = backOnClick ? '' : 'none';
    backBtn.onclick = backOnClick || null;
    document.getElementById('useradminmenu-action').innerHTML = actionHtml || '';
}

function loadUserList() {
    var query = Object.keys(adminUserFilters).map(k => k + '=' + adminUserFilters[k]).join('&');

    Ytan.get('/users' + (query ? '?' + query : '')).then(answer => {
        log('loadUserList()', LOG_INFO, answer);
        renderUserTable(answer.data);
    }).catch(err => {
        log('loadUserList() failed', LOG_ERROR, err);
        showAdminToast(t('admin_user.loading_users_failed', { error: err.message }), 'error');
    });
}

/**
 * Toggles one right on/off in the filter panel (each right combines with
 * any others already active, matching UserController::index()'s
 * exact-match-per-field query params) and reloads the list.
 */
function toggleUserFilter(field) {
    if (adminUserFilters.hasOwnProperty(field)) {
        delete adminUserFilters[field];
    } else {
        adminUserFilters[field] = 1;
    }
    adminUserPage = 1; // the result set is about to change - stay on a page that still exists
    loadUserList();
}

/**
 * Expands/collapses the "Filter by right" panel - a plain UI toggle with no
 * data to (re)fetch, so this re-renders from the already-loaded
 * adminUserLastList instead of going through loadUserList() again.
 */
function toggleUserFilterPanel() {
    adminUserFilterPanelOpen = !adminUserFilterPanelOpen;
    renderUserTable(adminUserLastList);
}

function userFilterPanelHtml() {
    var fields = ['is_admin'].concat(TOUR_RIGHT_FIELDS).concat(OTHER_RIGHT_FIELDS);
    var activeCount = Object.keys(adminUserFilters).length;

    var html = '<button type="button" class="btn btn-outline-secondary btn-sm mb-2 d-flex align-items-center gap-2" onclick="toggleUserFilterPanel();">' +
        '<i class="bi bi-sliders"></i>' +
        '<span>' + t('admin_user.filter_by_right') + (activeCount > 0 ? ' (' + activeCount + ')' : '') + '</span>' +
        '<i class="bi ' + (adminUserFilterPanelOpen ? 'bi-chevron-up' : 'bi-chevron-down') + '"></i>' +
        '</button>';

    if (adminUserFilterPanelOpen) {
        html += '<div class="card card-body mb-3">';
        for (let i = 0; i < fields.length; i++) {
            var field = fields[i];
            var checked = adminUserFilters.hasOwnProperty(field);
            html += '<div class="form-check">' +
                '<input class="form-check-input" type="checkbox" id="userFilter_' + field + '"' + (checked ? ' checked' : '') + ' onchange="toggleUserFilter(\'' + field + '\');">' +
                '<label class="form-check-label" for="userFilter_' + field + '">' + USER_RIGHT_LABELS[field] + '</label>' +
                '</div>';
        }
        html += '</div>';
    }

    return html;
}

function iconButton(icon, title, onclick) {
    // onclick is JS source (may itself contain a JSON.stringify()'d string
    // argument, i.e. embedded double quotes) being placed inside a
    // double-quoted HTML attribute, so it must be HTML-escaped here -
    // the browser HTML-attribute-decodes it before running it as JS.
    return '<button type="button" class="btn btn-sm btn-outline-secondary" title="' + escapeHTML(title) + '" onclick="' + escapeHTML(onclick) + '">' +
        '<i class="bi ' + icon + '"></i>' +
        '</button>';
}

function renderUserTable(users) {
    adminUserLastList = users;
    setUserAdminHeader(t('admin_user.title'), null, USER_ADMIN_NEW_BUTTON_HTML);

    var sizeOptions = '';
    for (let i = 0; i < ADMIN_LIST_PAGE_SIZES.length; i++) {
        var size = ADMIN_LIST_PAGE_SIZES[i];
        sizeOptions += '<option value="' + size + '"' + (size === adminUserPageSize ? ' selected' : '') + '>' + size + '</option>';
    }

    var html = '<div class="input-group mb-3">' +
        '<span class="input-group-text"><i class="bi bi-search"></i></span>' +
        '<input type="text" class="form-control" id="userSearchInput" placeholder="' + t('admin_user.search_placeholder') + '" value="' + escapeHTML(adminUserSearchQuery) + '" oninput="onAdminUserSearchInput();">' +
        '</div>';

    html += userFilterPanelHtml();

    // Rows-per-page sits above the list, next to the search/filter controls
    // it affects - only the prev/next page nav stays with the results
    // below, since that depends on the current (filtered) result count.
    html += '<div class="d-flex align-items-center gap-2 mb-3">' +
        '<label for="userPageSize" class="form-label mb-0 small">' + t('common.rows_per_page') + '</label>' +
        '<select id="userPageSize" class="form-select form-select-sm w-auto" onchange="onAdminUserPageSizeChange();">' + sizeOptions + '</select>' +
    '</div>';

    html += '<div id="adminUserTableResults"></div>';

    document.getElementById('useradminmenu-list').innerHTML = html;
    showUserList();
    renderFilteredUserRows();
}

/**
 * The list and the create/edit/set-password form share the same card body
 * but are mutually exclusive views - only one is ever meant to be visible.
 */
function showUserList() {
    document.getElementById('useradminmenu-list').hidden = false;
    document.getElementById('useradminmenu-form').hidden = true;
}

function showUserFormView() {
    document.getElementById('useradminmenu-list').hidden = true;
    document.getElementById('useradminmenu-form').hidden = false;
}

function onAdminUserSearchInput() {
    adminUserSearchQuery = document.getElementById('userSearchInput').value;
    adminUserPage = 1; // the result set is about to change - stay on a page that still exists
    renderFilteredUserRows();
}

function onAdminUserPageSizeChange() {
    adminUserPageSize = parseInt(document.getElementById('userPageSize').value, 10);
    adminUserPage = 1;
    renderFilteredUserRows();
}

function goToAdminUserPage(delta) {
    adminUserPage += delta;
    renderFilteredUserRows();
}

/**
 * Renders just the results table + pagination bar (into
 * #adminUserTableResults) from the already-loaded adminUserLastList,
 * filtered client-side by adminUserSearchQuery across username/email/
 * firstname/lastname and paged by adminUserPageSize/adminUserPage - kept
 * separate from renderUserTable() so typing in the search box never
 * rebuilds the search input itself (which would steal focus/cursor
 * position).
 */
function renderFilteredUserRows() {
    var query = foldSearchText(adminUserSearchQuery);

    function matches(u) {
        if (query === '') {
            return true;
        }
        var fullName = [u.firstname, u.lastname].filter(Boolean).join(' ');
        return foldSearchText(u.username).includes(query)
            || foldSearchText(u.email || '').includes(query)
            || foldSearchText(u.firstname || '').includes(query)
            || foldSearchText(u.lastname || '').includes(query)
            || foldSearchText(fullName).includes(query);
    }

    var allMatches = adminUserLastList.filter(u => u.username !== 'system').filter(matches);

    var totalPages = Math.max(1, Math.ceil(allMatches.length / adminUserPageSize));
    adminUserPage = Math.min(Math.max(1, adminUserPage), totalPages);
    var pageStart = (adminUserPage - 1) * adminUserPageSize;
    var users = allMatches.slice(pageStart, pageStart + adminUserPageSize);

    var html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr>' +
        '<th>' + t('admin_user.col_username') + '</th><th>' + t('admin_user.col_email') + '</th><th>' + t('admin_user.col_name') + '</th><th>' + t('admin_user.col_rights') + '</th><th>' + t('admin_user.col_actions') + '</th>' +
        '</tr></thead><tbody>';

    for (let i = 0; i < users.length; i++) {
        var u = users[i];
        var name = [u.firstname, u.lastname].filter(Boolean).map(escapeHTML).join(' ');

        var badges = u.is_admin ? '<span class="badge text-bg-primary me-1">' + USER_RIGHT_LABELS.is_admin + '</span>' : '';
        for (let r = 0; r < TOUR_RIGHT_FIELDS.length; r++) {
            if (u[TOUR_RIGHT_FIELDS[r]]) {
                badges += '<span class="badge text-bg-secondary me-1">' + USER_RIGHT_LABELS[TOUR_RIGHT_FIELDS[r]] + '</span>';
            }
        }
        for (let r = 0; r < OTHER_RIGHT_FIELDS.length; r++) {
            if (u[OTHER_RIGHT_FIELDS[r]]) {
                badges += '<span class="badge text-bg-secondary me-1">' + USER_RIGHT_LABELS[OTHER_RIGHT_FIELDS[r]] + '</span>';
            }
        }

        html += '<tr>' +
            '<td>' + escapeHTML(u.username) + '</td>' +
            '<td>' + escapeHTML(u.email || '') + '</td>' +
            '<td>' + name + '</td>' +
            '<td>' + (badges || '&ndash;') + '</td>' +
            '<td class="text-nowrap">' +
                iconButton('bi-pencil', t('common.edit'), 'editUserRow(' + u.id + ');') + ' ' +
                iconButton('bi-key', t('admin_user.set_password_title'), 'showSetPasswordForm(' + u.id + ', ' + JSON.stringify(u.username) + ');') + ' ' +
                iconButton('bi-envelope-arrow-up', t('admin_user.send_reset_title'), 'sendResetEmailRow(' + u.id + ');') + ' ' +
                iconButton('bi-trash', t('common.delete'), 'deleteUserRow(' + u.id + ');') +
            '</td>' +
            '</tr>';
    }

    html += '</tbody></table></div>';

    html += adminUserPaginationHtml(allMatches.length, totalPages);

    document.getElementById('adminUserTableResults').innerHTML = allMatches.length
        ? html
        : '<p class="text-secondary">' + t('admin_user.no_users_found') + '</p>' + adminUserPaginationHtml(0, totalPages);
}

function adminUserPaginationHtml(totalMatches, totalPages) {
    return '<div class="d-flex align-items-center justify-content-center gap-3 mt-2">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" ' + (adminUserPage <= 1 ? 'disabled' : '') + ' onclick="' + (adminUserPage > 1 ? 'goToAdminUserPage(-1);' : '') + '"><i class="bi bi-chevron-left"></i></button>' +
        '<span class="small text-secondary">' + (totalMatches === 0 ? t('admin_user.zero_users') : t('common.page_of', { page: adminUserPage, total: totalPages })) + '</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary" ' + (adminUserPage >= totalPages ? 'disabled' : '') + ' onclick="' + (adminUserPage < totalPages ? 'goToAdminUserPage(1);' : '') + '"><i class="bi bi-chevron-right"></i></button>' +
        '</div>';
}

function formCheckRow(id, checked, label) {
    return '<div class="form-check form-switch mb-2">' +
        '<input class="form-check-input" type="checkbox" role="switch" id="' + id + '"' + (checked ? ' checked' : '') + '>' +
        '<label class="form-check-label" for="' + id + '">' + label + '</label>' +
        '</div>';
}

function userFormHtml(u) {
    u = u || { id: null, username: '', email: '', firstname: '', lastname: '', is_admin: false, tour_create: false, tour_publish: false, tour_manage: false, tour_copy: false, route_view_recording: false };

    var saveCall = u.id === null ? 'saveNewUser()' : 'saveEditedUser(' + u.id + ')';
    var hint = u.id === null
        ? '<p class="text-secondary small">' + t('admin_user.invite_hint') + '</p>'
        : '';
    setUserAdminHeader(u.id === null ? t('admin_user.new_user') : t('admin_user.edit_user'), closeUserForm, '');

    return '<div class="admin-user-form">' +
        hint +
        '<div class="mb-3">' +
            '<label for="userFormUsername" class="form-label">' + t('admin_user.username_label') + '</label>' +
            '<input id="userFormUsername" type="text" class="form-control" value="' + escapeHTML(u.username) + '">' +
        '</div>' +
        '<div class="mb-3">' +
            '<label for="userFormEmail" class="form-label">' + t('admin_user.email_label') + '</label>' +
            '<input id="userFormEmail" type="email" class="form-control" value="' + escapeHTML(u.email || '') + '">' +
        '</div>' +
        '<div class="mb-3">' +
            '<label for="userFormFirstname" class="form-label">' + t('admin_user.firstname_label') + '</label>' +
            '<input id="userFormFirstname" type="text" class="form-control" value="' + escapeHTML(u.firstname || '') + '">' +
        '</div>' +
        '<div class="mb-3">' +
            '<label for="userFormLastname" class="form-label">' + t('admin_user.lastname_label') + '</label>' +
            '<input id="userFormLastname" type="text" class="form-control" value="' + escapeHTML(u.lastname || '') + '">' +
        '</div>' +
        formCheckRow('userFormIsAdmin', u.is_admin, t('admin_user.admin_label')) +
        '<p class="fw-bold small text-secondary mt-3 mb-2">' + t('admin_user.tour_rights_label') + '</p>' +
        formCheckRow('userFormTourCreate', u.tour_create, t('admin_user.tour_create_label')) +
        formCheckRow('userFormTourPublish', u.tour_publish, t('admin_user.tour_publish_label')) +
        formCheckRow('userFormTourManage', u.tour_manage, t('admin_user.tour_manage_label')) +
        formCheckRow('userFormTourCopy', u.tour_copy, t('admin_user.tour_copy_label')) +
        '<p class="fw-bold small text-secondary mt-3 mb-2">' + t('admin_user.other_rights_label') + '</p>' +
        formCheckRow('userFormRouteViewRecording', u.route_view_recording, t('admin_user.route_view_recording_label')) +
        '<div class="mt-3">' +
            '<button id="userFormSaveBtn" class="btn btn-primary" type="button" onclick="' + saveCall + '">' + t('common.save') + '</button>&nbsp;' +
            '<button class="btn btn-outline-secondary" type="button" onclick="closeUserForm();">' + t('common.cancel') + '</button>' +
        '</div>' +
        '</div>';
}

function showUserCreateForm() {
    document.getElementById('useradminmenu-form').innerHTML = userFormHtml(null);
    showUserFormView();
}

function editUserRow(id) {
    Ytan.get('/users/' + id).then(answer => {
        document.getElementById('useradminmenu-form').innerHTML = userFormHtml(answer.data);
        showUserFormView();
    }).catch(err => {
        showAdminToast(t('admin_user.loading_user_failed', { error: err.message }), 'error');
    });
}

function closeUserForm() {
    document.getElementById('useradminmenu-form').innerHTML = '';
    setUserAdminHeader(t('admin_user.title'), null, USER_ADMIN_NEW_BUTTON_HTML);
    showUserList();
}

function readUserForm() {
    return {
        username: document.getElementById('userFormUsername').value,
        email: document.getElementById('userFormEmail').value,
        firstname: document.getElementById('userFormFirstname').value,
        lastname: document.getElementById('userFormLastname').value,
        is_admin: document.getElementById('userFormIsAdmin').checked,
        tour_create: document.getElementById('userFormTourCreate').checked,
        tour_publish: document.getElementById('userFormTourPublish').checked,
        tour_manage: document.getElementById('userFormTourManage').checked,
        tour_copy: document.getElementById('userFormTourCopy').checked,
        route_view_recording: document.getElementById('userFormRouteViewRecording').checked,
    };
}

function saveNewUser() {
    document.getElementById('userFormSaveBtn').disabled = true;

    Ytan.post('/users', readUserForm()).then(() => {
        closeUserForm();
        loadUserList();
        showAdminToast(t('common.saved'), 'success');
    }).catch(err => {
        document.getElementById('userFormSaveBtn').disabled = false;
        showAdminToast(t('common.save_failed', { error: err.message }), 'error');
    });
}

function saveEditedUser(id) {
    document.getElementById('userFormSaveBtn').disabled = true;

    Ytan.put('/users/' + id, readUserForm()).then(() => {
        closeUserForm();
        loadUserList();
        showAdminToast(t('common.saved'), 'success');
    }).catch(err => {
        document.getElementById('userFormSaveBtn').disabled = false;
        showAdminToast(t('common.save_failed', { error: err.message }), 'error');
    });
}

function deleteUserRow(id) {
    if (!window.confirm(t('admin_user.confirm_delete_user'))) {
        return;
    }

    Ytan.del('/users/' + id).then(() => {
        loadUserList();
        showAdminToast(t('common.saved'), 'success');
    }).catch(err => {
        showAdminToast(t('common.delete_failed', { error: err.message }), 'error');
    });
}

/**
 * Admin override - sets a user's password directly, without needing (or
 * sending) a reset email. Useful e.g. when a user is locked out and can't
 * receive mail themselves.
 */
function showSetPasswordForm(id, username) {
    setUserAdminHeader(t('admin_user.set_password_for', { username: username }), closeUserForm, '');

    document.getElementById('useradminmenu-form').innerHTML =
        '<div class="admin-user-form">' +
        '<div class="mb-3">' +
            '<label for="setPasswordNew" class="form-label">' + t('admin_user.new_password_label') + '</label>' +
            '<input id="setPasswordNew" type="password" class="form-control" autocomplete="new-password">' +
        '</div>' +
        '<div id="setPasswordMessage" class="text-danger small mb-2"></div>' +
        '<div>' +
            '<button id="setPasswordSaveBtn" class="btn btn-primary" type="button" onclick="submitSetPassword(' + id + ');">' + t('common.save') + '</button>&nbsp;' +
            '<button class="btn btn-outline-secondary" type="button" onclick="closeUserForm();">' + t('common.cancel') + '</button>' +
        '</div>' +
        '</div>'
    ;
    showUserFormView();
}

function submitSetPassword(id) {
    var password = document.getElementById('setPasswordNew').value;
    var message = document.getElementById('setPasswordMessage');
    document.getElementById('setPasswordSaveBtn').disabled = true;

    Ytan.put('/users/' + id + '/password', { password: password }).then(() => {
        closeUserForm();
        loadUserList();
        showAdminToast(t('common.saved'), 'success');
    }).catch(err => {
        document.getElementById('setPasswordSaveBtn').disabled = false;
        message.textContent = err.message;
    });
}

function sendResetEmailRow(id) {
    if (!window.confirm(t('admin_user.confirm_send_reset'))) {
        return;
    }

    Ytan.post('/users/' + id + '/send-reset').then(() => {
        showAdminToast(t('admin_user.reset_email_sent'), 'success');
    }).catch(err => {
        showAdminToast(t('admin_user.sending_reset_failed', { error: err.message }), 'error');
    });
}
