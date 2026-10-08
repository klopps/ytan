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
