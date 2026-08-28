 /**
 * Funktionen zur Darstellung von Sector Lights
 * 
 * Informationen: https://en.wikipedia.org/wiki/Sector_light
 *
 *  Verzeichnisse:
 *      - https://www.soefartsstyrelsen.dk/Media/638090273557711196/Dansk%20Fyrliste%202022.pdf
 * 
 * 
 * @author Christoph Steindorff
 * @version 0.0.2
 */


 const SECTORLIGHT_LABEL_FONTSIZE = 14;     // font size of label in px (number)
 const SECTORLIGHT_RADIUS_DIVIDER = 8;      // the sectors are show at a radius divided by this value
 const SECTORLIGHT_EDGE_COLOR = "#FFFFFF";  // color of the edge lines


 function drawPartialCirclePolygon(map, center, startAngle, endAngle, radius, color) {
    var points = [];
    var numberOfSegments = Math.abs(Math.floor((endAngle - startAngle) / 2)); // Ein Segment je 2 Grad

    for (var i = startAngle; i <= endAngle; i += (endAngle - startAngle) / numberOfSegments) {
        var point = google.maps.geometry.spherical.computeOffset(center, radius, i);
        points.push(point);
    }

    var circlePath = new google.maps.Polygon({
        paths: points,
        strokeColor: color,
        strokeOpacity: 1,
        strokeWeight: 2,
        fillColor: color,
        fillOpacity: 0.35,
        map: map
    });

    return circlePath;
}


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
        
        //strokeWeight: strokeWeight
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


/**
 * Ermittele die Mitte auf einem Kreissegment und liefere diese LatLng-Koordinate zurück.
 * 
 * @param {google.maps.LatLng} center Zentrum des Kreises
 * @param {number} startAngle in Grad
 * @param {number} endAngle in Grad
 * @param {number} radius in Metern
 * @returns {google.maps.LatLng} Koordinate des Mittelpunkts des Kreissegments
 */
function getMiddleOfPartialCircle(center, startAngle, endAngle, radius) {
    
    var angle = endAngle - startAngle;

    var lat = center.lat + (radius * Math.cos(angle * Math.PI / 180)) / 111111;
    var lng = center.lng + (radius * Math.sin(angle * Math.PI / 180)) / (111111 * Math.cos(center.lat * Math.PI / 180));
    
    //return new google.maps.LatLng(lat, lng);
    var pos =  {
        lat: lat,
        lng: lng
    };
    return pos;
}


function drawPartialCircleOLD(index, map, center, startAngle, endAngle, radius, color, labelText="", labelColor="#000000", labelOffset = 10) {

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
        var lat = center.lat + (radius * Math.cos(angle * Math.PI / 180)) / 111111;
        var lng = center.lng + (radius * Math.sin(angle * Math.PI / 180)) / (111111 * Math.cos(center.lat * Math.PI / 180));
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
    var leftEdge = createDashedLine([ center, circlePath[circlePath.length -1] ], '#444444', 1, 2, 10);
    var rightEdge = createDashedLine([ center, circlePath[0] ], '#444444', 1, 2, 10);

  
    polyline.setMap(map);
    leftEdge.setMap(map);
    rightEdge.setMap(map);

    sectorLightsElements[index].push(polyline); // wir merken uns alle Elemente der Sektorenlichten zusammen mit dem poi-Index
    sectorLightsElements[index].push(leftEdge); // wir merken uns alle Elemente der Sektorenlichten zusammen mit dem poi-Index
    sectorLightsElements[index].push(rightEdge); // wir merken uns alle Elemente der Sektorenlichten zusammen mit dem poi-Index


    // Label darstellen
    if (labelText != "") {
        console.log('Rotated Label: ' + labelText);

        var midAngle = (startAngle + endAngle) / 2;
        var labelPosLat = center.lat + (radius * Math.cos(midAngle * Math.PI / 180)) / 111111;
        var labelPosLng = center.lng + (radius * Math.sin(midAngle * Math.PI / 180)) / (111111 * Math.cos(center.lat * Math.PI / 180));
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
            //textShadow: "-2.4px -2.4px rgba(255, 255, 255, 0.4), -2.4px 2.4px rgba(255, 255, 255, 0.4), 2.4px 2.4px rgba(255, 255, 255, 0.4), 2.4px -2.4px rgba(255, 255, 255, 0.4), -2.4px 0 rgba(255, 255, 255, 0.4), 0 2.4px rgba(255, 255, 255, 0.4), 2.4px 0 rgba(255, 255, 255, 0.4), 0 -2.4px rgba(255, 255, 255, 0.4)",
            textShadow: "-1px -1px rgba(255, 255, 255, 1), -1px 1px rgba(255, 255, 255, 1), 1px 1px rgba(255, 255, 255, 1), 1px -1px rgba(255, 255, 255, 1)",
            pointerEvents: "none" 
        }, labelOffsetX, labelOffsetY);

        sectorLightsElements[index].push(label); // wir merken uns alle Elemente der Sektorenlichten zusammen mit dem poi-Index
    }
}

