/**
 * Class to show sector lights (of lighthouses) on Google Maps
 * 
 * Information on sector lights: https://en.wikipedia.org/wiki/Sector_light
 *
 *  Directories of light houses:
 *      - https://www.soefartsstyrelsen.dk/Media/638090273557711196/Dansk%20Fyrliste%202022.pdf
 * 
 *  Usage: 
 *      var pos = {
 *          lat: 54.500041,
 *          lng: 10.573366
 *      };
 *
 *      var characteristic = "Iso WRG 6s";
 *      var sector_characteristics = 'W 17M 148-220\nR 14M 220-246\nW 17M 246-295\nR 14M 295-358\nW 17M 358-025\nG 13M 025-056\nR 14M 071-088\nW 17M 088-091\nG 13M 091-148';
 *
 *      var sectorLight = new SectorLightOverlay(pos, characteristic, sector_characteristics);
 *      sectorLight.setMap(map);
 *      map.panTo(pos);
 *
 */
class SectorLightOverlay extends google.maps.OverlayView {

    SECTORLIGHT_LABEL_FONTSIZE = 14;     // font size of label in px (number)
    SECTORLIGHT_RADIUS_DIVIDER = 8;      // the sectors are show at a radius divided by this value
    SECTORLIGHT_EDGE_COLOR = "#FFFFFF";  // color of the edge lines

    center;  // center or position of the sector light
    characteristic; // light characteristic, e.g. "Iso WRG 4s"
    sector_characteristics; // 
    elements = [];  // array of all elements of the sector light(Polylines, Label, Marker, ...)


    constructor(center, characteristic, sector_characteristics) {
		super();
        this.center = center;
        this.characteristic = characteristic;

        // sector_characteristics can be string or an array, we need an array
        if (Array.isArray(sector_characteristics)) {
            this.sector_characteristics = sector_characteristics;
        } else {
            if (typeof sector_characteristics === 'string' || sector_characteristics instanceof String) {
                this.sector_characteristics = sector_characteristics.split('\n');
            } else {
                this.sector_characteristics = [];
            }
        }
    }


    onAdd() {
        if (this.elements.length == 0) {
            this.drawSectorLight(this.center, this.characteristic, this.sector_characteristics);
        }
        console.log('sector light added');
    }    


    onRemove() {
        for (const element of this.elements) {
            element.setMap();
        }
        this.elements = [];
    }    


    /**
     * Draw partial circle with edges and color label
     * 
     * @param {*} center 
     * @param {*} startAngle 
     * @param {*} endAngle 
     * @param {*} radius 
     * @param {*} color 
     * @param {*} labelText 
     * @param {*} labelColor 
     * @param {*} labelOffset 
     */
     drawPartialCircle(center, startAngle, endAngle, radius, color, labelText="", labelColor="#000000", labelOffset = 10) {

        const circleRadius = radius / this.SECTORLIGHT_RADIUS_DIVIDER; // Radius der Kreiselemente

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

        var leftEdge = createDashedLine( [ center,  leftEdgeEnd], SECTORLIGHT_EDGE_COLOR, 1, 2, 10);
        var rightEdge = createDashedLine( [center, reightEdgeEnd ], SECTORLIGHT_EDGE_COLOR, 1, 2, 10);

    
        polyline.setMap(map);
        leftEdge.setMap(map);
        rightEdge.setMap(map);

        this.elements.push(polyline); // wir merken uns alle Elemente der Sektorenlichten
        this.elements.push(leftEdge); // wir merken uns alle Elemente der Sektorenlichten
        this.elements.push(rightEdge); // wir merken uns alle Elemente der Sektorenlichten


        // show label
        if (labelText != "") {
            console.log('Rotated Label: ' + labelText);

            var midAngle = (startAngle + endAngle) / 2;
            var labelPosLat = center.lat + (circleRadius * Math.cos(midAngle * Math.PI / 180)) / 111111;
            var labelPosLng = center.lng + (circleRadius * Math.sin(midAngle * Math.PI / 180)) / (111111 * Math.cos(center.lat * Math.PI / 180));
            var labelPos = new google.maps.LatLng(labelPosLat, labelPosLng);

            var labelAngle = (endAngle + startAngle) / 2;
            var labelOffsetX = Math.sin(labelAngle * (Math.PI / 180)) * labelOffset;
            var labelOffsetY = (Math.cos(labelAngle * (Math.PI / 180)) * labelOffset * -1) - ((SECTORLIGHT_LABEL_FONTSIZE * 1.1) / 2);

            console.log('labelPos: ' + labelPos.lat + ', ' + labelPos.lng);
            console.log('labelAngle: ' + labelAngle);
            
            var label = new  RotatedLabel(labelPos, labelText, labelAngle, map, {
                color: labelColor,
                fontSize: SECTORLIGHT_LABEL_FONTSIZE + "px",
                fontWeight: "bold",
                stroke: "black",
                textShadow: "-1px -1px rgba(255, 255, 255, 1), -1px 1px rgba(255, 255, 255, 1), 1px 1px rgba(255, 255, 255, 1), 1px -1px rgba(255, 255, 255, 1)",
                pointerEvents: "none" 
            }, labelOffsetX, labelOffsetY);

            this.elements.push(label); // wir merken uns alle Elemente der Sektorenlichten
        }
    }    

    
    drawSectorLight(center, characteristic, sector_characteristics) {

        console.log('drawSectorLight called: ' + characteristic);

        var color;
        var startAngle;
        var endAngle;
        var radiusMiles;
        var radiusMeters;
        var parts;
        var regex;
        var match;
        var angles;
    
        // Characteristic
        var label = new MarkerWithLabel({
            icon: " ",
            position: center, 
            clickable: false,
            draggable: false,
            map: map,
            labelContent: characteristic, // can also be HTMLElement
            labelClass: "lighthouseLabel", // the CSS class for the label
            labelStyle: { opacity: 1.0 },
        });
        label.setMap(map);
        this.elements.push(label); // wir merken uns alle Elemente der Sektorenlichten
    
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
    
                    console.log('Radius (m): ' + radiusMeters);
                    console.log('Startwinkel: ' + startAngle);
                    console.log('Endwinkel: ' + endAngle);
                    console.log('Farbe: ' + color);
    
                    this.drawPartialCircle(center, startAngle, endAngle, radiusMeters, color, colorCode, "black");
                    
                } else {
                    console.log('Zu wenige Parameter. Der Sektor wird übersprungen.');
                }
            }
        }
    }
}