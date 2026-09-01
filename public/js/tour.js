/**
 * Tour selector (a tour groups several routes together).
 */

/**
 * Hole alle Touren eines Users, speichere sie in tours[] und aktualisiere die Auswahlliste im Sidemenu
 *
 * @param {number} userId
 */
function getToursByUserId(userId) {
    Ytan.get('/tours?scope=mine_public').then(answer => {
        tours = answer.data;
        log('getToursByUserId(' + userId + ')', LOG_INFO, answer);
        updateTourSelector();
    }).catch(err => log('getToursByUserId() failed', LOG_ERROR, err));
}

/**
 * Hole alle öffentlichen Touren, speichere sie in tours[] und aktualisiere die Auswahlliste im Sidemenu
 */
function getPublicTours() {
    log('getPublicTours() called', LOG_INFO);

    Ytan.get('/tours?scope=public').then(answer => {
        log('getPublicTours() answer received', LOG_INFO, answer);
        tours = answer.data;
        updateTourSelector();
    }).catch(err => log('getPublicTours() failed', LOG_ERROR, err));
}

function updateTourSelector() {
    var options = [];
    options[0] = {value: -1, text: '-ALL TOURS-'};

    for (let i = 0; i < tours.length; i++) {
        options[i+1] = {value: tours[i].id, text: tours[i].name};
    }

    $('#select-tour').selectize({
        maxItems: 1,
        labelField: 'text',
        sortField: {
            field: 'text',
            direction: 'asc'
        },
        options: options,
        create: false,
        allowEmptyOption: true
    });
}

function showSelectedTour() {
    var selected = $('#select-tour').val();

    hideRoutes();

    if (selected == -1) {
        if (user.id !== null) {
            getRoutesByUserId(user.id);
        } else {
            getPublicRoutes();
        }
    } else {
        getRoutesByTourId(selected);
    }
    closeMenu();
}
