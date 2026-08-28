/**
 * Animate an HTML element with a light(house) characteristic
 * 
 * Bases on https://github.com/maciekmm/light-characteristics
 * Further information on light characterictics: https://en.wikipedia.org/wiki/Light_characteristic
 * 
 * @author Christoph Steindorff
 * @version 0.0.3
  */
class LightCharacteristic {

    // const COLOR_WHITE = 'white';
    COLOR_WHITE = '#FFFFCC'; // light yellow
    COLOR_RED = 'red';
    COLOR_GREEN = '#00FF00';
    COLOR_BLUE = 'blue';

    O_ON = (color = this.COLOR_WHITE) => {return {
        color: color,
        opacity: 1
    }}

    O_DIM = (color = this.COLOR_WHITE) => {return {
        opacity: 0.4,
        color: this.COLOR_WHITE
    }}

    O_OFF = {
        opacity: 0.001,
        color: this.COLOR_WHITE
    }

    LONG_FLASH_DURATION = 2500;
    FLASH_DURATION = 400;  // orginal 200ms were to short
    QUICK_FLASH_DURATION = 60*1000.0 / 70.0;
    VERY_QUICK_FLASH_DURATION = 60*1000.0 / 90.0;
    DIT_DURATION = 200;

    /**
    * Animate background color of a HTML elemets according to light characteristics code
    * 
    * @param string code light characteristic abbreviation, e.g. "Fl(3) WRG 9s" or "Iso R 4s"
    * @param {*} elementId id of HTML element to animate
    */
   animateElement(code, elementId) {
   
        // "Gr" for Groups is not supported

        // expected format: [class]{(x{+y})} [color] {period} {height} {range}

        var arrCode;
        var lcClass;    // F, Fl, LFl, Q, VQ, UQ, Oc, Iso, Mo(x), Al
        var lcGroup;    // (x)
        var lcInterrupted = false;
        var lcColor;    // W, G, R, Y, Bu
        var lcColors = [];
        var lcPeriod;   // e.g. 10s
        var lcHeight;   // e.g. 10m
        var lcRange;    // e.g. 15M
        const morseAlphabet = {
            'a': '.-',    'b': '-...',  'c': '-.-.', 'd': '-..',
            'e': '.',     'f': '..-.',  'g': '--.',  'h': '....',
            'i': '..',    'j': '.---',  'k': '-.-',  'l': '.-..',
            'm': '- -',    'n': '-.',    'o': '---',  'p': '.--.',
            'q': '--.-',  'r': '.-.',   's': '...',  't': '-',
            'u': '..-',   'v': '...-',  'w': '.--',  'x': '-..-',
            'y': '-.--',  'z': '--..',  ' ': '/',
            '1': '.----', '2': '..---', '3': '...--', '4': '....-', 
            '5': '.....', '6': '-....', '7': '--...', '8': '---..', 
            '9': '----.', '0': '-----', 
        }
    

        //reduce multiple whitespaces to one blank
        code = code.replace(/\s+/g," ");

        // build array from code
        arrCode = code.split(" ");


        // we need at least the type and a color
        if (arrCode.length < 2) {
            throw "Light characteristic code to short";
        }


        // get class (1st part of code)

        // Interrupted?
        if (arrCode[0].toLowerCase() != "iso") {
            if (arrCode[0].charAt(0).toLowerCase() == 'i') {
                lcInterrupted = true;
                lcClass = arrCode[0].substring(1);
            } else {
                lcClass = arrCode[0];
            }
        } else {
            lcClass = arrCode[0];
        }

        // get group
        if (lcClass.substring(0,2).toLowerCase() == 'mo') { // special case Morse Code
            var arrClass = lcClass.match(/^(.*)\((.)\)/);    
            if (arrClass) {
                lcClass = arrClass[1];
                lcGroup = arrClass[2]; 
            } else {
                throw "wrong light characteristic code (Morse Code)";
            }
        } else {
            var arrClass = lcClass.match(/^(.*)\((\d+)\)/);
            if (arrClass) {
                lcClass = arrClass[1];
                lcGroup = arrClass[2];
            } else {
                lcGroup = null;
            }
        }

        // get color (2nd part of code)
        lcColor = arrCode[1];
        for(var x=0; x < lcColor.length; x++) {
            switch(lcColor.charAt(x).toUpperCase()) {
                case 'W':
                    lcColors.push(this.COLOR_WHITE);
                    break;
                case 'R':
                    lcColors.push(this.COLOR_RED);
                    break;
                case 'G':
                    lcColors.push(this.COLOR_GREEN);
                    break;                  
                case 'B':
                    lcColors.push(this.COLOR_BLUE);
                    break;
            }
        }

        // Get optional period, height and range
        if (arrCode.length > 2) {
            for (var x=2; x < arrCode.length; x++) {
                console.log('arrcode[' + x + ']: ' + arrCode[x]);

                // period, e.g. "10s" or "4.5s"
                //if (arrCode[x].match(/^\d+s$/) !== null) {
                if (arrCode[x].match(/^\d+(\.\d+)?s$/) !== null) {
                    lcPeriod = arrCode[x].slice(0, -1) * 1000; // milliseconds
                }

                // height, e.g. "12m" or "12.5m"
                if (arrCode[x].match(/^\d+(\.\d+)?m$/) !== null) {
                    lcHeight = arrCode[x].slice(0, -1); 
                }

                // range, e.g. "15M" or "15.2M"
                if (arrCode[x].match(/^\d+(\.\d+)?M$/) !== null) {
                    lcRange = arrCode[x].slice(0, -1); 
                }
            }
        }

        // DEBUG CODE
        console.log('-----------------------');
        console.log('code: ' + code);
        console.log('ElementID: ' + elementId);
        console.log('Class: ' + lcClass);
        console.log('Group: ' + lcGroup);
        console.log('Interrupted: ' + lcInterrupted);
        console.log('Color: ' + lcColor);
        console.log('Colors: ' + lcColors);
        console.log('Period: ' + lcPeriod);
        console.log('Height: ' + lcHeight);
        console.log('Range: ' + lcRange);
        
   
       
       // parameters determined - let's start
        switch (lcClass) {
            case "F":       // F - Fixed
                this.animate(this.fixed(Infinity, lcColors[0]), elementId);
                break;

            case "Iso":     // Iso
                this.animate(this.iso(lcPeriod, lcColors[0]), elementId);
                break;
   
            case "Oc":      // Oc - Occulting
                if (lcGroup == null) {
                    this.animate(this.occulting(lcPeriod, lcColors[0]), elementId);
                } else {
                    this.animate(this.groupOcculting(lcPeriod, lcColors[0], lcGroup), elementId);
                }
                break;
   
            case "Fl":      // Fl - Flashing
                if (lcGroup == null) {
                    this.animate(this.flashing(lcPeriod, lcColors[0]), elementId); // Fl
                } else {
                    this.animate(this.groupFlashing(lcPeriod, lcColors[0], lcGroup), elementId); // Fl(x)
                }
                break;

            case "LFl": 
                if (lcGroup == null) {
                    this.animate(this.longFlashing(lcPeriod, lcColors[0]), elementId); // LFl
                } else {
                    this.animate(this.groupLongFlashing(lcPeriod, lcColors[0], lcGroup), elementId); // LFl(x)
                }
                break;


            case "Q":      // Q - Quick
                if (lcGroup == null) {
                    if (lcInterrupted) {
                        this.animate(this.interruptedQuick(lcPeriod, lcColors[0]), elementId); // IQ
                    } else {
                        this.animate(this.quick(924, lcColors[0]), elementId); // Q
                    }
                } else {
                    if (lcInterrupted) {
                        this.animate(this.interruptedGroupQuick(lcPeriod, lcColors[0], lcGroup), elementId); // IQ(x)
                    } else {
                        this.animate(this.groupQuick(lcPeriod, lcColors[0], lcGroup), elementId); // Q(x)
                    }
                }
                break;

            case "VQ":      // VQ - Very Quick
                if (lcGroup == null) {
                    if (lcInterrupted) {
                        this.animate(this.interruptedVeryQuick(lcPeriod, lcColors[0]), elementId); // IVQ
                    } else {
                        this.animate(this.veryQuick(660, lcColors[0]), elementId); // VQ
                    }
                } else {
                    if (lcInterrupted) {
                        this.animate(this.interruptedGroupVeryQuick(lcPeriod, lcColors[0], lcGroup), elementId); // IVQ(x)
                    } else {
                        this.animate(this.groupVeryQuick(lcPeriod, lcColors[0], lcGroup), elementId); // VQ(x)
                    }
                }
                break;


            case "FFl":      // FFl - Fixed and Flashing
                if (lcGroup == null) {
                    this.animate(this.fixedAndFlashing(lcPeriod, lcColors[0]), elementId); // FFl
                } else {
                    this.animate(this.fixedAndFlashing(lcPeriod, lcColors[0], lcGroup), elementId); // FFl(x)
                }
                break;


            case "Al":      // Al - Alternating
                this.animate(this.alternating(lcPeriod, lcColors), elementId); // Al
                break;

            
            case "Mo":      // Mo(x) - Morse Code
                if (lcGroup == null) {
                    throw "wrong light characteristic code (Morse Code)";
                } else {
                    var morseCode = morseAlphabet[lcGroup.charAt(0).toLowerCase()];
                    console.log(morseCode);
                    this.animate(this.morse(lcPeriod, morseCode, lcColors[0]), elementId); 
                }

       }
       
   }