/**
 * Draw partial circle with edges and color label
 * 
 * @param {*} index 
 * @param {*} map 
 * @param {*} center 
 * @param {*} startAngle 
 * @param {*} endAngle 
 * @param {*} radius 
 * @param {*} color 
 * @param {*} labelText 
 * @param {*} labelColor 
 * @param {*} labelOffset 
 */
function drawPartialCircle(index, map, center, startAngle, endAngle, radius, color, labelText="", labelColor="#000000", labelOffset = 10) {

    const circleRadius = radius / SECTORLIGHT_RADIUS_DIVIDER; // Radius der Kreiselemente

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

    sectorLightsElements[index].push(polyline); // wir merken uns alle Elemente der Sektorenlichten zusammen mit dem poi-Index
    sectorLightsElements[index].push(leftEdge); // wir merken uns alle Elemente der Sektorenlichten zusammen mit dem poi-Index
    sectorLightsElements[index].push(rightEdge); // wir merken uns alle Elemente der Sektorenlichten zusammen mit dem poi-Index


    // Label darstellen
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
            //textShadow: "-2.4px -2.4px rgba(255, 255, 255, 0.4), -2.4px 2.4px rgba(255, 255, 255, 0.4), 2.4px 2.4px rgba(255, 255, 255, 0.4), 2.4px -2.4px rgba(255, 255, 255, 0.4), -2.4px 0 rgba(255, 255, 255, 0.4), 0 2.4px rgba(255, 255, 255, 0.4), 2.4px 0 rgba(255, 255, 255, 0.4), 0 -2.4px rgba(255, 255, 255, 0.4)",
            textShadow: "-1px -1px rgba(255, 255, 255, 1), -1px 1px rgba(255, 255, 255, 1), 1px 1px rgba(255, 255, 255, 1), 1px -1px rgba(255, 255, 255, 1)",
            pointerEvents: "none" 
        }, labelOffsetX, labelOffsetY);

        sectorLightsElements[index].push(label); // wir merken uns alle Elemente der Sektorenlichten zusammen mit dem poi-Index
    }
}



function drawSectorLight(index, map, center, characteristic, sector_characteristics) {

    var color;
    var startAngle;
    var endAngle;
    var radiusMiles;
    var radiusMeters;
    var parts;
    var regex;
    var match;

    var sectors = [];
    if (sector_characteristics != null) {
        sectors = sector_characteristics.split('\n');
    }

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
    sectorLightsElements[index] = [];
    sectorLightsElements[index].push(label); // wir merken uns alle Elemente der Sektorenlichten zusammen mit dem poi-Index

    // Sektoren
    if (sectors.length > 0) {
        for (const sector of sectors) {
            console.log(sector);
            parts = sector.split(' ');
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

                drawPartialCircle(index, map, center, startAngle, endAngle, radiusMeters, color, parts[0], "black");
                
            } else {
                console.log('Zu wenige Parameter. Der Sektor wird übersprungen.');
            }
        }
    }
}

function testSectorLight() {
    var pos = {
        lat: 54.500041,
        lng: 10.273366
    };

    var characteristic = "Iso WRG 6s";
    var sector_characteristics = 'Iso G 6s 8M, 30, 110\nIso W 6s 10M, 110, 195\nIso R 6s 8M, 195, 275\nISO W 6s 5M, 275, 30';
    //var sector_characteristics = ' G 4M, 315, 45\n W 4M, 45, 135\n R 4M, 135, 225\n W 4M, 225, 315';
    //var sector_characteristics = ' G 10M, 315, 45\n W 8M, 45, 135\n R 2M, 135, 225\n W 10M, 225, 315';

    var sector_characteristics = 'W 17M 148-220\nR 14M 220-246\nW 17M 246-295\nR 14M 295-358\nW 17M 358-025\nG 13M 025-056\nR 14M 071-088\nW 17M 088-091\nG 13M 091-148';

    map.panTo(pos);
    drawSectorLight(0, map, pos, characteristic, sector_characteristics);

}