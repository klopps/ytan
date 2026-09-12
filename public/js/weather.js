/**
 * On-demand weather + sea-conditions timeline for a map location.
 * Right-click (desktop) or long-press (touch) an empty point on the map ->
 * "Weather data for this location" (map-core.js's generic map context
 * menu, registerMapContextMenuItem()) -> a bottom-sheet panel with a
 * horizontally scrollable hourly strip covering the next 7 days
 * (GET /api/v1/weather, WeatherController/WeatherService). Loaded right
 * after map-core.js (needs the global `map` and the context-menu
 * registry it defines), before poi.js - same slot poi.js/route.js/area.js
 * themselves occupy.
 *
 * Also registers a second, unrelated item in the same menu - "Open on
 * Windy" - a plain deep link to windy.com centered on the clicked point,
 * no backend/API involved (Windy needs no key for its own site, only for
 * its point-forecast API, which this doesn't use).
 */

const WINDY_DEFAULT_ZOOM = 10;

/**
 * Called once from map-core.js's initMap(). Registers this feature's items
 * in the generic map context menu instead of map-core.js needing to know
 * anything about weather - see that file's own comment on
 * registerMapContextMenuItem() for why.
 */
function initWeatherWidget() {
    registerMapContextMenuItem('cloud', 'weather.context_menu.item', openWeatherTimelineForLocation);
    registerMapContextMenuItem('open_in_new', 'weather.context_menu.windy_item', openWindyForLocation);
}

function openWindyForLocation(latLng) {
    const lat = latLng.lat().toFixed(4);
    const lng = latLng.lng().toFixed(4);
    window.open('https://www.windy.com/?' + lat + ',' + lng + ',' + WINDY_DEFAULT_ZOOM, '_blank', 'noopener');
}

function openWeatherTimelineForLocation(latLng) {
    const lat = latLng.lat();
    const lng = latLng.lng();

    document.getElementById('weatherTimelineTitle').textContent = t('weather.timeline.title', {
        lat: lat.toFixed(4),
        lng: lng.toFixed(4),
    });
    document.getElementById('weatherTimelineMarineNotice').style.display = 'none';
    document.getElementById('weatherTimelineStrip').innerHTML =
        '<div class="weather-timeline-message">' + escapeHTML(t('weather.timeline.loading')) + '</div>';
    document.getElementById('weatherTimelinePanel').style.display = 'flex';
    panelOpened();

    Ytan.get('/weather?lat=' + lat + '&lng=' + lng)
        .then((answer) => renderWeatherTimeline(answer.data))
        .catch((err) => {
            log('openWeatherTimelineForLocation() failed', LOG_ERROR, err);
            document.getElementById('weatherTimelineStrip').innerHTML =
                '<div class="weather-timeline-message">' + escapeHTML(t('weather.timeline.failed')) + '</div>';
        });
}

function closeWeatherTimeline() {
    document.getElementById('weatherTimelinePanel').style.display = 'none';
    panelClosed();
}

function renderWeatherTimeline(data) {
    document.getElementById('weatherTimelineMarineNotice').style.display = data.has_marine_data ? 'none' : 'block';

    const strip = document.getElementById('weatherTimelineStrip');
    strip.innerHTML = '';

    const now = new Date();
    let previousDateKey = null;
    let nowCard = null;

    data.hourly.forEach((hour) => {
        const hourDate = new Date(hour.time);
        const dateKey = hour.time.slice(0, 10); // "YYYY-MM-DD"

        if (dateKey !== previousDateKey) {
            strip.appendChild(buildDayDividerCard(hourDate));
            previousDateKey = dateKey;
        }

        const card = buildHourCard(hour, hourDate, data.has_marine_data);
        strip.appendChild(card);

        // First hour that hasn't passed yet - used below to scroll the
        // strip there on open, so the user isn't looking at today's
        // already-past hours by default.
        if (!nowCard && hourDate >= now) {
            nowCard = card;
            card.classList.add('weather-timeline-hour-card-current');
        }
    });

    if (nowCard) {
        // scrollIntoView with inline:'start' would also move the page/panel
        // vertically in some browsers - restrict the scroll to the strip's
        // own horizontal axis instead.
        strip.scrollLeft = Math.max(0, nowCard.offsetLeft - 8);
    }
}

function buildDayDividerCard(date) {
    const div = document.createElement('div');
    div.className = 'weather-timeline-day-divider';
    div.textContent = new Intl.DateTimeFormat(window.YTAN_LOCALE, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    }).format(date);
    return div;
}

function buildHourCard(hour, hourDate, hasMarineData) {
    const card = document.createElement('div');
    card.className = 'weather-timeline-hour-card';

    const time = document.createElement('div');
    time.className = 'weather-timeline-hour-time';
    time.textContent = hour.time.slice(11, 16); // "HH:MM"
    card.appendChild(time);

    card.appendChild(buildHourRow('thermostat', t('weather.field.temperature_aria_label'), Math.round(hour.temperature) + '°C'));

    const windRow = buildHourRow('navigation', t('weather.field.wind_aria_label'), Math.round(hour.wind_speed) + ' km/h');
    windRow.querySelector('.material-icons-round').style.transform = 'rotate(' + hour.wind_direction + 'deg)';
    card.appendChild(windRow);

    if (hasMarineData && hour.wave_height !== null) {
        card.appendChild(buildHourRow('waves', t('weather.field.wave_aria_label'), hour.wave_height.toFixed(2) + ' m'));
    }

    return card;
}

function buildHourRow(icon, title, value) {
    const row = document.createElement('div');
    row.className = 'weather-timeline-hour-row';

    const iconEl = document.createElement('i');
    iconEl.className = 'material-icons-round';
    iconEl.title = title;
    iconEl.textContent = icon;
    row.appendChild(iconEl);

    const valueEl = document.createElement('span');
    valueEl.textContent = value;
    row.appendChild(valueEl);

    return row;
}