    convertToRelativeKeyFrames(keyframes, duration) {
        const relativeKeyFrames = []
        for(let keyframe of keyframes) {
            relativeKeyFrames.push({
                ...keyframe,
                offset: (keyframe.offset || 0) / duration
            })
        }
        return relativeKeyFrames
    }
    
    animate([keyframes, duration], elementId) {
        const lightElement = document.getElementById(elementId);
        lightElement.animate(this.convertToRelativeKeyFrames(keyframes, duration), {
            duration: duration,
            iterations: Infinity
        });
    }
    
    getAnimation([keyframes, duration]) {
        return this.convertToRelativeKeyFrames(keyframes, duration), {
            duration: duration,
            iterations: Infinity
        };
    }        
    
    




    // ---------------------------------------------------


    
    // Mo(x)
    morse(period, morseCode, color = this.COLOR_WHITE) {

        var lights = [];
        var overallTime = 0;
        var partTime;

        const light = this.O_ON(color);

        for(var x=0; x<morseCode.length; x++) {

            switch(morseCode.charAt(x)) {
                case ".":      // dit
                    partTime = this.DIT_DURATION;
                    break;
                    console.log('dit');
                case "-":       // dah
                    partTime = this.DIT_DURATION * 3;
                    console.log('dah');
                    break;
            }
            lights.push(this.getFixed(partTime, light)); // symbol (dit or dah)
            lights.push(this.getFixed(this.DIT_DURATION, this.O_OFF)); // pause between symbols
            overallTime += partTime;
        }

        if (period < (overallTime + (3 * this.DIT_DURATION))) {
            throw "period to short for morse code";
        }

        // final dark so we get period time
        lights.push(this.getFixed(period - overallTime, this.O_OFF));

        return this.combine(
            ...lights
        );
    }

