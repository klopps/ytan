/**
 * Keeps the global tours[] array (the current user's own + public tours)
 * in sync, and activates/exits Tour Mode on the map. The drawer's old
 * in-place tour-picker dropdown that used to live here is gone - the
 * "Tours" drawer row now opens the full Tours panel directly
 * (tour-admin.js), whose detail view's "Activate Tour Mode" button calls
 * activateTourMode() below.
 */

/**
 * Hole alle Touren eines Users und speichere sie in tours[]
 *
 * @param {number} userId
 */
function getToursByUserId(userId) {
    Ytan.get('/tours?scope=mine_public').then(answer => {
        tours = answer.data;
        log('getToursByUserId(' + userId + ')', LOG_INFO, answer);
    }).catch(err => log('getToursByUserId() failed', LOG_ERROR, err));
}

/**
 * Hole alle öffentlichen Touren und speichere sie in tours[]
 */
function getPublicTours() {
    log('getPublicTours() called', LOG_INFO);

    Ytan.get('/tours?scope=public').then(answer => {
        log('getPublicTours() answer received', LOG_INFO, answer);
        tours = answer.data;
    }).catch(err => log('getPublicTours() failed', LOG_ERROR, err));
}

let activeTourModeId = null; // the tour active in Tour Mode, or null - route.js's saveRoute() reads this to auto-add newly created routes to it
let activeTourModeName = null; // kept alongside the id so saveRoute()'s toast can name the tour without reading it back out of the badge's DOM

/**
 * Enters Tour Mode: filters the map down to just this tour's routes and
 * shows the persistent "Tour Mode" badge (see #tourModeBadge in app.php)
 * so it stays obvious which tour is active - and, unlike a full-screen
 * panel, leaves #editToolbar untouched/usable, since Tour Mode is just a
 * route filter on the normal map, not an overlay.
 *
 * @param {number} id
 * @param {string} name
 */
function activateTourMode(id, name) {
    activeTourModeId = id;
    activeTourModeName = name;
    getRoutesByTourId(id);
    document.getElementById('tourModeBadgeName').textContent = name;
    document.getElementById('tourModeBadge').style.display = 'flex';
}

/**
 * Leaves Tour Mode and goes back to showing all of the user's own (or, if
 * signed out, all public) routes.
 */
function exitTourMode() {
    activeTourModeId = null;
    activeTourModeName = null;
    document.getElementById('tourModeBadge').style.display = 'none';
    if (user.id !== null) {
        getRoutesByUserId(user.id);
    } else {
        getPublicRoutes();
    }
}
