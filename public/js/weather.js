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

// Bumped on every open; a reverse-geocoding answer only updates the title
// if it's still the one for the CURRENT request - otherwise a slow lookup
// for a previous location could overwrite the title after the user has
// already moved on to a new one.
let weatherTimelineRequestId = 0;

function openWeatherTimelineForLocation(latLng) {
    const lat = latLng.lat();
    const lng = latLng.lng();
    const requestId = ++weatherTimelineRequestId;

    setWeatherTimelineTitle(lat, lng, null);
    document.getElementById('weatherTimelineMarineNotice').style.display = 'none';
    document.getElementById('weatherTimelineAttribution').style.display = 'none';
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

    // Independent of the weather fetch above - a slow/failed place-name
    // lookup must never hold up or break the actual forecast. Silent on
    // failure (no user-facing error), same philosophy as this file's other
    // best-effort lookups (e.g. a failed Windy link would just be a dead
    // link, not a shown error).
    Ytan.get('/geocode/reverse?lat=' + lat + '&lng=' + lng)
        .then((answer) => {
            if (requestId !== weatherTimelineRequestId) {
                return;
            }
            const placeName = answer.data.place_name;
            setWeatherTimelineTitle(lat, lng, placeName);
            document.getElementById('weatherTimelineAttribution').style.display = placeName ? 'block' : 'none';
        })
        .catch((err) => {
            log('openWeatherTimelineForLocation() reverse geocoding failed', LOG_WARN, err);
        });
}

