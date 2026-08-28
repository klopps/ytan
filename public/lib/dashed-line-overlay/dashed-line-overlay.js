/**
 *  const testPath = [
        { lat: 37.772, lng: -122.214 },
        { lat: 21.291, lng: -157.821 },
        { lat: -18.142, lng: 178.431 },
        { lat: -27.467, lng: 153.027 },
    ];
    var testDashedLine = new DashedLineOverlay([
        { lat: 37.772, lng: -122.214 },
        { lat: 21.291, lng: -157.821 },
        { lat: -18.142, lng: 178.431 },
        { lat: -27.467, lng: 153.027 },
    ], '#ffffff', 4, 2, 10);
    
    testDashedLine.setMap(map);
 */

class DashedLineOverlay extends google.maps.OverlayView {

    line;
    path;
    strokeColor;
    strokeWeight; 
    dashLength;
    gapLength;
    position;
    
    constructor(path, strokeColor, strokeWeight, dashLength, gapLength) {
		
        super();

        this.path = path;
        this.position = path[0];
        this.strokeColor = strokeColor;
        this.strokeWeight = strokeWeight; 
        this.dashLength = dashLength;
        this.gapLength = gapLength;
    }

    onAdd() {
        const lineSymbol = {
            path: "M 0,-1 0,1",
            strokeOpacity: 1,
            strokeWeight: this.strokeWeight,
            strokeColor: this.strokeColor,
            scale: this.dashLength,
        };

        this.line = new google.maps.Polyline({
            path: this.path,
            geodesic: true,
            strokeOpacity: 0,
            strokeWeight: this.strokeWeight,
            icons: [
                {
                  icon: lineSymbol,
                  offset: "0",
                  repeat: this.gapLength + "px",
                },
              ]
        });

        this.line.setMap(map);
    }
	
    onRemove() {
		if (!this.line) {
			return;
		}

		this.line.setMap(null);
		this.line = null;
	};
}