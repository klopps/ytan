/**
 * Admin-only user management panel (list / create / edit / delete users,
 * plus per-user password actions).
 *
 * A full-screen slide-in panel (the .cookiemenu pattern also used by the
 * cookie/legal menus, see ui.js: openCookieMenu/openLegalMenu) rather than
 * a nested nav-menu.js drawer screen - the account table needs more width
 * than the ~320px drawer can offer. Triggered from the "Site Settings"
 * drawer screen (nav-menu.js), which is closed first for a clean transition.
 * New users get no password field in the create form - they always set
 * their own password through the emailed invite link (see
 * UserController::create() on the backend, which sends that email on
 * POST /api/v1/users). Admins can still set a password directly for an
 * existing user (setUserPasswordRow) or trigger the same reset email a
 * "Forgot password?" request would send (sendResetEmailRow).
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
const USER_ADMIN_NEW_BUTTON_HTML = '<div class="startbtn" onclick="showUserCreateForm();"><i class="material-icons-round">person_add</i>&nbsp;' + t('admin_user.new_user') + '</div>';
let adminUserFilters = {}; // { is_admin: 1, tour_manage: 1, ... } - AND'ed together, see loadUserList()
let adminUserFilterPanelOpen = false; // whether the collapsible "Filter by right" panel is expanded
let adminUserLastList = []; // last list rendered, so toggling the filter panel/typing a search can re-render without a re-fetch
let adminUserSearchQuery = ''; // client-side search over username/email/firstname/lastname, see renderFilteredUserRows()
let adminUserPageSize = ADMIN_LIST_DEFAULT_PAGE_SIZE; // config.js
let adminUserPage = 1; // 1-indexed, over the filtered result set (not the unfiltered list)

/**
 * Deliberately does NOT call closeMenu() - #useradminmenu's z-index sits
 * above #sidemenu's (style.css, ".cookiemenu opening over the drawer"), so
 * this opens directly on top of the drawer exactly as it currently is
 * (open on whichever screen, or closed) rather than needing to hide it
 * first. closeUserAdminMenu() then needs no matching "reopen the drawer"
 * step either - the drawer was never touched, so it's simply revealed
 * again in the same state once this panel closes. pushMenuLeft() (ui.js)
 * makes a still-open drawer slide fully out of view as this panel slides in
 * over it, the same "pushed left" look navMenuGoTo() gives a nav-screen
 * like Site Settings. slideInPanel()/slideOutPanel() (ui.js) drive the
 * panel's own slide-in-from-the-right transform.
 */
function openUserAdminMenu() {
    slideInPanel('useradminmenu');
    pushMenuLeft();
    panelOpened();
    closeUserForm();
    adminUserFilters = {};
    adminUserFilterPanelOpen = false;
    adminUserSearchQuery = '';
    adminUserPageSize = ADMIN_LIST_DEFAULT_PAGE_SIZE;
    adminUserPage = 1;
    loadUserList();
}

function closeUserAdminMenu() {
    slideOutPanel('useradminmenu');
    unpushMenuLeft();
    panelClosed();
}

