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
 * Windy's own default wind-speed color scale, in m/s (their internal SI
 * unit), as [speed, [r,g,b]] stops with linear interpolation between
 * them - live-extracted from windy.com's own running app
 * (`W.colors.wind.defaultColorGradient` in the browser console), not
 * guessed, since the project's own icon-font caveat ("verify, don't
 * assume") applies just as much to a color scale as to a glyph name.
 * Used so our own hour cards (never Windy's own UI) read as
 * calmer/stormier at a glance the same way a Windy user would expect.
 */
const WIND_COLOR_SCALE_MS = [
    [0, [98, 113, 183]],
    [1, [57, 97, 159]],
    [3, [74, 148, 169]],
    [5, [77, 141, 123]],
    [7, [83, 165, 83]],
    [9, [53, 159, 53]],
    [11, [167, 157, 81]],
    [13, [159, 127, 58]],
    [15, [161, 108, 92]],
    [17, [129, 58, 78]],
    [19, [175, 80, 136]],
    [21, [117, 74, 147]],
    [24, [109, 97, 163]],
    [27, [68, 105, 141]],
    [29, [92, 144, 152]],
    [36, [125, 68, 165]],
    [46, [231, 215, 215]],
    [51, [219, 212, 135]],
    [77, [205, 202, 112]],
    [104, [128, 128, 128]],
];

function windSpeedColor(speedKmh) {
    const speedMs = speedKmh / 3.6;
    const scale = WIND_COLOR_SCALE_MS;

    if (speedMs <= scale[0][0]) {
        return 'rgb(' + scale[0][1].join(',') + ')';
    }
    for (let i = 0; i < scale.length - 1; i++) {
        const [lowSpeed, lowColor] = scale[i];
        const [highSpeed, highColor] = scale[i + 1];
        if (speedMs >= lowSpeed && speedMs <= highSpeed) {
            const ratio = (speedMs - lowSpeed) / (highSpeed - lowSpeed);
            const rgb = lowColor.map((channel, idx) => Math.round(channel + (highColor[idx] - channel) * ratio));
            return 'rgb(' + rgb.join(',') + ')';
        }
    }
    return 'rgb(' + scale[scale.length - 1][1].join(',') + ')';
}

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

// Kept so the strip can be redrawn (formatWindSpeed()/windSpeedColor()
// react to hour.wind_speed the same way, only the unit changes) without a
// fresh fetch when the user switches the wind-speed unit in Preferences -
// see refreshOpenWeatherTimelineWindUnit(), called from map-core.js's
// editWindUnit().
let lastWeatherTimelineData = null;

// Marks the exact point the open timeline is for - same plain-marker
// pattern map-core.js's own showSearchResultOnMap()/clearSearchMarker()
// already use for "a temporary marker showing one location", not a
// custom icon set of its own.
let weatherLocationMarker = null;

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
    lastWeatherTimelineData = null;

    if (weatherLocationMarker) {
        weatherLocationMarker.setPosition(latLng);
    } else {
        weatherLocationMarker = new google.maps.Marker({
            position: latLng,
            map: map,
            title: t('weather.marker.title'),
            zIndex: ZINDEX_POI + 10, // above regular POI markers, so it's never hidden underneath one at the same spot
            // Same 'cloud' glyph as the context-menu item, rendered via the
            // vendored icon font as the pin's label instead of a plain dot -
            // marks this specific pin as "the weather location", not just
            // any location marker.
            label: {
                text: 'cloud',
                fontFamily: 'Material Icons Round',
                fontSize: '16px',
                color: '#ffffff',
            },
        });
    }

    Ytan.get('/weather?lat=' + lat + '&lng=' + lng)
        .then((answer) => {
            lastWeatherTimelineData = answer.data;
            renderWeatherTimeline(answer.data);
        })
        .catch((err) => {
            log('openWeatherTimelineForLocation() failed', LOG_ERROR, err);
            document.getElementById('weatherTimelineStrip').innerHTML =
                '<div class="weather-timeline-message">' + escapeHTML(t('weather.timeline.failed')) + '</div>';
        });
}

function closeWeatherTimeline() {
    document.getElementById('weatherTimelinePanel').style.display = 'none';
    panelClosed();
    if (weatherLocationMarker) {
        weatherLocationMarker.setMap(null);
        weatherLocationMarker = null;
    }
}

/**
 * Called from map-core.js's editWindUnit() whenever the Preferences wind
 * unit changes. Only redraws (no re-fetch, no "no marine data" flicker)
 * and only if the panel is actually open and already holds real data.
 */
function refreshOpenWeatherTimelineWindUnit() {
    if (!lastWeatherTimelineData) {
        return;
    }
    if (document.getElementById('weatherTimelinePanel').style.display === 'none') {
        return;
    }
    renderWeatherTimeline(lastWeatherTimelineData, /* preserveScroll */ true);
}

function renderWeatherTimeline(data, preserveScroll) {
    document.getElementById('weatherTimelineMarineNotice').style.display = data.has_marine_data ? 'none' : 'block';

    const strip = document.getElementById('weatherTimelineStrip');
    const previousScrollLeft = strip.scrollLeft;
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

    if (preserveScroll) {
        // A wind-unit-only redraw shouldn't jump the user back to "now" if
        // they'd already scrolled further into the strip.
        strip.scrollLeft = previousScrollLeft;
    } else if (nowCard) {
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

    // A solid, full-width color block rather than a thin accent line -
    // needs to read at a glance while scrolling the strip (see
    // windSpeedColor()'s own comment on where the color scale comes from).
    const windBand = document.createElement('div');
    windBand.className = 'weather-timeline-hour-wind-band';
    windBand.style.backgroundColor = windSpeedColor(hour.wind_speed);
    card.appendChild(windBand);

    const content = document.createElement('div');
    content.className = 'weather-timeline-hour-card-content';

    const time = document.createElement('div');
    time.className = 'weather-timeline-hour-time';
    time.textContent = hour.time.slice(11, 16); // "HH:MM"
    content.appendChild(time);

    content.appendChild(buildHourRow('thermostat', t('weather.field.temperature_aria_label'), Math.round(hour.temperature) + '°C'));

    const windRow = buildHourRow('navigation', t('weather.field.wind_aria_label'), formatWindSpeed(hour.wind_speed, settings.windUnit));
    windRow.querySelector('.material-icons-round').style.transform = 'rotate(' + hour.wind_direction + 'deg)';
    content.appendChild(windRow);

    content.appendChild(buildHourRow('opacity', t('weather.field.precipitation_aria_label'), hour.precipitation.toFixed(1) + ' mm'));

    if (hasMarineData && hour.wave_height !== null) {
        content.appendChild(buildHourRow('waves', t('weather.field.wave_aria_label'), hour.wave_height.toFixed(2) + ' m'));
    }

    card.appendChild(content);

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