    combine(...animations) {
        let finalDuration = 0
        let finalKeyframes = []
        for (let [keyframes, duration] of animations) {
            for (let keyframe of keyframes) {
                finalKeyframes.push(
                    {
                        ...keyframe,
                        offset: (keyframe.offset || 0) + finalDuration
                    }
                )
            }
            finalDuration += duration
        }
        return [finalKeyframes, finalDuration]
    }

    compositeGroup(period, groupOnIntensity, groupOffIntensity, onDuration, offDuration, ...groups) {
        const totalDims = groups.reduce((a, b) => a + b)
        const totalDimsDuration = (onDuration + offDuration) * totalDims
        if (totalDimsDuration > period) {
            throw "total duration of dims exceeds total period"
        }

        const longFlashDuration = (period - totalDimsDuration) / groups.length;

        const lights = []
        for (let inGroup of groups) {
            for (let i = 0; i < inGroup; i++) {
                lights.push(...[this.getFixed(onDuration, groupOnIntensity), this.getFixed(offDuration, groupOffIntensity)])
            }
            lights.push(...[this.getFixed(longFlashDuration, groupOffIntensity)])
        }


        return this.combine(
            ...lights,
        )
    }


    getFixed(duration = Infinity, light) {
        const keyframes = [
            {
                opacity: light.opacity,
                backgroundColor: light.color,
                easing: 'steps(1, end)',
                // easing: 'cubic-bezier(0.8, 0, 1,0)'
            },
        ]
        return [keyframes, duration]
    }


