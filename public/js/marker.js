/**
 * YtanMarker - google.maps.marker.AdvancedMarkerElement behind the small
 * part of the deprecated google.maps.Marker API this app actually uses
 * (todo.md "Warning zu google.maps.Marker"), so poi.js/map-core.js/
 * weather.js keep their setMap()/getMap()/getPosition()/setIcon() calls and
 * their google.maps.event.addListener(marker, 'click'|'contextmenu'|
 * 'dblclick', ...) listeners unchanged.
 *
 * Differences that matter to callers:
 * - An InfoWindow needs the real element as anchor:
 *   infoWindow.open({ map: map, anchor: marker.advanced }).
 * - Events are re-dispatched from DOM/gmp events with a minimal
 *   MapMouseEvent-like object { latLng, domEvent } - latLng is the
 *   marker's position (all handlers here only need that).
 * - Needs the map's mapId (map-core.js) and the 'marker' library.
 *
 * Options: position, map, title, zIndex, draggable, id (kept as .id),
 *   iconUrl (image content, anchored bottom-center like the old default),
 *   iconAnchor ({x, y} px from the icon's top-left, e.g. a lighthouse
 *   centered on its position), glyphSrc (image URL drawn on the default
 *   pin, e.g. weather.js's white cloud). Neither: the default red pin.
 */
class YtanMarker {
    constructor(options) {
        this.id = options.id;
        this.iconAnchor = options.iconAnchor || null;

        this.advanced = new google.maps.marker.AdvancedMarkerElement({
            position: options.position,
            title: options.title || '',
            zIndex: options.zIndex,
            gmpDraggable: !!options.draggable,
            gmpClickable: true,
        });

        if (options.iconUrl) {
            this.setIcon(options.iconUrl);
        } else if (options.glyphSrc) {
            this.advanced.content = new google.maps.marker.PinElement({ glyphSrc: options.glyphSrc });
        }

        this.advanced.addEventListener('gmp-click', (event) => {
            google.maps.event.trigger(this, 'click', this.mouseEvent(event.domEvent || event));
        });
        // Right-click and double-click have no gmp-* event - plain DOM
        // events on the element, kept from also reaching the map (whose
        // own 'contextmenu' would open the map context menu on top, and
        // whose 'dblclick' would zoom).
        this.advanced.addEventListener('contextmenu', (domEvent) => {
            domEvent.preventDefault();
            domEvent.stopPropagation();
            google.maps.event.trigger(this, 'contextmenu', this.mouseEvent(domEvent));
        });
        this.advanced.addEventListener('dblclick', (domEvent) => {
            domEvent.preventDefault();
            domEvent.stopPropagation();
            google.maps.event.trigger(this, 'dblclick', this.mouseEvent(domEvent));
        });

        if (options.map) {
            this.setMap(options.map);
        }
    }

    mouseEvent(domEvent) {
        return { latLng: this.getPosition(), domEvent: domEvent };
    }

    setMap(map) {
        this.advanced.map = map || null;
    }

    /** null when not on a map, like google.maps.Marker. */
    getMap() {
        return this.advanced.map || null;
    }

    /** Always a google.maps.LatLng (AdvancedMarkerElement may hold a literal). */
    getPosition() {
        const position = this.advanced.position;
        if (!position) {
            return null;
        }
        return position instanceof google.maps.LatLng
            ? position
            : new google.maps.LatLng(position.lat, position.lng);
    }

    get position() {
        return this.getPosition();
    }

    setPosition(latLng) {
        this.advanced.position = latLng;
    }

    getTitle() {
        return this.advanced.title;
    }

    /**
     * Image content. AdvancedMarkerElement always anchors its content
     * bottom-center; a different anchor point is applied as a CSS shift
     * (percentages of the image's own size, so no need to know it here).
     */
    setIcon(url) {
        this.iconUrl = url;
        const img = document.createElement('img');
        img.src = url;
        img.alt = '';
        img.draggable = false;
        // Block, not inline: an inline <img> leaves a few px of descender
        // space below it, which lifted every icon 3px off its position.
        img.style.display = 'block';
        if (this.iconAnchor) {
            img.style.transform = 'translate(calc(50% - ' + this.iconAnchor.x + 'px), calc(100% - ' + this.iconAnchor.y + 'px))';
        }
        this.advanced.content = img;
    }

    /** {url, anchor} like the old Marker's icon object - findLongPressTarget() reads the anchor. */
    getIcon() {
        return this.iconUrl ? { url: this.iconUrl, anchor: this.iconAnchor } : null;
    }

