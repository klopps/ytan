/**
 * Class to show sector lights (of lighthouses) on Google Maps
 * 
 * Dependencies:
 *      - class RotatedLabel is used
 * 
 * Information on sector lights: https://en.wikipedia.org/wiki/Sector_light
 *
 *  Directories of light houses:
 *      - World Wide: https://msi.nga.mil/Publications/NGALOL
 *      - Denmark: https://www.soefartsstyrelsen.dk/Media/638090273557711196/Dansk%20Fyrliste%202022.pdf
 * 
 *  Usage: 
 *      var pos = {
 *          lat: 54.500041,
 *          lng: 10.573366
 *      };
 *
 *      var characteristic = "Iso WRG 6s";
 *      // sector characteristics as string with sectors separated by a newline "\n" or semikolon ";"
 *      var sector_characteristics = 'W 17M 148-220\nR 14M 220-246\nW 17M 246-295\nR 14M 295-358\nW 17M 358-025\nG 13M 025-056\nR 14M 071-088\nW 17M 088-091\nG 13M 091-148';
 *      // or as an array
 *      // var sector_characteristics = ['W 17M 148-220', 'R 14M 220-246', 'W 17M 246-295', 'R 14M 295-358', 'W 17M 358-025', 'G 13M 025-056', 'R 14M 071-088', 'W 17M 088-091', 'G 13M 091-148'];
 *
 *      var sectorLight = new SectorLightOverlay(pos, characteristic, sector_characteristics);
 *      sectorLight.setMap(map);
 *      map.panTo(pos);
 * 
 * CSS:
 *      Example for the styling of the label
 *
 *      .lighthouseLabel {
 *          font-size: 14px;
 *          font-weight: bold;
 *          color: black;
 *          transform: translateX(-50%) translateY(17px);
 *          stroke: black;
 *          text-shadow: -1px -1px rgba(255, 255, 255, 1), -1px 1px rgba(255, 255, 255, 1), 1px 1px rgba(255, 255, 255, 1), 1px -1px rgba(255, 255, 255, 1);
 *          pointer-events: none; 
 *      }
 *
 */
function createDashedLine(path, strokeColor, strokeWeight, dashLength, gapLength) {

    const lineSymbol = {
        path: "M 0,-1 0,1",
        strokeOpacity: 1,
        strokeWeight: strokeWeight,
        strokeColor: strokeColor,
        scale: dashLength,
    };

    const line = new google.maps.Polyline({
        path: path,
        geodesic: true,
        strokeOpacity: 0,
        icons: [
            {
              icon: lineSymbol,
              offset: "0",
              repeat: gapLength + "px",
            },
          ]
    });

    return line;
}

class SectorLightOverlay extends google.maps.OverlayView {

    SECTORLIGHT_LABEL_FONTSIZE = 14;     // font size of label in px (number)
    SECTORLIGHT_EDGE_COLOR = "#FFFFFF";  // color of the edge lines
    SECTORLIGHT_RADIUS_PIXEL = 100;  // radius in pixel of the sector with longest distance
    MINZOOMLEVEL = 10; // on zoom levels smaller than this, no sector light will be drawn


    center;  // center or position of the sector light
    characteristic; // light characteristic, e.g. "Iso WRG 4s"
    sector_characteristics; // 
    sectors = []; // array of sector parameters
    elements = [];  // array of all elements of the sector light(Polylines, Label, Marker, ...)
    maxRadius = 0; // largest radius of all sectors