    //F
    fixed(duration = Infinity, color = this.COLOR_WHITE) {
        
        const light = this.O_ON(color);
        const keyframes = [
            {
                opacity: light.opacity,
                backgroundColor: light.color,
                easing: 'steps(1, end)',
                // easing: 'cubic-bezier(0.8, 0, 1,0)'
            },
        ]
        return this.getFixed(duration, light);
    }


    //Iso
    iso(period, color = this.COLOR_WHITE) {

        const light = this.O_ON(color);
        return this.combine(
            this.getFixed(0.5 * period, light),
            this.getFixed(0.5 * period, this.O_OFF)
        );
    }

    //Oc
    occulting(period, color = this.COLOR_WHITE) {
        if (period < 1500) {
            throw "period of occulting light cannot be shorter than 1.5s"
        }
        const light = this.O_ON(color);
        return this.combine(
            this.getFixed(0.7 * period, light),
            this.getFixed(0.3 * period, this.O_OFF)
        );
    }

    //Oc(x)
    groupOcculting(period, color = this.COLOR_WHITE, dims = 2) {
        return this.compositeGroupOcculting(period, color, dims)
    }


    //Fl
    flashing(period, color = this.COLOR_WHITE) {
        return this.compositeGroupFlashing(period, color, 1)
    }

    //Fl(x)
    groupFlashing(period, color = this.COLOR_WHITE, inGroup) {
        return this.compositeGroupFlashing(period, color, inGroup)
    }


    //LFl
    longFlashing(period, color = this.COLOR_WHITE) {
        if (period < 3000) {
            throw "LFl period cannot be shorter than 3s"
        }
        return this.compositeGroup(period, this.O_ON(color), this.O_OFF, this.LONG_FLASH_DURATION, 3000 - this.LONG_FLASH_DURATION, 1)
    }

    //LFl(x)
    groupLongFlashing(period, color = this.COLOR_WHITE, inGroup) {
        if (period < inGroup * 2 * this.LONG_FLASH_DURATION) {
            throw "LFl(x) period is to short"
        }
        return this.compositeLongGroupFlashing(period, color, inGroup)
    }


    //Q
    quick(period = 120, color = this.COLOR_WHITE) {
        return this.blinking(period, 50, color)
    }

    //Q(x)
    groupQuick(period, color = this.COLOR_WHITE, flashes = 1) {
        return this.groupBlinking(period, this.QUICK_FLASH_DURATION, flashes, color)
    }

    //IQ
    interruptedQuick(period, color = this.COLOR_WHITE) {
        return this.interruptedBlinking(period, this.QUICK_FLASH_DURATION, color)
    }

    //IQ(x)
    interruptedGroupQuick(period, color = this.COLOR_WHITE, flashes) {
        return this.interruptedBlinking(period, this.QUICK_FLASH_DURATION, color, flashes)
    }


    //VQ
    veryQuick(period = 180, color = this.COLOR_WHITE) {
        return this.blinking(period, 80, color)
    }

    //VQ(x)
    groupVeryQuick(period, color = this.COLOR_WHITE, flashes = 1) {
        return this.groupBlinking(period, this.VERY_QUICK_FLASH_DURATION, flashes, color)
    }

    //IVQ
    interruptedVeryQuick(period, color = this.COLOR_WHITE) {
        return this.interruptedBlinking(period, this.VERY_QUICK_FLASH_DURATION, color)
    }

    //IVQ(x)
    interruptedGroupVeryQuick(period, color = this.COLOR_WHITE, flashes) {
        return this.interruptedBlinking(period, this.VERY_QUICK_FLASH_DURATION, color, flashes)
    }


