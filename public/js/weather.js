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
 * Also registers two further, unrelated items in the same menu - "Open on
 * Windy" and "Open rain radar on Windy" - plain deep links to windy.com
 * centered on the clicked point, no backend/API involved (Windy needs no
 * key for its own site, only for its point-forecast API, which this
 * doesn't use).
 *
 * Two earlier approaches to a rain-radar feature were tried and dropped
 * before this one:
 * 1. Rendering RainViewer's radar tiles directly as a map overlay
 *    (public/js/radar.js) - dropped because RainViewer's free tiles only
 *    carry real data up to zoom 7 (confirmed both live via curl+md5sum and
 *    in RainViewer's own API docs, "Maximum zoom level is 7"), far coarser
 *    than this app's normal close-in kayak-touring zoom.
 * 2. A deep link to RainViewer's own map viewer (rainviewer.com/map.html)
 *    - dropped because that page auto-collapses its own zoom/play controls
 *    behind a "Toggle map controls" icon whenever it's opened at a browser
 *    width below 500px (confirmed live in RainViewer's own inline script:
 *    `window.innerWidth<500`), i.e. on essentially every phone - an extra
 *    tap was needed before the radar was even usable.
 * A deep link to Windy's own radar view has neither problem: it opens with
 * the radar overlay and its play controls immediately visible/usable at
 * any width (confirmed live), and Windy has no equivalent close-zoom
 * ceiling for this app's normal usage.
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
    registerMapContextMenuItem('radar', 'radar.context_menu.open_windy_radar_item', openWindyRadarForLocation);
}

function openWindyForLocation(latLng) {
    const lat = latLng.lat().toFixed(4);
    const lng = latLng.lng().toFixed(4);
    window.open('https://www.windy.com/?' + lat + ',' + lng + ',' + WINDY_DEFAULT_ZOOM, '_blank', 'noopener');
}

/**
 * Deep link to Windy's own rain-radar view, centered on the clicked point
 * at the map's actual current zoom (unlike openWindyForLocation() above,
 * which always uses a fixed default zoom) - confirmed live (not guessed)
 * that windy.com/?radar,<lat>,<lon>,<zoom> opens directly on the radar
 * overlay, already centered/zoomed there, with its play controls usable
 * immediately (no extra tap needed, unlike the RainViewer deep link this
 * replaced - see the file-level comment above).
 */
function openWindyRadarForLocation(latLng) {
    const lat = latLng.lat().toFixed(4);
    const lng = latLng.lng().toFixed(4);
    const zoom = Math.round(map.getZoom());
    window.open('https://www.windy.com/?radar,' + lat + ',' + lng + ',' + zoom, '_blank', 'noopener');
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

/**
 * @param {Date} date A "location-local wall-clock time, encoded as if it
 *   were UTC" Date - see renderWeatherTimeline()'s comment on `now` for why
 *   every Date derived from data.hourly[].time/data.daily[].date follows
 *   this convention. timeZone: 'UTC' here is what makes Intl actually read
 *   that encoded value back out, instead of reinterpreting it through the
 *   device's own timezone (which would silently reintroduce the same bug
 *   this convention exists to avoid).
 */
function weatherDayLabel(date) {
    const dow = new Intl.DateTimeFormat(window.YTAN_LOCALE, { weekday: 'short', timeZone: 'UTC' }).format(date);
    return { dow: dow, dom: date.getUTCDate() };
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
            current = { dateKey: dateKey, date: new Date(hour.time.slice(0, 10) + 'T00:00Z'), hours: [] };
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

    const dailyByDate = {};
    (data.daily || []).forEach((day) => { dailyByDate[day.date] = day; });

    // Open-Meteo's timezone=auto gives every hour.time/daily.date string as
    // a naive LOCAL time at the forecast COORDINATE, not at the device's own
    // location - new Date("...T12:00") would otherwise be parsed using the
    // device's own timezone, which only coincidentally matches the forecast
    // location's (e.g. testing this exact spot from a device whose timezone
    // isn't Europe/Copenhagen, or simply planning a trip to a place in a
    // different zone than home - see todo.md for the live report that first
    // caught this). Convention used everywhere in this file instead:
    // every Date built from one of these strings gets a "Z" appended, so it
    // encodes the location's own wall-clock reading as if it were UTC - and
    // is then always read back via getUTC*(), never the device-timezone
    // get*() variants. `now` is put into that same frame by shifting the
    // true current instant (Date.now(), a real UTC epoch, unaffected by the
    // device's timezone setting) by the forecast location's own UTC offset
    // (WeatherService::fetchAndCombine()'s utc_offset_seconds) - the result
    // is directly comparable to every hourDate below despite not actually
    // being the location's wall-clock Date object per se, only encoded the
    // same way. `|| 0` covers a leftover pre-this-change cache entry still
    // on disk (30-minute TTL) that predates this field - falls back to the
    // old (device-timezone) behavior for just that one stale response
    // rather than throwing.
    const now = new Date(Date.now() + (data.utc_offset_seconds || 0) * 1000);
    let previousDateKey = null;
    let cumulativeLeft = 0;
    let prevHourLeft = 0, prevHourTime = null;
    let nowLineLeft = null;
    const tidePoints = []; // {x, value} - only collected when has_marine_data, drawn as one continuous curve after the loop

    data.hourly.forEach((hour) => {
        const hourDate = new Date(hour.time + 'Z');
        const dateKey = hour.time.slice(0, 10);

        if (dateKey !== previousDateKey) {
            const tile = buildWeatherDayDividerTile(hourDate, dailyByDate[dateKey]);
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

        if (data.has_marine_data && hour.tide_height !== null) {
            // Centered under its hour column, same as every per-hour value -
            // the tide curve is drawn as one path afterwards, not per-cell.
            tidePoints.push({ x: cumulativeLeft + WEATHER_HOUR_COL_WIDTH / 2, value: hour.tide_height, time: hour.time });
        }

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

    // A first "now"-line mismatch report (2026-09-17, see todo.md) turned
    // out NOT to be explained by the device-vs-forecast-location timezone
    // theory this function's utc_offset_seconds handling was originally
    // built to fix (confirmed live: the reporting device's own timezone was
    // already the same GMT+2 as the forecast location). The real cause is
    // still open - this LOG_DEBUG dump gives the exact numbers needed to
    // find it next time it's reproduced (open Chrome DevTools remote
    // debugging against the device, same as the background-geolocation
    // work - see todo.md - and set logLevel high enough to show LOG_DEBUG),
    // rather than reasoning from a screenshot again.
    log('renderWeatherTimeline() now-line inputs', LOG_DEBUG, {
        device_now_iso: new Date().toISOString(),
        device_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        device_utc_offset_minutes: -new Date().getTimezoneOffset(),
        location_utc_offset_seconds: data.utc_offset_seconds,
        computed_now_encoded: now.toISOString(),
        first_hour_time: data.hourly[0] ? data.hourly[0].time : null,
        now_line_left_px: nowLineLeft,
        hour_col_width_px: WEATHER_HOUR_COL_WIDTH,
    });

    if (tidePoints.length > 1) {
        strip.appendChild(buildWeatherTideCurve(tidePoints, cumulativeLeft));
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
        '<div class="rl r-temp"><svg><use href="#ic-thermo"/></svg>' + escapeHTML(t('weather.row.temperature')) + ' <span class="rl-unit">&deg;C</span></div>' +
        '<div class="rl r-rain"><svg><use href="#ic-drop"/></svg>' + escapeHTML(t('weather.row.precipitation')) + ' <span class="rl-unit">mm</span></div>' +
        '<div class="rl r-wind"><svg><use href="#ic-flag"/></svg>' + escapeHTML(t('weather.row.wind')) + ' <span class="rl-unit">' + escapeHTML(windUnit) + '</span></div>' +
        '<div class="rl r-gust"><svg><use href="#ic-gust"/></svg>' + escapeHTML(t('weather.row.gusts')) + ' <span class="rl-unit">' + escapeHTML(windUnit) + '</span></div>';
    if (hasMarineData) {
        html += '<div class="rl r-wave"><svg><use href="#ic-wave"/></svg>' + escapeHTML(t('weather.row.wave')) + ' <span class="rl-unit">m</span></div>';
        html += '<div class="rl r-tide"><svg><use href="#ic-tide"/></svg>' + escapeHTML(t('weather.row.tide')) + '</div>';
    }
    return html;
}

function weatherFormatTime(iso) {
    if (!iso) {
        return null;
    }
    // See renderWeatherTimeline()'s comment on the "Z"-encoding convention -
    // sunrise/sunset are naive location-local strings too.
    const d = new Date(iso + 'Z');
    return pad2(d.getUTCHours()) + ':' + pad2(d.getUTCMinutes());
}

/**
 * @param {Date} date
 * @param {{sunrise: ?string, sunset: ?string}} [daily] this date's entry
 *   from data.daily (WeatherService), if any - sunrise sits above the
 *   weekday, sunset below the day-of-month (explicit request: shown here
 *   instead of on the temperature curve, where they were hard to notice).
 */
function buildWeatherDayDividerTile(date, daily) {
    const label = weatherDayLabel(date);
    const sunrise = daily ? weatherFormatTime(daily.sunrise) : null;
    const sunset = daily ? weatherFormatTime(daily.sunset) : null;

    const tile = document.createElement('div');
    tile.className = 'weather-timeline-day-divider-tile';
    // Icon sits closest to the tile's own top/bottom edge on both ends
    // (sunrise: icon then time; sunset: time then icon) so the two sun
    // groups mirror each other around the date in the middle.
    tile.innerHTML =
        (sunrise ? '<div class="sun"><svg><use href="#ic-sunrise"/></svg>' + sunrise + '</div>' : '') +
        '<div class="date"><span class="dow">' + escapeHTML(label.dow) + '</span><span class="dom">' + label.dom + '.</span></div>' +
        (sunset ? '<div class="sun">' + sunset + '<svg><use href="#ic-sunset"/></svg></div>' : '');
    return tile;
}

function buildWeatherHourColumn(hour, hourDate, hasMarineData) {
    const col = document.createElement('div');
    col.className = 'weather-timeline-hour-col';

    const isNight = hourDate.getUTCHours() >= 21 || hourDate.getUTCHours() < 6;
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

    if (hasMarineData) {
        // Empty on purpose - just reserves the row's height so every column
        // stays the same total height and the tide curve (one continuous
        // path drawn separately, see buildWeatherTideCurve()) lines up
        // under the correct row.
        const tideRow = document.createElement('div');
        tideRow.className = 'r-tide';
        col.appendChild(tideRow);
    }

    return col;
}

// Row heights in DOM order, mirroring the CSS heights in style.css - used
// to compute where the tide curve's row starts (sum of every row above it).
const WEATHER_ROW_HEIGHTS = { time: 20, icon: 22, temp: 30, rain: 18, wind: 22, gust: 22, wave: 18, tide: 34 };

function pad2(n) {
    return String(n).padStart(2, '0');
}

/**
 * A local high/low is only ever exactly on an hourly sample by
 * coincidence - Open-Meteo gives hourly points, but the real tide peaks
 * and dips between them. Fits a parabola through the extremum sample and
 * its two immediate neighbors (standard 3-point vertex interpolation)
 * and returns that vertex's estimated true time/value/x-position, rather
 * than just reporting the hourly sample's own (always-:00) timestamp.
 * xAt/timeAt bracket the vertex between whichever two ADJACENT points it
 * falls between, using their real pixel/time gap - not an assumed
 * uniform hour width, since a day-divider tile widens that gap by more
 * than one hour's worth of pixels wherever a date boundary falls.
 */
function interpolateTideExtremum(points, i) {
    const y0 = points[i - 1].value, y1 = points[i].value, y2 = points[i + 1].value;
    const denom = y0 - 2 * y1 + y2;
    let t = denom === 0 ? 0 : 0.5 * (y0 - y2) / denom; // fraction of one hour, offset from point i
    t = Math.max(-0.999, Math.min(0.999, t));
    const value = denom === 0 ? y1 : y1 - (y2 - y0) * (y2 - y0) / (8 * denom);

    const neighbor = t >= 0 ? points[i + 1] : points[i - 1];
    const x = points[i].x + Math.abs(t) * (neighbor.x - points[i].x) * (t >= 0 ? 1 : -1);
    // points[i].time is the raw hour.time string (see the tidePoints.push()
    // call in renderWeatherTimeline()) - "Z"-encoded per that function's
    // comment, so the returned Date follows the same convention and must be
    // read back with getUTC*(), not getHours()/getMinutes().
    const timeMs = new Date(points[i].time + 'Z').getTime() + t * 3600000;

    return { x: x, value: value, time: new Date(timeMs) };
}

/**
 * The tide row isn't per-cell numbers like the others - it's one
 * continuous curve (an SVG line, optionally filled) spanning every hour
 * column, since a rise/fall shape only reads as a shape when drawn
 * continuously. Points are pixel positions already computed by the
 * caller's main render loop (same x-coordinates the hour columns/day
 * dividers actually ended up at), so the curve always lines up exactly
 * under the (empty) .r-tide placeholder each hour column reserves.
 *
 * Each local high (Flut) and low (Ebbe) gets a dot at its interpolated
 * true position (see interpolateTideExtremum()) plus that exact time as
 * a label - on purpose placed on the INSIDE of the curve (a high's label
 * sits just below its dot, a low's just above), not stacked in a
 * dedicated margin outside the curve's own range, so the row doesn't
 * need extra height reserved purely for labels.
 */
function buildWeatherTideCurve(points, totalWidth) {
    const rowTop = WEATHER_ROW_HEIGHTS.time + WEATHER_ROW_HEIGHTS.icon + WEATHER_ROW_HEIGHTS.temp +
        WEATHER_ROW_HEIGHTS.rain + WEATHER_ROW_HEIGHTS.wind + WEATHER_ROW_HEIGHTS.gust + WEATHER_ROW_HEIGHTS.wave;
    const rowHeight = WEATHER_ROW_HEIGHTS.tide;
    const padding = 3; // keeps the curve's own peaks/troughs off the row's top/bottom edge
    // Label baseline distance from its dot, toward the row's center - a
    // high's label sits a bit further from its dot than a low's (more
    // breathing room from the curve line, per explicit request).
    const labelOffsetHigh = 13;
    const labelOffsetLow = 9;

    const values = points.map((p) => p.value);
    const vMin = Math.min.apply(null, values), vMax = Math.max.apply(null, values);
    const vRange = Math.max(0.01, vMax - vMin); // guard against a perfectly flat curve dividing by zero

    const toY = (value) => rowTop + rowHeight - padding - (value - vMin) / vRange * (rowHeight - 2 * padding);
    const lineD = points.map((p, i) => (i === 0 ? 'M' : 'L') + p.x.toFixed(1) + ',' + toY(p.value).toFixed(1)).join(' ');
    const fillD = lineD + ' L' + points[points.length - 1].x.toFixed(1) + ',' + (rowTop + rowHeight) +
        ' L' + points[0].x.toFixed(1) + ',' + (rowTop + rowHeight) + ' Z';

    let markersHTML = '';
    for (let i = 1; i < points.length - 1; i++) {
        const prev = points[i - 1].value, cur = points[i].value, next = points[i + 1].value;
        const isHigh = cur > prev && cur > next;
        const isLow = cur < prev && cur < next;
        if (!isHigh && !isLow) {
            continue;
        }
        const extremum = interpolateTideExtremum(points, i);
        const x = extremum.x.toFixed(1);
        const y = toY(extremum.value);
        const timeLabel = pad2(extremum.time.getUTCHours()) + ':' + pad2(extremum.time.getUTCMinutes());
        const labelY = isHigh ? (y + labelOffsetHigh) : (y - labelOffsetLow);
        markersHTML +=
            '<circle class="weather-timeline-tide-dot' + (isHigh ? ' is-high' : ' is-low') + '" cx="' + x + '" cy="' + y.toFixed(1) + '" r="2.2"/>' +
            '<text class="weather-timeline-tide-label" x="' + x + '" y="' + labelY.toFixed(1) + '">' + timeLabel + '</text>';
    }

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'weather-timeline-tide-curve');
    svg.setAttribute('width', totalWidth);
    svg.setAttribute('height', rowTop + rowHeight);
    svg.setAttribute('viewBox', '0 0 ' + totalWidth + ' ' + (rowTop + rowHeight));
    svg.innerHTML =
        '<path class="weather-timeline-tide-curve-fill" d="' + fillD + '"/>' +
        '<path class="weather-timeline-tide-curve-line" d="' + lineD + '"/>' +
        markersHTML;
    return svg;
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
            const hod = new Date(h.time + 'Z').getUTCHours();
            const bestHod = new Date(best.time + 'Z').getUTCHours();
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

    // 0°C reference: clamped into the curve's vertical band so the gradient
    // stop below is always valid, even for an all-above/all-below-zero week
    // (the dashed line itself is only drawn when the week actually straddles it).
    const zeroY = Math.min(CURVE_BOTTOM, Math.max(CURVE_TOP, CURVE_BOTTOM - (0 - tMin) / tRange * (CURVE_BOTTOM - CURVE_TOP)));
    const showZeroLine = tMin < 0 && tMax > 0;
    const zeroFrac = ((zeroY - CURVE_TOP) / (CURVE_BOTTOM - CURVE_TOP) * 100).toFixed(1);

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
        '<defs>' +
            '<linearGradient id="weatherWeekWindGrad" x1="0" y1="0" x2="1" y2="0">' + stops + '</linearGradient>' +
            '<linearGradient id="weatherWeekTempGrad" x1="0" y1="0" x2="0" y2="1">' +
                '<stop offset="0%" class="weather-timeline-week-chart-temp-fill-warm"/>' +
                '<stop offset="' + zeroFrac + '%" class="weather-timeline-week-chart-temp-fill-warm"/>' +
                '<stop offset="' + zeroFrac + '%" class="weather-timeline-week-chart-temp-fill-cold"/>' +
                '<stop offset="100%" class="weather-timeline-week-chart-temp-fill-cold"/>' +
            '</linearGradient>' +
        '</defs>' +
        '<rect class="weather-timeline-week-chart-today-band" x="0" y="0" width="100" height="' + VB_H + '"/>' +
        ticks +
        '<path class="weather-timeline-week-chart-temp-fill" d="' + fillD + '" fill="url(#weatherWeekTempGrad)"/>' +
        '<path class="weather-timeline-week-chart-temp-line" d="' + lineD + '"/>' +
        (showZeroLine ? '<line class="weather-timeline-week-chart-zero-line" x1="0" y1="' + zeroY.toFixed(1) + '" x2="' + VB_W + '" y2="' + zeroY.toFixed(1) + '"/>' : '') +
        '<rect x="0" y="' + BAR_Y + '" width="' + VB_W + '" height="' + BAR_H + '" rx="4" fill="url(#weatherWeekWindGrad)"/>';
}