    /**
     * Sector Light
     * 
     * @constructor
     * @param {object} center - center/position of the sector light
     * @param {number} center.lat - latitude
     * @param {mumber} center.lng - longitude
     * @param {string} characteristic - e.g. "Iso WRG 6s"
     * @param {string} sector_characteristics - e.g. "R 12M 5-50;W 16M 50-90.5;G 11M 90.5-200"
     */
    constructor(center, characteristic, sector_characteristics) {
		super();

        var color;
        var startAngle;
        var endAngle;
        var radiusMiles;
        var radiusMeters;
        var parts;
        var regex;
        var match;
        var angles;

        this.center = center;
        this.characteristic = characteristic;

        // sector_characteristics can be string or an array, we need an array
        if (Array.isArray(sector_characteristics)) {
            this.sector_characteristics = sector_characteristics;
        } else {
            if (typeof sector_characteristics === 'string' || sector_characteristics instanceof String) {
                sector_characteristics = sector_characteristics.replace(/;/g, '\n');
                sector_characteristics = sector_characteristics.replace(/[ \t]+/g, " ");
                this.sector_characteristics = sector_characteristics.split('\n');
            } else {
                this.sector_characteristics = [];
            }
        }

        // Sectors
        if (this.sector_characteristics.length > 0) {
            for (const sector of this.sector_characteristics) {
                console.log(sector);
                parts = sector.trim().split(' ');
                if (parts.length > 2) {
    
                    var colorCode  = parts[0].trim().toUpperCase();
                    var radiusCode = parts[1].trim();
                    var angleCode  = parts[2].trim();
    
                    // Farbe
                    if (colorCode == "W" ) {
                        color = 'yellow'; // weiß (wird als gelb dargestellt)
                    } else {
                        if (colorCode == "R") {
                            color = '#FF0000'; // rot
                        } else {
                            if (colorCode == "G") {
                                color = '#00FF00'; // grün
                            } else {
                                console.log('Keine gültige Farbe. Der Sektor wird übersprungen.');
                                continue;
                            }
                        }
                    }
                    
                    // Radius
                    regex = /(\d*\.?\d+)M/;
                    match = regex.exec(radiusCode);
                    if (match != null) {
    
                        // Radius
                        radiusMiles = parseFloat(match[1]);
                        if (isNaN(radiusMiles)) {
                            console.log('Kein gültiger Radius. Der Sektor wird übersprungen.');
                            continue;
                        }
                        radiusMeters = radiusMiles * 1852;
                    } else {
                        console.log('Keine Entfernungsangabe vorhanden. Der Sektor wird übersprungen.');
                        continue;
                    }
    
                    // Startwinkel
                    angles = angleCode.split("-");
                    if (angles.length == 2) {
                        startAngle = (parseFloat(angles[0]) + 180) % 360; // Winkel um 180° drehen
                        if (isNaN(startAngle)) {
                            console.log('Kein gültiger Startwinkel. Der Sektor wird übersprungen.');
                            continue;
                        }
                        endAngle = (parseFloat(angles[1]) + 180) % 360; // Winkel um 180° drehen
                        if (isNaN(endAngle)) {
                            console.log('Kein gültiger Endwinkel. Der Sektor wird übersprungen.');
                            continue;
                        }       
        
                    } else {
                        console.log('Winkelangaben fehlerhaft.');
                        continue;
                    }
    
                    this.sectors.push({
                        radiusMeters: radiusMeters,
                        startAngle: startAngle,
                        endAngle: endAngle,
                        color: color,
                        colorCode: colorCode
                    });

                    // get maxRadius
                    if (radiusMeters > this.maxRadius) {
                        this.maxRadius = radiusMeters;
                    }
                        
                } else {
                    console.log('Zu wenige Parameter. Der Sektor wird übersprungen.');
                }
            }
            console.log('maxRadius [m]: ' + this.maxRadius);
        }
    }


    onAdd() {
        /*
        if (this.elements.length == 0) {
            this.draw();
        }
        */
        console.log('sector light added');
    }    


    onRemove() {
        for (const element of this.elements) {
            element.setMap();
        }
        this.elements = [];
    }    


    draw() {

        var zoomLevel = map.getZoom();
        console.log('draw called (zoom level ' + zoomLevel + ')');
        if (zoomLevel == this.lastZoomLevel) {
            console.log('unchanged zoom level');
            return true;
        } else {
            console.log('zoom level changed');
            this.onRemove();
            this.lastZoomLevel = zoomLevel;
        }


        if (zoomLevel < this.MINZOOMLEVEL) {
            this.onRemove();
            return 1;
        }
        
    
        // Show Marker with characteristic
        var label = new markerWithLabel.MarkerWithLabel({
            icon: " ",
            position: this.center, 
            clickable: false,
            draggable: false,
            map: map,
            labelContent: this.characteristic, // can also be HTMLElement
            labelClass: "lighthouseLabel", // the CSS class for the label
            labelStyle: { opacity: 1.0 },
        });
        label.setMap(map);
        this.elements.push(label); // wir merken uns alle Elemente der Sektorenlichten
    
        console.log('number of sectors: ' + this.sectors.length);

        // Draw Sectors
        if (this.sectors.length > 0) {
            for (var sector of this.sectors) {
                console.log(sector.colorCode);
                this.drawPartialCircle(this.center, sector.startAngle, sector.endAngle, sector.radiusMeters, sector.color, sector.colorCode, "black");
            }
        }
    }