/**
 * Shared sticky title bar for the list/form sub-views - mirrors
 * tour-admin.js's setTourAdminHeader() for the same .cm-panel-header
 * markup. Kept as its own function here (not a cross-file shared utility)
 * to match how this panel's list/pagination logic already duplicates
 * tour-admin.js's equivalents rather than sharing them.
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
        showToast(t('admin_user.loading_users_failed', { error: err.message }), 'error');
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

    var html = '<div class="admin-filter-toggle" onclick="toggleUserFilterPanel();">' +
        '<i class="material-icons-round">tune</i>' +
        '<span>' + t('admin_user.filter_by_right') + (activeCount > 0 ? ' (' + activeCount + ')' : '') + '</span>' +
        '<i class="material-icons-round admin-filter-chevron">' + (adminUserFilterPanelOpen ? 'expand_less' : 'expand_more') + '</i>' +
        '</div>';

    if (adminUserFilterPanelOpen) {
        html += '<div class="admin-filter-panel">';
        for (let i = 0; i < fields.length; i++) {
            var field = fields[i];
            var checked = adminUserFilters.hasOwnProperty(field);
            html += '<div class="admin-filter-check-row">' +
                '<input type="checkbox" id="userFilter_' + field + '"' + (checked ? ' checked' : '') + ' onchange="toggleUserFilter(\'' + field + '\');">' +
                '<label for="userFilter_' + field + '"><span></span>' + USER_RIGHT_LABELS[field] + '</label>' +
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
    return '<span class="icon-btn" title="' + escapeHTML(title) + '" onclick="' + escapeHTML(onclick) + '">' +
        '<i class="material-icons-round">' + icon + '</i>' +
        '</span>';
}

function renderUserTable(users) {
    adminUserLastList = users;
    setUserAdminHeader(t('admin_user.title'), closeUserAdminMenu, USER_ADMIN_NEW_BUTTON_HTML);

    var sizeOptions = '';
    for (let i = 0; i < ADMIN_LIST_PAGE_SIZES.length; i++) {
        var size = ADMIN_LIST_PAGE_SIZES[i];
        sizeOptions += '<option value="' + size + '"' + (size === adminUserPageSize ? ' selected' : '') + '>' + size + '</option>';
    }

    var html = '<div class="search-bar"><i class="material-icons-round">search</i>' +
        '<input type="text" id="userSearchInput" placeholder="' + t('admin_user.search_placeholder') + '" value="' + escapeHTML(adminUserSearchQuery) + '" oninput="onAdminUserSearchInput();"></div>';

    html += userFilterPanelHtml();

    // Rows-per-page sits above the list, next to the search/filter controls
    // it affects - matches tour-admin.js's list view (.tour-page-size-row) -
    // only the prev/next page nav stays with the results below, since that
    // depends on the current (filtered) result count.
    html += '<div class="admin-pagination-size tour-page-size-row">' +
        '<label for="userPageSize">' + t('common.rows_per_page') + '</label>' +
        '<select id="userPageSize" class="select-css select-css-compact" onchange="onAdminUserPageSizeChange();">' + sizeOptions + '</select>' +
    '</div>';

    html += '<div id="adminUserTableResults"></div>';

    document.getElementById('useradminmenu-list').innerHTML = html;
    renderFilteredUserRows();
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
 * position), the same split renderFilteredTourList() uses in tour-admin.js.
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

    var html = '<div class="admin-user-table-wrap"><table class="admin-user-table"><thead><tr>' +
        '<th>' + t('admin_user.col_username') + '</th><th>' + t('admin_user.col_email') + '</th><th>' + t('admin_user.col_name') + '</th><th>' + t('admin_user.col_rights') + '</th><th class="actions">' + t('admin_user.col_actions') + '</th>' +
        '</tr></thead><tbody>';

    for (let i = 0; i < users.length; i++) {
        var u = users[i];
        var name = [u.firstname, u.lastname].filter(Boolean).map(escapeHTML).join(' ');

        var badges = u.is_admin ? '<span class="admin-badge">' + USER_RIGHT_LABELS.is_admin + '</span>' : '';
        for (let r = 0; r < TOUR_RIGHT_FIELDS.length; r++) {
            if (u[TOUR_RIGHT_FIELDS[r]]) {
                badges += '<span class="admin-badge admin-badge-tour">' + USER_RIGHT_LABELS[TOUR_RIGHT_FIELDS[r]] + '</span>';
            }
        }
        for (let r = 0; r < OTHER_RIGHT_FIELDS.length; r++) {
            if (u[OTHER_RIGHT_FIELDS[r]]) {
                badges += '<span class="admin-badge admin-badge-tour">' + USER_RIGHT_LABELS[OTHER_RIGHT_FIELDS[r]] + '</span>';
            }
        }

        html += '<tr>' +
            '<td data-label="' + t('admin_user.col_username') + '">' + escapeHTML(u.username) + '</td>' +
            '<td data-label="' + t('admin_user.col_email') + '">' + escapeHTML(u.email || '') + '</td>' +
            '<td data-label="' + t('admin_user.col_name') + '">' + name + '</td>' +
            '<td data-label="' + t('admin_user.col_rights') + '">' + (badges || '&ndash;') + '</td>' +
            '<td class="actions" data-label="' + t('admin_user.col_actions') + '">' +
                iconButton('edit', t('common.edit'), 'editUserRow(' + u.id + ');') +
                iconButton('vpn_key', t('admin_user.set_password_title'), 'showSetPasswordForm(' + u.id + ', ' + JSON.stringify(u.username) + ');') +
                iconButton('forward_to_inbox', t('admin_user.send_reset_title'), 'sendResetEmailRow(' + u.id + ');') +
                iconButton('delete', t('common.delete'), 'deleteUserRow(' + u.id + ');') +
            '</td>' +
            '</tr>';
    }

    html += '</tbody></table></div>';

    html += adminUserPaginationHtml(allMatches.length, totalPages);

    document.getElementById('adminUserTableResults').innerHTML = allMatches.length
        ? html
        : '<p class="hint">' + t('admin_user.no_users_found') + '</p>' + adminUserPaginationHtml(0, totalPages);
}

function adminUserPaginationHtml(totalMatches, totalPages) {
    return '<div class="admin-pagination admin-pagination-section-only">' +
        '<div class="admin-pagination-nav">' +
            '<span class="admin-pagination-nav-btn' + (adminUserPage <= 1 ? ' disabled' : '') + '" onclick="' + (adminUserPage > 1 ? 'goToAdminUserPage(-1);' : '') + '"><i class="material-icons-round">chevron_left</i></span>' +
            '<span class="admin-pagination-page">' + (totalMatches === 0 ? t('admin_user.zero_users') : t('common.page_of', { page: adminUserPage, total: totalPages })) + '</span>' +
            '<span class="admin-pagination-nav-btn' + (adminUserPage >= totalPages ? ' disabled' : '') + '" onclick="' + (adminUserPage < totalPages ? 'goToAdminUserPage(1);' : '') + '"><i class="material-icons-round">chevron_right</i></span>' +
        '</div>' +
        '</div>';
}

function userFormHtml(u) {
    u = u || { id: null, username: '', email: '', firstname: '', lastname: '', is_admin: false, tour_create: false, tour_publish: false, tour_manage: false, tour_copy: false, route_view_recording: false };

    var saveCall = u.id === null ? 'saveNewUser()' : 'saveEditedUser(' + u.id + ')';
    var hint = u.id === null
        ? '<p class="hint">' + t('admin_user.invite_hint') + '</p>'
        : '';
    setUserAdminHeader(u.id === null ? t('admin_user.new_user') : t('admin_user.edit_user'), closeUserForm, '');

    return '<div class="admin-user-form">' +
        hint +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="userFormUsername">' + t('admin_user.username_label') + '</label></div>' +
            '<div class="adminFormField"><input id="userFormUsername" type="text" value="' + escapeHTML(u.username) + '"></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="userFormEmail">' + t('admin_user.email_label') + '</label></div>' +
            '<div class="adminFormField"><input id="userFormEmail" type="email" value="' + escapeHTML(u.email || '') + '"></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="userFormFirstname">' + t('admin_user.firstname_label') + '</label></div>' +
            '<div class="adminFormField"><input id="userFormFirstname" type="text" value="' + escapeHTML(u.firstname || '') + '"></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="userFormLastname">' + t('admin_user.lastname_label') + '</label></div>' +
            '<div class="adminFormField"><input id="userFormLastname" type="text" value="' + escapeHTML(u.lastname || '') + '"></div>' +
        '</div>' +
        '<div class="adminFormRow adminFormRowCheckbox">' +
            '<div class="adminFormField"><input id="userFormIsAdmin" type="checkbox"' + (u.is_admin ? ' checked' : '') + '><label for="userFormIsAdmin"><span></span></label></div>' +
            '<div class="adminFormLabel"><label for="userFormIsAdmin">' + t('admin_user.admin_label') + '</label></div>' +
        '</div>' +
        '<p class="nav-field-label">' + t('admin_user.tour_rights_label') + '</p>' +
        '<div class="adminFormRow adminFormRowCheckbox">' +
            '<div class="adminFormField"><input id="userFormTourCreate" type="checkbox"' + (u.tour_create ? ' checked' : '') + '><label for="userFormTourCreate"><span></span></label></div>' +
            '<div class="adminFormLabel"><label for="userFormTourCreate">' + t('admin_user.tour_create_label') + '</label></div>' +
        '</div>' +
        '<div class="adminFormRow adminFormRowCheckbox">' +
            '<div class="adminFormField"><input id="userFormTourPublish" type="checkbox"' + (u.tour_publish ? ' checked' : '') + '><label for="userFormTourPublish"><span></span></label></div>' +
            '<div class="adminFormLabel"><label for="userFormTourPublish">' + t('admin_user.tour_publish_label') + '</label></div>' +
        '</div>' +
        '<div class="adminFormRow adminFormRowCheckbox">' +
            '<div class="adminFormField"><input id="userFormTourManage" type="checkbox"' + (u.tour_manage ? ' checked' : '') + '><label for="userFormTourManage"><span></span></label></div>' +
            '<div class="adminFormLabel"><label for="userFormTourManage">' + t('admin_user.tour_manage_label') + '</label></div>' +
        '</div>' +
        '<div class="adminFormRow adminFormRowCheckbox">' +
            '<div class="adminFormField"><input id="userFormTourCopy" type="checkbox"' + (u.tour_copy ? ' checked' : '') + '><label for="userFormTourCopy"><span></span></label></div>' +
            '<div class="adminFormLabel"><label for="userFormTourCopy">' + t('admin_user.tour_copy_label') + '</label></div>' +
        '</div>' +
        '<p class="nav-field-label">' + t('admin_user.other_rights_label') + '</p>' +
        '<div class="adminFormRow adminFormRowCheckbox">' +
            '<div class="adminFormField"><input id="userFormRouteViewRecording" type="checkbox"' + (u.route_view_recording ? ' checked' : '') + '><label for="userFormRouteViewRecording"><span></span></label></div>' +
            '<div class="adminFormLabel"><label for="userFormRouteViewRecording">' + t('admin_user.route_view_recording_label') + '</label></div>' +
        '</div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel">&nbsp;</div>' +
            '<div class="adminFormField">' +
                '<button id="userFormSaveBtn" class="button" type="button" onclick="' + saveCall + '">' + t('common.save') + '</button>&nbsp;' +
                '<button class="button" type="button" onclick="closeUserForm();">' + t('common.cancel') + '</button>' +
            '</div>' +
        '</div>' +
        '</div>';
}

function showUserCreateForm() {
    document.getElementById('useradminmenu-form').innerHTML = userFormHtml(null);
}

function editUserRow(id) {
    Ytan.get('/users/' + id).then(answer => {
        document.getElementById('useradminmenu-form').innerHTML = userFormHtml(answer.data);
    }).catch(err => {
        showToast(t('admin_user.loading_user_failed', { error: err.message }), 'error');
    });
}

function closeUserForm() {
    document.getElementById('useradminmenu-form').innerHTML = '';
    setUserAdminHeader(t('admin_user.title'), closeUserAdminMenu, USER_ADMIN_NEW_BUTTON_HTML);
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
    }).catch(err => {
        document.getElementById('userFormSaveBtn').disabled = false;
        showToast(t('common.save_failed', { error: err.message }), 'error');
    });
}

function saveEditedUser(id) {
    document.getElementById('userFormSaveBtn').disabled = true;

    Ytan.put('/users/' + id, readUserForm()).then(() => {
        closeUserForm();
        loadUserList();
    }).catch(err => {
        document.getElementById('userFormSaveBtn').disabled = false;
        showToast(t('common.save_failed', { error: err.message }), 'error');
    });
}

async function deleteUserRow(id) {
    if (!(await showConfirmDialog(t('admin_user.confirm_delete_user'), { type: 'danger', confirmLabel: t('common.delete') }))) {
        return;
    }

    Ytan.del('/users/' + id).then(() => {
        loadUserList();
    }).catch(err => {
        showToast(t('common.delete_failed', { error: err.message }), 'error');
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
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel"><label for="setPasswordNew">' + t('admin_user.new_password_label') + '</label></div>' +
            '<div class="adminFormField"><input id="setPasswordNew" type="password" autocomplete="new-password"></div>' +
        '</div>' +
        '<div id="setPasswordMessage"></div>' +
        '<div class="adminFormRow">' +
            '<div class="adminFormLabel">&nbsp;</div>' +
            '<div class="adminFormField">' +
                '<button id="setPasswordSaveBtn" class="button" type="button" onclick="submitSetPassword(' + id + ');">' + t('common.save') + '</button>&nbsp;' +
                '<button class="button" type="button" onclick="closeUserForm();">' + t('common.cancel') + '</button>' +
            '</div>' +
        '</div>' +
        '</div>'
    ;
}

function submitSetPassword(id) {
    var password = document.getElementById('setPasswordNew').value;
    var message = document.getElementById('setPasswordMessage');
    document.getElementById('setPasswordSaveBtn').disabled = true;

    Ytan.put('/users/' + id + '/password', { password: password }).then(() => {
        closeUserForm();
        loadUserList();
    }).catch(err => {
        document.getElementById('setPasswordSaveBtn').disabled = false;
        message.style.color = '#b3261e';
        message.textContent = err.message;
    });
}

async function sendResetEmailRow(id) {
    if (!(await showConfirmDialog(t('admin_user.confirm_send_reset'), { type: 'default', confirmLabel: t('admin_user.send') }))) {
        return;
    }

    Ytan.post('/users/' + id + '/send-reset').then(() => {
        showToast(t('admin_user.reset_email_sent'), 'success');
    }).catch(err => {
        showToast(t('admin_user.sending_reset_failed', { error: err.message }), 'error');
    });
}