    //FFl(x)
    fixedAndFlashing(period, color = this.COLOR_WHITE, inGroup = 1) {
        const flashes = []

        for (let i = 0; i < inGroup; i++) {
            flashes.push(...[this.getFixed(FLASH_DURATION, this.O_ON(color)), this.getFixed(FLASH_DURATION, this.O_DIM(color))])
        }

        const durationOfFlashes = this.FLASH_DURATION * 2 * inGroup;
        if (durationOfFlashes > period) {
            throw "duration of flashes exceeds total period"
        }

        return this.combine(
            ...flashes,
            this.getFixed(period - durationOfFlashes, this.O_DIM(color))
        )
    }

    // Al
    alternating(period, colors) {
        const singleColorPeriod = period / colors.length
        const lights = []
        for(let color of colors) {
            lights.push(this.getFixed(singleColorPeriod, this.O_ON(color)))
        }

        return this.combine(
            ...lights
        )
    }


    // ------------------------------------------------------------------------------------------------------------------------------


    //Fl(x+y)
    compositeGroupFlashing(period, color = this.COLOR_WHITE, ...groups) {
        return this.compositeGroup(period, this.O_ON(color), this.O_OFF, this.FLASH_DURATION, this.FLASH_DURATION, groups)
    }

    //LFl(x+y)
    compositeLongGroupFlashing(period, color = this.COLOR_WHITE, ...groups) {
        return this.compositeGroup(period, this.O_ON(color), this.O_OFF, this.LONG_FLASH_DURATION, this.LONG_FLASH_DURATION, groups)
    }


    //Oc(x+y)
    compositeGroupOcculting(period, color = this.COLOR_WHITE, ...dimGroups) {
        return compositeGroup(period, O_OFF, O_ON(color), FLASH_DURATION, FLASH_DURATION, dimGroups)
    }

    //LFl(x+y)
    compositeGroupLongFlashing(period, ...groups) {
        if(period < 3000) {
            throw "LFl period cannot be shorter than 3s"
        }
        return this.compositeGroup(period, this.O_ON(this.COLOR_WHITE), this.O_OFF, 2500, 500, groups)
    }


    //Q(x)+LFI
    groupQuickByLongFlash(period, flashes, color = this.COLOR_WHITE) {
        return this.groupBlinkingByLongFlash(period, this.QUICK_FLASH_DURATION, flashes, color = this.COLOR_WHITE)
    }


    //VQ(x)+LFI
    groupVeryQuickByLongFlash(period, flashes) {
        return this.groupBlinkingByLongFlash(period, this.VERY_QUICK_FLASH_DURATION, flashes)
    }




    blinking(period, minimumFrequency, color = this.COLOR_WHITE) {
        if(period > 60*1000 / minimumFrequency) {
            throw "quick's frequency must be > "+minimumFrequency + " blinks/minute"
        }

        return this.combine(
            this.getFixed(0.5*period, this.O_OFF),
            this.getFixed(0.5*period, this.O_ON(color))
        )
    }

    groupBlinking(period, flashDuration, flashes = 1, color = this.COLOR_WHITE) {
        return this.compositeGroup(period, this.O_ON(color), this.O_OFF, flashDuration/2, flashDuration/2, flashes)
    }

    interruptedBlinking(period, flashDuration, color = this.COLOR_WHITE, flashes = 8) {
        const dimStageLength = period - flashDuration * flashes;
        if(dimStageLength < 3000) {
            throw "dim stage in interrupted light must of length > 3s, but is " + dimStageLength
        }
        return this.compositeGroup(period, this.O_ON(color), this.O_OFF, flashDuration/2, flashDuration/2, flashes)
    }

    groupBlinkingByLongFlash(period, flashDuration, flashes, color = this.COLOR_WHITE) {
        const groupBlinkingDuration = flashDuration*flashes;
        const longFlashDuration = (period - groupBlinkingDuration) / 2;

        return this.combine(
            this.groupQuick(groupBlinkingDuration, flashes),
            this.getFixed(longFlashDuration, this.O_ON(color)),
            this.getFixed(longFlashDuration, this.O_OFF)
        )
    }



}