    /**
     * Draw partial circle with edges and color label
     * 
     * @param {object} center - center/position of the sector light
     * @param {number} center.lat - latitude
     * @param {mumber} center.lng - longitude
     * @param {number} startAngle - angle of left edge
     * @param {number} endAngle  - angle of right edge
     * @param {number} radius - radius of partial circle in meters
     * @param {string} color - HTML color of the sector line
     * @param {string} labelText - label of the sector
     * @param {string} labelColor - HTML color of the label
     * @param {number} labelOffset - distance of label from line
     */
    drawPartialCircle(center, startAngle, endAngle, radius, color, labelText="", labelColor="#000000", labelOffset = 10) {

        // Calculate radius of circle in meters so it will be displayed with SECTORLIGHT_RADIUS_PIXEL pixels on every zoom level       
        var metersPerPx = this.metersPerPx(center.lat, this.lastZoomLevel);
        var circleRadius = radius * this.SECTORLIGHT_RADIUS_PIXEL / (this.maxRadius / metersPerPx);
        if (circleRadius > radius) {
            circleRadius = radius;
        }

        if (endAngle < startAngle) {
            endAngle += 360;
        }

        // Calculate number of segments for the polyline
        var numSegments = Math.abs(Math.floor((endAngle - startAngle) / 2)); // Ein Segment je 2 Grad
        
        // Initialize array to hold polyline path
        var circlePath = [];
        
        // Calculate the angle increment for each segment
        var angleIncrement = (endAngle - startAngle) / numSegments;
        
        // Generate polyline path points
        for (var i = 0; i <= numSegments; i++) {
            var angle = startAngle + (angleIncrement * i);
            var lat = center.lat + (circleRadius * Math.cos(angle * Math.PI / 180)) / 111111;
            var lng = center.lng + (circleRadius * Math.sin(angle * Math.PI / 180)) / (111111 * Math.cos(center.lat * Math.PI / 180));
            circlePath.push(new google.maps.LatLng(lat, lng));
        }
        
        // Draw polyline on the map
        var polyline = new google.maps.Polyline({
            path: circlePath,
            geodesic: true,
            strokeColor: color,
            strokeOpacity: 1.0,
            strokeWeight: 2
        });

        // rechte und linke Begrenzungslinie erzeugen
        var leftEdgeEndLat = center.lat + (radius * Math.cos(startAngle * Math.PI / 180)) / 111111;
        var leftEdgeEndLng = center.lng + (radius * Math.sin(startAngle * Math.PI / 180)) / (111111 * Math.cos(center.lat * Math.PI / 180));
        var leftEdgeEnd = new google.maps.LatLng(leftEdgeEndLat, leftEdgeEndLng);

        var rightEdgeEndLat = center.lat + (radius * Math.cos(endAngle * Math.PI / 180)) / 111111;
        var rightEdgeEndLng = center.lng + (radius * Math.sin(endAngle * Math.PI / 180)) / (111111 * Math.cos(center.lat * Math.PI / 180));
        var reightEdgeEnd = new google.maps.LatLng(rightEdgeEndLat, rightEdgeEndLng);

        var leftEdge = createDashedLine( [ center,  leftEdgeEnd], this.SECTORLIGHT_EDGE_COLOR, 1, 2, 10);
        var rightEdge = createDashedLine( [center, reightEdgeEnd ], this.SECTORLIGHT_EDGE_COLOR, 1, 2, 10);


        polyline.setMap(map);
        leftEdge.setMap(map);
        rightEdge.setMap(map);

        this.elements.push(polyline); 
        this.elements.push(leftEdge); 
        this.elements.push(rightEdge); 


        // show label
        if (labelText != "") {
            console.log('Rotated Label: ' + labelText);

            var midAngle = (startAngle + endAngle) / 2;
            var labelPosLat = center.lat + (circleRadius * Math.cos(midAngle * Math.PI / 180)) / 111111;
            var labelPosLng = center.lng + (circleRadius * Math.sin(midAngle * Math.PI / 180)) / (111111 * Math.cos(center.lat * Math.PI / 180));
            var labelPos = new google.maps.LatLng(labelPosLat, labelPosLng);

            var labelAngle = (endAngle + startAngle) / 2;
            var labelOffsetX = Math.sin(labelAngle * (Math.PI / 180)) * labelOffset;
            var labelOffsetY = (Math.cos(labelAngle * (Math.PI / 180)) * labelOffset * -1) - ((this.SECTORLIGHT_LABEL_FONTSIZE * 1.1) / 2);

            console.log('labelPos: ' + labelPos.lat + ', ' + labelPos.lng);
            console.log('labelAngle: ' + labelAngle);
            
            var label = new  RotatedLabel(labelPos, labelText, labelAngle, map, {
                color: labelColor,
                fontSize: this.SECTORLIGHT_LABEL_FONTSIZE + "px",
                fontWeight: "bold",
                stroke: "black",
                textShadow: "-1px -1px rgba(255, 255, 255, 1), -1px 1px rgba(255, 255, 255, 1), 1px 1px rgba(255, 255, 255, 1), 1px -1px rgba(255, 255, 255, 1)",
                pointerEvents: "none" 
            }, labelOffsetX, labelOffsetY);

            this.elements.push(label); 
        }
    }

    /**
     * Meters per pixel on Google Maps depending on latitude and zoom level
     * @param {number} latitude - latitude of position
     * @param {number} zoomLevel - Google Maps zoomlevel (0..19)
     * @return {numbner} meters per pixel
     */
    metersPerPx(latitude, zoomLevel) {
        return 156543.03392 * Math.cos(latitude * Math.PI / 180) / Math.pow(2, zoomLevel);
    }

}