function setWeatherTimelineTitle(lat, lng, placeName) {
    const titleKey = placeName ? 'weather.timeline.title_with_place' : 'weather.timeline.title';
    document.getElementById('weatherTimelineTitle').textContent = t(titleKey, {
        place: placeName,
        lat: lat.toFixed(4),
        lng: lng.toFixed(4),
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

// Open-Meteo's weather_code follows WMO code table 4677. Mapped down to the
// handful of pictograms this compact view actually draws (inline SVG, see
// the <symbol> sprite in app.php - not the vendored icon font, which per
// CLAUDE.md's own caveat can't be assumed to have sun/rain/snow glyphs).
// isNight only swaps the two "no cloud at all" codes to a moon - every other
// code already reads fine day or night without a dedicated night variant.
function weatherConditionIcon(code, isNight) {
    if (code === 0 || code === 1) {
        return isNight ? 'ic-moon' : 'ic-sun';
    }
    if (code === 2) {
        return 'ic-partly';
    }
    if (code === 3) {
        return 'ic-cloud';
    }
    if (code === 45 || code === 48) {
        return 'ic-fog';
    }
    if ([51, 53, 55, 56, 57].includes(code)) {
        return 'ic-drizzle';
    }
    if ([61, 63, 65, 66, 67, 80, 81, 82].includes(code)) {
        return 'ic-rain';
    }
    if ([71, 73, 75, 77, 85, 86].includes(code)) {
        return 'ic-snow';
    }
    if ([95, 96, 99].includes(code)) {
        return 'ic-storm';
    }
    return 'ic-cloud';
}

function weatherDayLabel(date) {
    const dow = new Intl.DateTimeFormat(window.YTAN_LOCALE, { weekday: 'short' }).format(date);
    return { dow: dow, dom: date.getDate() };
}

/**
 * Splits the flat hourly[] list into per-calendar-day groups, in order -
 * feeds the day-glance row, the inline day-divider tiles, and (indirectly,
 * since it's just data.hourly split up) the week chart below them, so a
 * day's boundary always lands in the same place across all three.
 */
function groupWeatherHourlyByDay(hourly) {
    const days = [];
    let current = null;
    hourly.forEach((hour) => {
        const dateKey = hour.time.slice(0, 10);
        if (!current || current.dateKey !== dateKey) {
            current = { dateKey: dateKey, date: new Date(hour.time.slice(0, 10) + 'T00:00'), hours: [] };
            days.push(current);
        }
        current.hours.push(hour);
    });
    return days;
}

function renderWeatherTimeline(data, preserveScroll) {
    document.getElementById('weatherTimelineMarineNotice').style.display = data.has_marine_data ? 'none' : 'block';

    const dayGroups = groupWeatherHourlyByDay(data.hourly);
    renderWeatherDayGlance(dayGroups);
    renderWeatherWeekChart(data.hourly, dayGroups.length);

    const strip = document.getElementById('weatherTimelineStrip');
    const rowLabels = document.getElementById('weatherTimelineRowLabels');
    const tableScroll = document.getElementById('weatherTimelineTableScroll');
    const previousScrollLeft = tableScroll.scrollLeft;
    strip.innerHTML = '';
    rowLabels.innerHTML = weatherRowLabelsHTML(data.has_marine_data);

    const now = new Date();
    let previousDateKey = null;
    let cumulativeLeft = 0;
    let prevHourLeft = 0, prevHourTime = null;
    let nowLineLeft = null;

    data.hourly.forEach((hour) => {
        const hourDate = new Date(hour.time);
        const dateKey = hour.time.slice(0, 10);

        if (dateKey !== previousDateKey) {
            const tile = buildWeatherDayDividerTile(hourDate);
            strip.appendChild(tile);
            cumulativeLeft += WEATHER_DAY_DIVIDER_WIDTH + 2 * WEATHER_DAY_DIVIDER_MARGIN;
            previousDateKey = dateKey;
        }

        // The dashed "now" line sits between the last hour at/before now and
        // the first hour after it, positioned by the actual elapsed fraction
        // of that hour - not just snapped to whichever column is closest.
        if (nowLineLeft === null && hourDate > now) {
            if (prevHourTime) {
                const fraction = (now - prevHourTime) / (hourDate - prevHourTime);
                nowLineLeft = prevHourLeft + fraction * WEATHER_HOUR_COL_WIDTH;
            } else {
                nowLineLeft = 0; // "now" is before the very first hour in the data
            }
        }

        strip.appendChild(buildWeatherHourColumn(hour, hourDate, data.has_marine_data));

        prevHourLeft = cumulativeLeft;
        prevHourTime = hourDate;
        cumulativeLeft += WEATHER_HOUR_COL_WIDTH;
    });

    if (nowLineLeft === null) {
        // "now" is after every hour in the data (right at the end of the
        // 7-day window) - pin the line to the last column instead of
        // leaving it unset.
        nowLineLeft = prevHourLeft;
    }

    const nowLine = document.createElement('div');
    nowLine.className = 'weather-timeline-now-line';
    nowLine.style.left = nowLineLeft + 'px';
    strip.appendChild(nowLine);

    if (preserveScroll) {
        // A wind-unit-only redraw shouldn't jump the user back to "now" if
        // they'd already scrolled further into the strip.
        tableScroll.scrollLeft = previousScrollLeft;
    } else {
        tableScroll.scrollLeft = Math.max(0, nowLineLeft - 40);
    }
}

const WEATHER_HOUR_COL_WIDTH = 26; // keep in sync with .weather-timeline-hour-col / .weather-timeline-day-divider-tile CSS width
const WEATHER_DAY_DIVIDER_WIDTH = 26;
const WEATHER_DAY_DIVIDER_MARGIN = 2;

function weatherRowLabelsHTML(hasMarineData) {
    const windUnit = windUnitLabel(settings.windUnit);
    let html =
        '<div class="rl r-time"><svg><use href="#ic-clock"/></svg>' + escapeHTML(t('weather.row.time')) + '</div>' +
        '<div class="rl r-icon"></div>' +
        '<div class="rl r-temp"><svg><use href="#ic-thermo"/></svg>' + escapeHTML(t('weather.row.temperature')) + '</div>' +
        '<div class="rl r-rain"><svg><use href="#ic-drop"/></svg>' + escapeHTML(t('weather.row.precipitation')) + '</div>' +
        '<div class="rl r-wind"><svg><use href="#ic-flag"/></svg>' + escapeHTML(t('weather.row.wind')) + ' <span class="rl-unit">' + escapeHTML(windUnit) + '</span></div>' +
        '<div class="rl r-gust"><svg><use href="#ic-gust"/></svg>' + escapeHTML(t('weather.row.gusts')) + ' <span class="rl-unit">' + escapeHTML(windUnit) + '</span></div>';
    if (hasMarineData) {
        html += '<div class="rl r-wave"><svg><use href="#ic-wave"/></svg>' + escapeHTML(t('weather.row.wave')) + '</div>';
    }
    return html;
}

function buildWeatherDayDividerTile(date) {
    const label = weatherDayLabel(date);
    const tile = document.createElement('div');
    tile.className = 'weather-timeline-day-divider-tile';
    tile.innerHTML = '<span class="dow">' + escapeHTML(label.dow) + '</span><span class="dom">' + label.dom + '.</span>';
    return tile;
}

function buildWeatherHourColumn(hour, hourDate, hasMarineData) {
    const col = document.createElement('div');
    col.className = 'weather-timeline-hour-col';

    const isNight = hourDate.getHours() >= 21 || hourDate.getHours() < 6;
    const icon = weatherConditionIcon(hour.weather_code, isNight);

    col.innerHTML =
        '<div class="r-time">' + hour.time.slice(11, 13) + '</div>' +
        '<div class="r-icon" aria-hidden="true"><svg><use href="#' + icon + '"/></svg></div>' +
        '<div class="r-temp"><span class="t-main">' + Math.round(hour.temperature) + '&deg;</span><span class="t-feel">' + Math.round(hour.feels_like) + '&deg;</span></div>' +
        '<div class="r-rain' + (hour.precipitation > 0 ? ' has-rain' : '') + '">' + hour.precipitation.toFixed(1) + '</div>';

    const windRow = document.createElement('div');
    windRow.className = 'r-wind';
    windRow.style.backgroundColor = windSpeedColor(hour.wind_speed);
    // Open-Meteo's wind_direction is the standard meteorological "from"
    // bearing (0deg/360deg = wind blowing FROM the north) - our ic-arrow glyph
    // points straight up at 0deg rotation, so rotating by the raw value
    // would point the arrow AT the direction the wind comes from, not
    // where it's actually going. +180deg flips it to a flow/"blowing
    // toward" arrow instead, matching Windy's own arrows (verified live
    // against windy.com for this exact coordinate/time: their glyph's own
    // rest orientation points down, and they rotate by the raw value with
    // no offset - mathematically the same flow bearing this +180deg
    // produces from an up-pointing glyph).
    windRow.innerHTML = '<svg style="transform:rotate(' + (hour.wind_direction + 180) + 'deg)"><use href="#ic-arrow"/></svg>' + escapeHTML(formatWindSpeedValue(hour.wind_speed, settings.windUnit));
    col.appendChild(windRow);

    const gustRow = document.createElement('div');
    gustRow.className = 'r-gust';
    gustRow.style.backgroundColor = windSpeedColor(hour.wind_gusts);
    gustRow.textContent = formatWindSpeedValue(hour.wind_gusts, settings.windUnit);
    col.appendChild(gustRow);

    if (hasMarineData && hour.wave_height !== null) {
        const waveRow = document.createElement('div');
        waveRow.className = 'r-wave';
        waveRow.textContent = hour.wave_height.toFixed(2);
        col.appendChild(waveRow);
    }

    return col;
}

/**
 * The 7-tile day-glance row right under the panel header - one icon and
 * one daily high per calendar day covered by the forecast, so the whole
 * week is visible before scrolling into the hourly detail below. The icon
 * is the condition at the hour closest to local noon (a day's own hourly
 * icons can vary hour to hour; midday is the usual single-icon convention
 * other weather apps use for a whole-day summary).
 */
function renderWeatherDayGlance(dayGroups) {
    const container = document.getElementById('weatherTimelineDayGlance');
    container.innerHTML = '';
    dayGroups.forEach((day, index) => {
        const high = Math.round(Math.max.apply(null, day.hours.map((h) => h.temperature)));
        const noonHour = day.hours.reduce((best, h) => {
            const hod = new Date(h.time).getHours();
            const bestHod = new Date(best.time).getHours();
            return Math.abs(hod - 12) < Math.abs(bestHod - 12) ? h : best;
        });
        const icon = weatherConditionIcon(noonHour.weather_code, false);
        const label = weatherDayLabel(day.date);

        const cell = document.createElement('div');
        cell.className = 'weather-timeline-day-glance-cell' + (index === 0 ? ' is-today' : '');
        cell.innerHTML =
            '<span class="dow">' + escapeHTML(label.dow) + ' ' + label.dom + '.</span>' +
            '<svg aria-hidden="true"><use href="#' + icon + '"/></svg>' +
            '<span class="hi">' + high + '&deg;</span>';
        container.appendChild(cell);
    });
}

/**
 * Week-long temperature curve + wind-strength bar drawn behind the day
 * table, one continuous SVG so the week reads as a single shape rather
 * than 7 disconnected mini-charts (matches the reference the user shared).
 * Built directly from the same hourly[] the detail strip below uses - no
 * separate/approximated dataset, so the curve's peaks always agree with
 * the daily highs shown in the day-glance row above it.
 */
function renderWeatherWeekChart(hourly, dayCount) {
    const VB_W = dayCount * 100, VB_H = 60;
    const CURVE_TOP = 5, CURVE_BOTTOM = 38, BAR_Y = 44, BAR_H = 12;

    const temps = hourly.map((h) => h.temperature);
    const tMin = Math.min.apply(null, temps), tMax = Math.max.apply(null, temps);
    const tRange = Math.max(0.1, tMax - tMin); // guard against a flat week dividing by zero

    const points = hourly.map((h, i) => {
        const x = i / (hourly.length - 1) * VB_W;
        const y = CURVE_BOTTOM - (h.temperature - tMin) / tRange * (CURVE_BOTTOM - CURVE_TOP);
        return [x, y];
    });
    const lineD = points.map((p, i) => (i === 0 ? 'M' : 'L') + p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ');
    const fillD = lineD + ' L' + VB_W + ',' + CURVE_BOTTOM + ' L0,' + CURVE_BOTTOM + ' Z';

    const stops = hourly.map((h, i) => {
        const pct = (i / (hourly.length - 1) * 100).toFixed(1);
        return '<stop offset="' + pct + '%" stop-color="' + windSpeedColor(h.wind_speed) + '"/>';
    }).join('');

    let ticks = '';
    for (let day = 1; day < dayCount; day++) {
        const tx = day * 100;
        ticks += '<line class="weather-timeline-week-chart-tick" x1="' + tx + '" y1="2" x2="' + tx + '" y2="' + (BAR_Y + BAR_H) + '"/>';
    }

    const svg = document.getElementById('weatherTimelineWeekChart');
    svg.setAttribute('viewBox', '0 0 ' + VB_W + ' ' + VB_H);
    svg.innerHTML =
        '<defs><linearGradient id="weatherWeekWindGrad" x1="0" y1="0" x2="1" y2="0">' + stops + '</linearGradient></defs>' +
        '<rect class="weather-timeline-week-chart-today-band" x="0" y="0" width="100" height="' + VB_H + '"/>' +
        ticks +
        '<path class="weather-timeline-week-chart-temp-fill" d="' + fillD + '"/>' +
        '<path class="weather-timeline-week-chart-temp-line" d="' + lineD + '"/>' +
        '<rect x="0" y="' + BAR_Y + '" width="' + VB_W + '" height="' + BAR_H + '" rx="4" fill="url(#weatherWeekWindGrad)"/>';
}