    get icon() {
        return this.getIcon();
    }
}

/**
 * LabelMarker - HTML label (optionally with a round dot) at a position, a
 * YtanMarker underneath (replaces the vendored MarkerWithLabel, which
 * subclassed the deprecated google.maps.Marker). The content is a
 * zero-size box sitting exactly on the position, so offsets are plain
 * pixel values from the position: the dot is centered on it, the label's
 * top-left is `labelAnchor` away from it (default 0/0, like
 * MarkerWithLabel).
 *
 * Options (besides YtanMarker's): labelContent (HTML), labelClass,
 *   labelAnchor ({x, y}), dotColor (round dot, omit for none),
 *   clickable (default false: no pointer events at all).
 * .labelElement is the label <div>.
 */
class LabelMarker extends YtanMarker {
    constructor(options) {
        super(options);

        const anchor = options.labelAnchor || { x: 0, y: 0 };
        const box = document.createElement('div');
        box.style.cssText = 'position:relative;width:0;height:0;';
        box.style.pointerEvents = options.clickable ? 'auto' : 'none';

        if (options.dotColor) {
            const dot = document.createElement('div');
            dot.style.cssText = 'position:absolute;left:-6px;top:-6px;width:12px;height:12px;border-radius:50%;box-sizing:border-box;border:2px solid #fff;pointer-events:none;';
            dot.style.backgroundColor = options.dotColor;
            box.appendChild(dot);
        }

        const label = document.createElement('div');
        label.className = options.labelClass || '';
        label.style.cssText = 'position:absolute;white-space:nowrap;';
        label.style.left = anchor.x + 'px';
        label.style.top = anchor.y + 'px';
        label.innerHTML = options.labelContent || '';
        box.appendChild(label);

        this.labelElement = label;
        this.advanced.content = box;
        this.advanced.gmpClickable = !!options.clickable;
    }
}

/**
 * A YtanMarker that is only built when it is first needed on the map
 * (performance review 2026-10-16): creating an AdvancedMarkerElement costs
 * ~130 µs (4x that on a mid-range phone), and setAllPois() used to build all
 * ~1,750 POI and ~360 wind-shelter markers at once - 0.5 s (3.4 s throttled)
 * of blocked page, although below POI_CLUSTER_ZOOM_THRESHOLD only cluster
 * bubbles are shown and most markers are off screen anyway.
 *
 * Same surface as YtanMarker for what the callers use: setMap()/getMap(),
 * getPosition()/position/setPosition(), getIcon()/icon/setIcon(),
 * getTitle(), .id and .advanced. setMap(null) on a marker that was never
 * built does nothing; setMap(map) and .advanced build it. `onCreate(real)`
 * runs once right after building - the place for event listeners and
 * attachLongPressContextMenu(), which need the real YtanMarker (`.real`).
 */
class LazyYtanMarker {
    constructor(options, onCreate) {
        this.options = options;
        this.onCreate = onCreate || null;
        this.real = null;
        this.id = options.id;
    }

    ensure() {
        if (this.real === null) {
            this.real = new YtanMarker(this.options);
            if (this.onCreate) {
                this.onCreate(this.real);
            }
        }
        return this.real;
    }

    setMap(map) {
        if (!map && this.real === null) {
            return;
        }
        this.ensure().setMap(map);
    }

    getMap() {
        return this.real ? this.real.getMap() : null;
    }

    getPosition() {
        if (this.real) {
            return this.real.getPosition();
        }
        const position = this.options.position;
        if (!position) {
            return null;
        }
        return position instanceof google.maps.LatLng ? position : new google.maps.LatLng(position.lat, position.lng);
    }

    get position() {
        return this.getPosition();
    }

    setPosition(latLng) {
        this.options.position = latLng;
        if (this.real) {
            this.real.setPosition(latLng);
        }
    }

    getTitle() {
        return this.real ? this.real.getTitle() : (this.options.title || '');
    }

    setIcon(url) {
        this.options.iconUrl = url;
        if (this.real) {
            this.real.setIcon(url);
        }
    }

    getIcon() {
        if (this.real) {
            return this.real.getIcon();
        }
        return this.options.iconUrl ? { url: this.options.iconUrl, anchor: this.options.iconAnchor || null } : null;
    }

    get icon() {
        return this.getIcon();
    }

    get advanced() {
        return this.ensure().advanced;
    }
}
